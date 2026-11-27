<?php

/**
 * Dharma Form — универсальные формы для Joomla 5.2+ и 6+.
 *
 * @package    com_dharmaform
 * @copyright  (C) 2026 Dharma. Все права защищены.
 * @license    GNU General Public License версии 2 или новее; см. LICENSE.txt
 */

declare(strict_types=1);

namespace Dharma\Component\DharmaForm\Site\Service;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\Input\Input;

\defined('_JEXEC') or die;

/**
 * Адрес посетителя для ограничения частоты и защиты. По умолчанию только `REMOTE_ADDR`: заголовки вроде `X-Forwarded-For`
 * посетитель может подделать сам. Заголовки учитываются только от настроенных доверенных прокси.
 */
final class ClientIp
{
    /** Допустимые источники: значение настройки => имя ключа в `$_SERVER`. */
    public const SOURCES = [
        'remote_addr'   => 'REMOTE_ADDR',
        'cloudflare'    => 'HTTP_CF_CONNECTING_IP',
        'real_ip'       => 'HTTP_X_REAL_IP',
        'forwarded_for' => 'HTTP_X_FORWARDED_FOR',
    ];

    /** Адрес для счётчиков: IPv6 берётся по сети /64 (у посетителя их миллиарды), иначе лимит обходится сменой адреса. */
    public static function bucket(string $ip): string
    {
        $packed = @inet_pton($ip);

        if ($packed !== false && \strlen($packed) === 16) {
            return inet_ntop(substr($packed, 0, 8) . str_repeat("\0", 8)) . '/64';
        }

        return $ip;
    }

    public static function get(Input $server): string
    {
        $remote = (string) $server->getString('REMOTE_ADDR', '');
        if (!filter_var($remote, FILTER_VALIDATE_IP)) {
            return '';
        }

        $params = ComponentHelper::getParams('com_dharmaform');
        $source = (string) $params->get('ip_source', 'remote_addr');
        $key    = self::SOURCES[$source] ?? 'REMOTE_ADDR';
        $trusted = (string) $params->get('trusted_proxies', '');

        if ($key === 'REMOTE_ADDR' || !SubmissionGuard::ipInList($remote, $trusted)) {
            return $remote;
        }

        $value = trim((string) $server->getString($key, ''));
        if ($source !== 'forwarded_for') {
            return filter_var($value, FILTER_VALIDATE_IP) ? $value : $remote;
        }

        // Доверенные прокси добавляют предыдущий узел справа. Всё левее первого недоверенного узла игнорируем.
        foreach (array_reverse(explode(',', $value)) as $hop) {
            $hop = trim($hop);
            if (!filter_var($hop, FILTER_VALIDATE_IP)) {
                return $remote;
            }
            if (!SubmissionGuard::ipInList($hop, $trusted)) {
                return $hop;
            }
        }

        return $remote;
    }
}
