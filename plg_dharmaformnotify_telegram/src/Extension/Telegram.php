<?php

/**
 * Dharma Form — универсальные формы для Joomla 5.2+ и 6+.
 *
 * @package    pkg_dharma_form
 * @copyright  (C) 2026 Dharma. Все права защищены.
 * @license    GNU General Public License версии 2 или новее; см. LICENSE.txt
 */

declare(strict_types=1);

namespace Dharma\Plugin\DharmaFormNotify\Telegram\Extension;

use Dharma\Component\DharmaForm\Administrator\Notification\SubmissionNotification;
use Dharma\Component\DharmaForm\Administrator\Notification\SubmissionNotificationService;
use Joomla\CMS\Http\HttpFactory;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Event\EventInterface;
use Joomla\Event\SubscriberInterface;
use Joomla\Registry\Registry;

\defined('_JEXEC') or die;

/**
 * Канал «Telegram» (D42; образец — `plg_dharmashopnotify_telegram`): официальный Bot API. Получатели — числовые номера
 * чатов и открытые каналы `@имя` из настроек компонента и формы. Можно отправлять только «пришла заявка №» без
 * контактов: тогда в чат не попадают персональные данные.
 */
final class Telegram extends CMSPlugin implements SubscriberInterface
{
    protected $allowLegacyListeners = false;

    private const TEXT_MAX = 3900;

    public static function getSubscribedEvents(): array
    {
        return ['onDharmaFormNotify' => 'sendNotification'];
    }

    public function sendNotification(EventInterface $event): void
    {
        $settings = $event->getArgument('settings');
        $message  = $event->getArgument('notification');

        if (!$settings instanceof Registry || !$message instanceof SubmissionNotification || !(int) $settings->get('notify_telegram_enabled', 0)) {
            return;
        }

        $channels = (array) ($message->form['params']['notify_channels'] ?? []);

        if ($channels && !\in_array('telegram', $channels, true)) {
            return;
        }

        $token      = trim((string) $settings->get('notify_telegram_token', ''));
        $chats      = $settings->get('notify_telegram_chats', '');
        $recipients = self::recipients((\is_array($chats) ? implode(' ', $chats) : (string) $chats) . ' ' . (string) $message->form['params']['notify_chats']);

        if ($token === '' || !$recipients) {
            Log::add('Telegram notification skipped: token or chats are not configured', Log::WARNING, 'com_dharmaform');

            return;
        }

        $service = SubmissionNotificationService::fromContainer();
        $retry   = (bool) $event->getArgument('retry', false);
        $text    = mb_substr(implode("\n", $message->lines((bool) (int) $settings->get('notify_telegram_details', 1))), 0, self::TEXT_MAX);

        foreach ($recipients as $chat) {
            $deliveryId = $service->claim($message, 'telegram', $chat, $retry);

            if ($deliveryId === null) {
                continue;
            }

            $sent  = false;
            $error = '';

            try {
                $this->sendMessage($token, $chat, $text);
                $sent = true;
            } catch (\Throwable $e) {
                $error = $e instanceof \RuntimeException && preg_match('~^telegram_[a-z0-9_]+$~', $e->getMessage()) ? $e->getMessage() : $e::class;
                Log::add('Telegram delivery ' . $deliveryId . ' failed: ' . $error, Log::ERROR, 'com_dharmaform');
            }

            $service->finish($deliveryId, $sent, $error);
        }
    }

    /** @return string[] числовые номера чатов и @каналы */
    private static function recipients(string $value): array
    {
        $out = [];

        foreach (preg_split('~[\s,;]+~u', trim($value), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $candidate) {
            if (preg_match('~^-?\d{1,20}$~', $candidate) || preg_match('~^@[A-Za-z][A-Za-z0-9_]{4,31}$~', $candidate)) {
                $out[$candidate] = $candidate;
            }

            if (\count($out) >= 50) {
                break;
            }
        }

        return array_values($out);
    }

    private function sendMessage(string $token, string $chatId, string $text): void
    {
        $body = json_encode(['chat_id' => $chatId, 'text' => $text, 'link_preview_options' => ['is_disabled' => true]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $response = HttpFactory::getHttp(['timeout' => 8])->post(
            'https://api.telegram.org/bot' . $token . '/sendMessage',
            $body,
            ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
            8
        );

        $result = json_decode((string) $response->body, true);

        if ((int) $response->code < 200 || (int) $response->code >= 300 || !\is_array($result) || empty($result['ok'])) {
            $api = \is_array($result) ? (int) ($result['error_code'] ?? 0) : 0;

            throw new \RuntimeException($api > 0 ? 'telegram_api_' . $api : 'telegram_invalid_response');
        }
    }
}
