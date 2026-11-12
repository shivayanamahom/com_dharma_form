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
 * посетитель может подделать сам. Если сайт стоит за прокси или CDN (Cloudflare), в настройках компонента выбирается
 * заголовок, который этот прокси ставит и перезаписывает; иначе все посетители выглядели бы одним адресом прокси.
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
        $source = (string) ComponentHelper::getParams('com_dharmaform')->get('ip_source', 'remote_addr');
        $key    = self::SOURCES[$source] ?? 'REMOTE_ADDR';

        if ($key === 'REMOTE_ADDR') {
            return filter_var($remote, FILTER_VALIDATE_IP) ? $remote : '';
        }

        $value = trim((string) $server->getString($key, ''));

        // В цепочке прокси клиент — первый адрес.
        $value = trim(explode(',', $value)[0]);

        return filter_var($value, FILTER_VALIDATE_IP) ? $value : (filter_var($remote, FILTER_VALIDATE_IP) ? $remote : '');
    }
}
