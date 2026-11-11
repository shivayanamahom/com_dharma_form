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
 * Чаты MAX для уведомлений. MAX не отдаёт список чатов бота (`GET /chats` отключён), а номер чата приходит только в
 * событиях (`bot_added`, `bot_started`, `message_created`), поэтому поле берёт чаты из последних событий через
 * `GET /updates` (работает, пока у бота нет подписки-вебхука). Номер чата или `user:номер` можно ввести вручную.
 * Токен и ответ сервера в форму и журнал не попадают.
 */
class MaxchatsField extends ListField
{
    protected $type = 'Maxchats';

    private const API = 'https://platform-api2.max.ru';

    /** @var array<string, array<string, string>> */
    private static array $cache = [];

    protected function getOptions(): array
    {
        $options = parent::getOptions();
        $params  = ComponentHelper::getParams('com_dharmaform');
        $token   = trim((string) $params->get('notify_max_token', ''));
        $chats   = $token !== '' ? self::discover($token, trim((string) $params->get('notify_max_ca', ''))) : [];

        foreach ((array) $this->value as $saved) {
            $saved = trim((string) $saved);

            if ($saved !== '' && !isset($chats[$saved]) && preg_match('~^(?:user:)?-?\d{1,20}$~i', $saved)) {
                $chats[$saved] = $saved;
            }
        }

        foreach ($chats as $id => $title) {
            $options[] = HTMLHelper::_('select.option', $id, $title);
        }

        return $options;
    }

    /** @return array<string, string> номер чата => название */
    private static function discover(string $token, string $ca): array
    {
        $key = hash('sha256', $token);

        if (isset(self::$cache[$key])) {
            return self::$cache[$key];
        }

        $chats   = [];
        $options = ['timeout' => 4];

        if ($ca !== '' && is_file($ca)) {
            $options['transport.curl'] = [CURLOPT_CAINFO => $ca];
        }

        try {
            $response = HttpFactory::getHttp($options)->get(self::API . '/updates?limit=100&timeout=0', ['Authorization' => $token, 'Accept' => 'application/json'], 4);
            $payload  = json_decode((string) $response->body, true);

            foreach (\is_array($payload) ? ($payload['updates'] ?? []) : [] as $update) {
                $id = $update['chat_id'] ?? ($update['message']['recipient']['chat_id'] ?? null);

                if ($id === null || (string) $id === '') {
                    continue;
                }

                $name = trim((string) ($update['user']['name'] ?? ($update['message']['sender']['name'] ?? '')));
                $chats[(string) $id] = $name !== '' ? $name . ' (' . $id . ')' : (string) $id;
            }
        } catch (\Throwable) {
            // Недоступный MAX не должен мешать сохранению настроек.
        }

        return self::$cache[$key] = $chats;
    }
}
