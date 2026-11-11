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

    /** Заявки, срок хранения которых вышел. @return int сколько удалено */
    public function purgeExpired(): int
    {
        $db    = $this->db;
        $today = Factory::getDate()->format('Y-m-d');
        $total = 0;

        do {
            $query = $db->createQuery()
                ->select($db->quoteName('id'))
                ->from($db->quoteName('#__dharmaform_submissions'))
                ->where($db->quoteName('purge_after') . ' IS NOT NULL')
                ->where($db->quoteName('purge_after') . ' < :today')
                ->bind(':today', $today)
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
