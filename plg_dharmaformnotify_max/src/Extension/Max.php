<?php

/**
 * Dharma Form — универсальные формы для Joomla 5.2+ и 6+.
 *
 * @package    pkg_dharma_form
 * @copyright  (C) 2026 Dharma. Все права защищены.
 * @license    GNU General Public License версии 2 или новее; см. LICENSE.txt
 */

declare(strict_types=1);

namespace Dharma\Plugin\DharmaFormNotify\Max\Extension;

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
 * Канал «MAX» (D42): Bot API мессенджера MAX по документации dev.max.ru. Запрос `POST /messages` на
 * `platform-api2.max.ru`, токен бота — в заголовке `Authorization` (параметр запроса не принимается). Получатели:
 * номер чата или канала (`chat_id`) либо `user:номер` для личного сообщения пользователю (`user_id`); бот должен быть
 * добавлен в чат с правом записи. Не больше двух сообщений в секунду в один чат: получатели обрабатываются по очереди.
 * Можно слать только «Заявка № …» без контактов.
 */
final class Max extends CMSPlugin implements SubscriberInterface
{
    protected $allowLegacyListeners = false;

    public const API = 'https://platform-api2.max.ru';

    private const TEXT_MAX = 4000;

    public static function getSubscribedEvents(): array
    {
        return ['onDharmaFormNotify' => 'sendNotification'];
    }

    public function sendNotification(EventInterface $event): void
    {
        $settings = $event->getArgument('settings');
        $message  = $event->getArgument('notification');

        if (!$settings instanceof Registry || !$message instanceof SubmissionNotification || !(int) $settings->get('notify_max_enabled', 0)) {
            return;
        }

        $channels = (array) ($message->form['params']['notify_channels'] ?? []);

        if ($channels && !\in_array('max', $channels, true)) {
            return;
        }

        $token      = trim((string) $settings->get('notify_max_token', ''));
        $chats      = $settings->get('notify_max_chats', '');
        $recipients = self::recipients((\is_array($chats) ? implode(' ', $chats) : (string) $chats) . ' ' . (string) ($message->form['params']['notify_max_chats'] ?? ''));

        if ($token === '' || !$recipients) {
            Log::add('MAX notification skipped: token or chats are not configured', Log::WARNING, 'com_dharmaform');

            return;
        }

        $service = SubmissionNotificationService::fromContainer();
        $retry   = (bool) $event->getArgument('retry', false);
        $text    = mb_substr(implode("\n", $message->lines((bool) (int) $settings->get('notify_max_details', 1))), 0, self::TEXT_MAX);
        $ca      = trim((string) $settings->get('notify_max_ca', ''));

        foreach ($recipients as $recipient) {
            $deliveryId = $service->claim($message, 'max', $recipient, $retry);

            if ($deliveryId === null) {
                continue;
            }

            $sent  = false;
            $error = '';

            try {
                $this->send($token, $recipient, $text, $ca);
                $sent = true;
            } catch (\Throwable $e) {
                $error = $e instanceof \RuntimeException && preg_match('~^max_[a-z0-9_]+$~', $e->getMessage()) ? $e->getMessage() : $e::class;
                Log::add('MAX delivery ' . $deliveryId . ' failed: ' . $error, Log::ERROR, 'com_dharmaform');
            }

            $service->finish($deliveryId, $sent, $error);

            // Не больше двух сообщений в секунду в один диалог, чат или канал.
            usleep(550000);
        }
    }

    /**
     * Получатели: «число» — чат или канал (`chat_id`), «user:число» — личное сообщение (`user_id`).
     *
     * @return string[]
     */
    public static function recipients(string $value): array
    {
        $out = [];

        foreach (preg_split('~[\s,;]+~u', trim($value), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $candidate) {
            if (preg_match('~^(?:user:)?-?\d{1,20}$~i', $candidate)) {
                $out[strtolower($candidate)] = strtolower($candidate);
            }

            if (\count($out) >= 50) {
                break;
            }
        }

        return array_values($out);
    }

    /**
     * Запрос отправки: адрес, заголовки и тело. Вынесен отдельно, чтобы его можно было проверить без сети.
     *
     * @return array{url: string, headers: array<string, string>, body: string}
     */
    public static function buildRequest(string $token, string $recipient, string $text): array
    {
        $isUser = str_starts_with($recipient, 'user:');
        $id     = $isUser ? substr($recipient, 5) : $recipient;

        return [
            'url'     => self::API . '/messages?' . ($isUser ? 'user_id' : 'chat_id') . '=' . rawurlencode($id) . '&disable_link_preview=true',
            'headers' => ['Authorization' => $token, 'Content-Type' => 'application/json', 'Accept' => 'application/json'],
            'body'    => json_encode(['text' => $text, 'notify' => true], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ];
    }

    private function send(string $token, string $recipient, string $text, string $ca): void
    {
        $request = self::buildRequest($token, $recipient, $text);
        $options = ['timeout' => 8];

        // Серверы MAX используют сертификат Минцифры: если он не входит в системные, укажите файл сертификатов в настройках.
        if ($ca !== '' && is_file($ca)) {
            $options['transport.curl'] = [CURLOPT_CAINFO => $ca];
        }

        $response = HttpFactory::getHttp($options)->post($request['url'], $request['body'], $request['headers'], 8);
        $result   = json_decode((string) $response->body, true);

        if ((int) $response->code < 200 || (int) $response->code >= 300 || !\is_array($result) || !isset($result['message'])) {
            throw new \RuntimeException((int) $response->code > 0 ? 'max_api_' . (int) $response->code : 'max_invalid_response');
        }
    }
}
