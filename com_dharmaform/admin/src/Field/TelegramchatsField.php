<?php

/**
 * Dharma Form — универсальные формы для Joomla 5.2+ и 6+.
 *
 * @package    com_dharmaform
 * @copyright  (C) 2026 Dharma. Все права защищены.
 * @license    GNU General Public License версии 2 или новее; см. LICENSE.txt
 */

declare(strict_types=1);

namespace Dharma\Component\DharmaForm\Administrator\Field;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Form\Field\ListField;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Http\HttpFactory;

\defined('_JEXEC') or die;

/**
 * Чаты и каналы Telegram для уведомлений. По сохранённому токену бота
 * спрашивает у Bot API, где бот уже получал сообщения или куда его добавили, и предлагает эти чаты списком. Чат или
 * `@канал`, которых в списке нет, можно ввести вручную. Токен и ответ Telegram в форму и журнал не попадают.
 */
class TelegramchatsField extends ListField
{
    protected $type = 'Telegramchats';

    /** @var array<string, array<string, string>> */
    private static array $cache = [];

    protected function getOptions(): array
    {
        $options = parent::getOptions();
        $token   = trim((string) ComponentHelper::getParams('com_dharmaform')->get('notify_telegram_token', ''));
        $chats   = preg_match('~^\d+:[A-Za-z0-9_-]{20,}$~', $token) ? self::discover($token) : [];

        // Уже выбранные чаты остаются в списке, даже если Telegram их больше не отдаёт.
        foreach ((array) $this->value as $saved) {
            $saved = trim((string) $saved);

            if ($saved === '' || isset($chats[$saved])) {
                continue;
            }

            if (preg_match('~^-?\d{1,20}$~', $saved) && preg_match('~^\d+:[A-Za-z0-9_-]{20,}$~', $token)) {
                $chats[$saved] = self::title($token, $saved) ?? $saved;
            } elseif (preg_match('~^@[A-Za-z][A-Za-z0-9_]{4,31}$~', $saved)) {
                $chats[$saved] = $saved;
            }
        }

        foreach ($chats as $id => $title) {
            $options[] = HTMLHelper::_('select.option', $id, $title);
        }

        return $options;
    }

    /** @return array<string, string> номер чата => название */
    private static function discover(string $token): array
    {
        $key = hash('sha256', $token);

        if (isset(self::$cache[$key])) {
            return self::$cache[$key];
        }

        $chats = [];

        try {
            $response = HttpFactory::getHttp(['timeout' => 4])->get('https://api.telegram.org/bot' . $token . '/getUpdates?limit=100', ['Accept' => 'application/json'], 4);
            $payload  = json_decode((string) $response->body, true);

            if ((int) $response->code === 200 && \is_array($payload) && !empty($payload['ok'])) {
                foreach ($payload['result'] ?? [] as $update) {
                    $chat = $update['message']['chat'] ?? $update['edited_message']['chat'] ?? $update['channel_post']['chat'] ?? $update['edited_channel_post']['chat']
                        ?? $update['my_chat_member']['chat'] ?? null;

                    if (\is_array($chat) && isset($chat['id'])) {
                        $chats[(string) $chat['id']] = self::format($chat, (string) $chat['id']);
                    }
                }
            }
        } catch (\Throwable) {
            // Недоступный Telegram не должен мешать сохранению настроек.
        }

        return self::$cache[$key] = $chats;
    }

    private static function title(string $token, string $chatId): ?string
    {
        try {
            $response = HttpFactory::getHttp(['timeout' => 3])->get('https://api.telegram.org/bot' . $token . '/getChat?chat_id=' . rawurlencode($chatId), ['Accept' => 'application/json'], 3);
            $payload  = json_decode((string) $response->body, true);

            return (int) $response->code === 200 && !empty($payload['ok']) && \is_array($payload['result'] ?? null) ? self::format($payload['result'], $chatId) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** @param array<string, mixed> $chat */
    private static function format(array $chat, string $fallback): string
    {
        $title = trim((string) ($chat['title'] ?? ''));

        if ($title === '') {
            $title    = trim(implode(' ', array_filter([(string) ($chat['first_name'] ?? ''), (string) ($chat['last_name'] ?? '')])));
            $username = trim((string) ($chat['username'] ?? ''));

            if ($username !== '') {
                $title .= ($title !== '' ? ' ' : '') . '@' . $username;
            }
        }

        return $title !== '' ? $title . ' (' . $fallback . ')' : $fallback;
    }
}
