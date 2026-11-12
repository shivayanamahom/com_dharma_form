<?php

/**
 * Dharma Form — универсальные формы для Joomla 5.2+ и 6+.
 *
 * @package    pkg_dharma_form
 * @copyright  (C) 2026 Dharma. Все права защищены.
 * @license    GNU General Public License версии 2 или новее; см. LICENSE.txt
 */

declare(strict_types=1);

namespace Dharma\Plugin\DharmaFormNotify\Email\Extension;

use Dharma\Component\DharmaForm\Administrator\Notification\SubmissionNotification;
use Dharma\Component\DharmaForm\Administrator\Notification\SubmissionNotificationService;
use Joomla\CMS\Factory;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Mail\MailerFactoryInterface;
use Joomla\CMS\Mail\MailHelper;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Database\DatabaseInterface;
use Joomla\Event\EventInterface;
use Joomla\Event\SubscriberInterface;
use Joomla\Registry\Registry;

\defined('_JEXEC') or die;

/**
 * Канал «почта» (D42): письмо через почтовые настройки Joomla (собственных SMTP-паролей нет). Получатели — адреса из
 * настроек компонента, сотрудники с включённой отправкой системных писем и адреса, указанные у формы.
 */
final class Email extends CMSPlugin implements SubscriberInterface
{
    protected $allowLegacyListeners = false;

    public static function getSubscribedEvents(): array
    {
        return ['onDharmaFormNotify' => 'sendNotification'];
    }

    public function sendNotification(EventInterface $event): void
    {
        $settings = $event->getArgument('settings');
        $message  = $event->getArgument('notification');

        $test = (bool) $event->getArgument('test', false);
        $only = (string) $event->getArgument('only', '');

        if (!$settings instanceof Registry || !$message instanceof SubmissionNotification || ($only !== '' && $only !== 'email') || (!$test && !(int) $settings->get('notify_email_enabled', 0))) {
            return;
        }

        $channels = (array) ($message->form['params']['notify_channels'] ?? []);

        if (!$test && $channels && !\in_array('email', $channels, true)) {
            return;
        }

        $recipients = $this->recipients($settings, $message);

        if (!$recipients) {
            Log::add('Submission ' . $message->submissionId . ': no email recipients', Log::WARNING, 'com_dharmaform');

            if ($test) {
                SubmissionNotificationService::report($event, 'email', 'email_no_recipients');
            }

            return;
        }

        $service  = SubmissionNotificationService::fromContainer();
        $retry    = (bool) $event->getArgument('retry', false);
        $template = trim((string) $message->form['params']['notify_subject']) !== '' ? (string) $message->form['params']['notify_subject'] : (string) $settings->get('notify_email_subject', 'Новая заявка: {form} — {name}');
        $subject  = MailHelper::cleanLine($message->subject($template) ?: 'Новая заявка № ' . $message->submissionId);
        $text     = implode("\n", $message->lines(true));
        $attach   = (int) $settings->get('notify_email_attach', 1) ? $this->attachments($message, (int) $settings->get('notify_email_attach_mb', 10)) : [];
        $html     = $message->html((string) (Factory::getApplication()->get('sitename') ?: 'Сайт'));

        foreach ($recipients as $email) {
            $deliveryId = $test ? 0 : $service->claim($message, 'email', $email, $retry);

            if ($deliveryId === null) {
                continue;
            }

            $sent  = false;
            $error = '';

            try {
                $mailer = Factory::getContainer()->get(MailerFactoryInterface::class)->createMailer();
                $mailer->addRecipient($email);
                $mailer->setSubject($subject);
                $mailer->isHtml(true);
                $mailer->setBody($html);
                $mailer->AltBody = $text;

                foreach ($attach as $file) {
                    $mailer->addAttachment($file['path'], $file['name']);
                }

                $reply = trim((string) $message->submission['email']);

                if ((int) $settings->get('notify_email_replyto', 1) && $reply !== '' && MailHelper::isEmailAddress($reply)) {
                    $mailer->addReplyTo($reply, MailHelper::cleanLine((string) $message->submission['name']));
                }

                if ($mailer->send() !== true) {
                    throw new \RuntimeException('mail_not_accepted');
                }

                $sent = true;
            } catch (\Throwable $e) {
                $error = $e::class;
                Log::add('Email delivery ' . $deliveryId . ' failed: ' . $error, Log::ERROR, 'com_dharmaform');
            }

            if ($test) {
                SubmissionNotificationService::report($event, 'email:' . $email, $sent ? 'ok' : $error);
            } else {
                $service->finish($deliveryId, $sent, $error);
            }
        }
    }

    /**
     * Файлы заявки для вложения: только существующие, пока суммарный размер в пределах лимита (МБ).
     *
     * @return array<int, array{name: string, path: string}>
     */
    private function attachments(SubmissionNotification $message, int $limitMb): array
    {
        $left = max(0, $limitMb) * 1048576;
        $out  = [];

        foreach ($message->files as $file) {
            $size = is_file($file['path']) ? (int) filesize($file['path']) : 0;

            if ($size > 0 && $size <= $left) {
                $out[] = ['name' => $file['name'], 'path' => $file['path']];
                $left -= $size;
            }
        }

        return $out;
    }

    /** @return string[] */
    private function recipients(Registry $settings, SubmissionNotification $message): array
    {
        $candidates = preg_split('~[\s,;]+~u', (string) $settings->get('notify_email_to', ''), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $candidates = array_merge($candidates, preg_split('~[\s,;]+~u', (string) $message->form['params']['notify_emails'], -1, PREG_SPLIT_NO_EMPTY) ?: []);

        if ((int) $settings->get('notify_email_system', 0)) {
            $db         = Factory::getContainer()->get(DatabaseInterface::class);
            $candidates = array_merge($candidates, $db->setQuery('SELECT email FROM ' . $db->quoteName('#__users') . ' WHERE sendEmail = 1 AND block = 0')->loadColumn() ?: []);
        }

        $out = [];

        foreach ($candidates as $email) {
            $email = strtolower(trim((string) $email));

            if (\strlen($email) <= 254 && MailHelper::isEmailAddress($email)) {
                $out[$email] = $email;
            }
        }

        return array_values($out);
    }
}
