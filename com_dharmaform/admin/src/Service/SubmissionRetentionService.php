<?php

/**
 * Dharma Form — универсальные формы для Joomla 5.2+ и 6+.
 *
 * @package    com_dharmaform
 * @copyright  (C) 2026 Dharma. Все права защищены.
 * @license    GNU General Public License версии 2 или новее; см. LICENSE.txt
 */

declare(strict_types=1);

namespace Dharma\Component\DharmaForm\Administrator\Service;

use Dharma\Component\DharmaForm\Site\Service\FileUploader;
use Joomla\CMS\Cache\CacheControllerFactoryInterface;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;

\defined('_JEXEC') or die;

/** Удаление заявок вместе с файлами и журналом доставки; очистка по сроку хранения (`purge_after`). */
final class SubmissionRetentionService
{
    private const CHUNK = 200;

    public function __construct(private readonly DatabaseInterface $db)
    {
    }

    public static function fromContainer(): self
    {
        return new self(Factory::getContainer()->get(DatabaseInterface::class));
    }

    /** Раз в сутки (при поступлении заявок) убирает просроченное: работает без задания планировщика. */
    public static function purgeIfDue(): void
    {
        try {
            $cache = Factory::getContainer()->get(CacheControllerFactoryInterface::class)->createCacheController('output', ['defaultgroup' => 'com_dharmaform_purge', 'caching' => true, 'lifetime' => 1440]);

            if ($cache->contains('daily')) {
                return;
            }

            $cache->store('1', 'daily');
            self::fromContainer()->purgeExpired();
        } catch (\Throwable) {
            // Очистка не должна мешать приёму заявок: её сделает следующий запуск или планировщик.
        }
    }

    /**
     * Просроченные заявки: у которых вышел `purge_after`, без него — старше общего срока хранения из настроек, спам — старше
     * срока хранения спама. Срок 0 в настройках — не удалять.
     *
     * @return int сколько удалено
     */
    public function purgeExpired(): int
    {
        $db       = $this->db;
        $params   = ComponentHelper::getParams('com_dharmaform');
        $today    = Factory::getDate()->format('Y-m-d');
        $days     = max(0, (int) $params->get('retention_days', 365));
        $spamDays = max(0, (int) $params->get('retention_spam_days', 30));
        $total    = 0;

        $or = [$db->quoteName('purge_after') . ' < ' . $db->quote($today)];

        if ($days > 0) {
            $or[] = '(' . $db->quoteName('purge_after') . ' IS NULL AND ' . $db->quoteName('created') . ' < ' . $db->quote(Factory::getDate('-' . $days . ' days')->toSql()) . ')';
        }

        if ($spamDays > 0) {
            $or[] = '(' . $db->quoteName('is_spam') . ' = 1 AND ' . $db->quoteName('created') . ' < ' . $db->quote(Factory::getDate('-' . $spamDays . ' days')->toSql()) . ')';
        }

        do {
            $query = $db->createQuery()
                ->select($db->quoteName('id'))
                ->from($db->quoteName('#__dharmaform_submissions'))
                ->where('(' . implode(' OR ', $or) . ')')
                ->setLimit(self::CHUNK);
            $ids   = array_map('intval', $db->setQuery($query)->loadColumn() ?: []);
            $total += $this->delete($ids);
        } while (\count($ids) === self::CHUNK);

        return $total;
    }

    /**
     * Удалить заявки: файлы с диска, записи доставки, сами строки.
     *
     * @param int[] $ids
     *
     * @return int сколько заявок удалено
     */
    public function delete(array $ids): int
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));

        if (!$ids) {
            return 0;
        }

        $db   = $this->db;
        $rows = $db->setQuery($db->createQuery()->select($db->quoteName(['id', 'files']))->from($db->quoteName('#__dharmaform_submissions'))->whereIn($db->quoteName('id'), $ids))->loadObjectList() ?: [];

        foreach ($rows as $row) {
            $files = \is_string($row->files) && $row->files !== '' ? json_decode($row->files, true) : [];

            if (\is_array($files) && $files) {
                FileUploader::deleteFiles($files);
            }
        }

        $db->setQuery($db->createQuery()->delete($db->quoteName('#__dharmaform_deliveries'))->whereIn($db->quoteName('submission_id'), $ids))->execute();
        $db->setQuery($db->createQuery()->delete($db->quoteName('#__dharmaform_submissions'))->whereIn($db->quoteName('id'), $ids))->execute();

        return \count($rows);
    }
}
