<?php

/**
 * Dharma Form — универсальные формы для Joomla 5.2+ и 6+.
 *
 * @package    com_dharmaform
 * @copyright  (C) 2026 Dharma. Все права защищены.
 * @license    GNU General Public License версии 2 или новее; см. LICENSE.txt
 */

declare(strict_types=1);

namespace Dharma\Component\DharmaForm\Administrator\Notification;

use Dharma\Component\DharmaForm\Administrator\Forms\FormConfig;
use Dharma\Component\DharmaForm\Site\Service\FileUploader;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\Uri\Uri;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use Joomla\Event\Event;

\defined('_JEXEC') or die;

/**
 * Уведомления о заявках (D42; образец — `NotificationService` Dharma Shop). Заявка рассылается событием
 * `onDharmaFormNotify` плагинам группы `dharmaformnotify`; каждый плагин сам выбирает получателей из настроек,
 * «захватывает» получателя (`claim`: один получатель канала получает заявку один раз) и записывает итог (`finish`).
 * Сбой не теряет заявку: запись остаётся в журнале со статусом «ошибка» и повторяется заданием планировщика.
 */
final class SubmissionNotificationService
{
    public const MAX_ATTEMPTS = 5;

    /** Отправка, застрявшая в статусе «отправляется» дольше этого, считается оборванной и повторяется. */
    public const STALE_MINUTES = 15;

    public const STATE_SENDING = 0;

    public const STATE_SENT = 1;

    public const STATE_FAILED = 2;

    public function __construct(private readonly DatabaseInterface $db)
    {
    }

    public static function fromContainer(): self
    {
        return new self(Factory::getContainer()->get(DatabaseInterface::class));
    }

    /**
     * Разослать заявку по каналам. Заявки-спам рассылаются, только если это разрешено настройкой `notify_spam`.
     */
    public function notify(int $submissionId, bool $retry = false): void
    {
        try {
            $settings     = clone ComponentHelper::getParams('com_dharmaform');
            $notification = $this->build($submissionId);

            if ($notification === null) {
                return;
            }

            if (!empty($notification->submission['is_spam']) && !$settings->get('notify_spam', 0)) {
                return;
            }

            if (!$retry && !$settings->get('notify_new', 1)) {
                return;
            }

            PluginHelper::importPlugin('dharmaformnotify');
            Factory::getApplication()->getDispatcher()->dispatch('onDharmaFormNotify', new Event('onDharmaFormNotify', [
                'notification' => $notification,
                'settings'     => $settings,
                'retry'        => $retry,
            ]));
        } catch (\Throwable $error) {
            // Без контактов и текста заявки в журнале.
            Log::add('Submission notification ' . $submissionId . ': ' . $error::class, Log::ERROR, 'com_dharmaform');
        }
    }

    /** Заявки, у которых есть неотправленное или оборванное уведомление: повтор (задание планировщика). @return int сколько заявок обработано */
    public function retryDue(): int
    {
        $now   = Factory::getDate();
        $stale = Factory::getDate('-' . self::STALE_MINUTES . ' minutes')->toSql();
        $sql   = 'SELECT DISTINCT submission_id FROM ' . $this->db->quoteName('#__dharmaform_deliveries')
            . ' WHERE attempts < ' . self::MAX_ATTEMPTS
            . ' AND ((state = ' . self::STATE_FAILED . ' AND (next_try IS NULL OR next_try <= ' . $this->db->quote($now->toSql()) . '))'
            . ' OR (state = ' . self::STATE_SENDING . ' AND created < ' . $this->db->quote($stale) . '))';
        $ids   = array_map('intval', $this->db->setQuery($sql)->loadColumn() ?: []);

        foreach ($ids as $id) {
            $this->notify($id, true);
        }

        return \count($ids);
    }

    /**
     * Захватить получателя канала. Вне повтора запись создаётся один раз; при повторе перехватывается ошибочная или
     * оборванная запись. null — отправлять не нужно (уже отправлено или идёт).
     */
    public function claim(SubmissionNotification $message, string $channel, string $recipient, bool $retry): ?int
    {
        $key    = bin2hex(random_bytes(16));
        $hash   = hash('sha256', mb_strtolower($recipient));
        $now    = Factory::getDate()->toSql();
        $stale  = Factory::getDate('-' . self::STALE_MINUTES . ' minutes')->toSql();
        $sid   = $message->submissionId;
        $db     = $this->db;

        if ($retry) {
            $query = $db->createQuery()
                ->update($db->quoteName('#__dharmaform_deliveries'))
                ->set($db->quoteName('state') . ' = ' . self::STATE_SENDING)
                ->set($db->quoteName('claim_key') . ' = :key')
                ->set($db->quoteName('attempts') . ' = ' . $db->quoteName('attempts') . ' + 1')
                ->set($db->quoteName('error_code') . " = ''")
                ->where($db->quoteName('submission_id') . ' = :sid')
                ->where($db->quoteName('channel') . ' = :channel')
                ->where($db->quoteName('recipient_hash') . ' = :hash')
                ->where($db->quoteName('attempts') . ' < ' . self::MAX_ATTEMPTS)
                ->where('(' . $db->quoteName('state') . ' = ' . self::STATE_FAILED . ' OR (' . $db->quoteName('state') . ' = ' . self::STATE_SENDING . ' AND ' . $db->quoteName('created') . ' < :stale))')
                ->bind(':key', $key)
                ->bind(':sid', $sid, ParameterType::INTEGER)
                ->bind(':channel', $channel)
                ->bind(':hash', $hash)
                ->bind(':stale', $stale);
            $db->setQuery($query)->execute();
        } else {
            $query = 'INSERT IGNORE INTO ' . $db->quoteName('#__dharmaform_deliveries') . ' (submission_id, channel, recipient, recipient_hash, state, attempts, claim_key, created)'
                . ' VALUES (' . $sid . ', ' . $db->quote($channel) . ', ' . $db->quote(mb_substr($recipient, 0, 255)) . ', ' . $db->quote($hash) . ', ' . self::STATE_SENDING . ', 1, ' . $db->quote($key) . ', ' . $db->quote($now) . ')';
            $db->setQuery($query)->execute();
        }

        $id = $db->setQuery('SELECT id FROM ' . $db->quoteName('#__dharmaform_deliveries') . ' WHERE claim_key = ' . $db->quote($key) . ' AND submission_id = ' . $sid . ' AND state = ' . self::STATE_SENDING)->loadResult();

        return $id ? (int) $id : null;
    }

    /** Записать итог отправки; при ошибке назначается время следующей попытки (всё дольше с каждой). */
    public function finish(int $deliveryId, bool $sent, string $errorCode = ''): void
    {
        $db       = $this->db;
        $attempts = (int) $db->setQuery('SELECT attempts FROM ' . $db->quoteName('#__dharmaform_deliveries') . ' WHERE id = ' . $deliveryId)->loadResult();
        $next     = $sent ? null : Factory::getDate('+' . (10 * max(1, $attempts)) . ' minutes')->toSql();
        $query    = $db->createQuery()
            ->update($db->quoteName('#__dharmaform_deliveries'))
            ->set($db->quoteName('state') . ' = ' . ($sent ? self::STATE_SENT : self::STATE_FAILED))
            ->set($db->quoteName('sent') . ' = ' . ($sent ? $db->quote(Factory::getDate()->toSql()) : 'NULL'))
            ->set($db->quoteName('next_try') . ' = ' . ($next !== null ? $db->quote($next) : 'NULL'))
            ->set($db->quoteName('error_code') . ' = ' . $db->quote(substr($errorCode, 0, 100)))
            ->where($db->quoteName('id') . ' = ' . $deliveryId);
        $db->setQuery($query)->execute();
    }

    /** Журнал отправок заявки (для вкладки «Доставка»). @return object[] */
    public function deliveries(int $submissionId): array
    {
        return $this->db->setQuery('SELECT channel, recipient, state, attempts, error_code, sent, created FROM ' . $this->db->quoteName('#__dharmaform_deliveries') . ' WHERE submission_id = ' . $submissionId . ' ORDER BY id')->loadObjectList() ?: [];
    }

    private function build(int $submissionId): ?SubmissionNotification
    {
        $db  = $this->db;
        $row = $db->setQuery(
            'SELECT s.*, f.title AS form_title, f.code AS form_code, f.params AS form_params'
            . ' FROM ' . $db->quoteName('#__dharmaform_submissions', 's')
            . ' LEFT JOIN ' . $db->quoteName('#__dharmaform_forms', 'f') . ' ON f.id = s.form_id'
            . ' WHERE s.id = ' . $submissionId
        )->loadObject();

        if (!$row) {
            return null;
        }

        $decode     = static fn (mixed $json): array => \is_string($json) && $json !== '' ? (json_decode($json, true) ?: []) : [];
        $formParams = $decode($row->form_params);
        $fields     = $decode($row->fields);
        $utm        = $decode($row->utm);
        $files      = [];

        foreach ($decode($row->files) as $file) {
            $path = \is_array($file) && isset($file['name'], $file['path']) ? FileUploader::resolve((string) $file['path']) : null;

            if ($path !== null) {
                $files[] = ['name' => (string) $file['name'], 'path' => $path, 'size' => (int) ($file['size'] ?? 0)];
            }
        }

        return new SubmissionNotification(
            $submissionId,
            [
                'id' => $submissionId, 'name' => (string) $row->name, 'phone' => (string) $row->phone, 'email' => (string) $row->email,
                'message' => (string) $row->message, 'page_url' => (string) $row->page_url, 'created' => (string) $row->created,
                'is_spam' => (int) $row->is_spam,
            ],
            array_map('strval', array_filter($fields, 'is_scalar')),
            $files,
            ['title' => (string) ($row->form_title ?? ''), 'code' => (string) ($row->form_code ?? ''), 'params' => array_merge(FormConfig::DEFAULTS, $formParams)],
            array_map('strval', array_filter($utm, 'is_scalar')),
            rtrim(Uri::root(), '/') . '/administrator/index.php?option=com_dharmaform&task=submission.edit&id=' . $submissionId
        );
    }
}
