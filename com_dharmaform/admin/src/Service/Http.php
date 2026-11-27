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

use Joomla\CMS\Cache\CacheControllerFactoryInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Http\HttpFactory;

\defined('_JEXEC') or die;

/**
 * Запросы к внешним сервисам (Telegram, MAX) штатным HTTP-клиентом Joomla. На части серверов curl не может разрешить имена
 * (c-ares: «Resolving timed out»), хотя обычное разрешение имён работает: тогда запрос повторяется потоковым
 * транспортом Joomla, а выбор запоминается на час, чтобы следующие запросы не ждали таймаут curl.
 */
final class Http
{
    private const GROUP = 'com_dharmaform_http';

    /** @param array<string, string> $headers @param array<string, mixed> $options */
    public static function get(string $url, array $headers = [], int $timeout = 8, array $options = []): object
    {
        return self::send('get', $url, '', $headers, $timeout, $options);
    }

    /** @param array<string, string> $headers @param array<string, mixed> $options */
    public static function post(string $url, string $body, array $headers = [], int $timeout = 8, array $options = []): object
    {
        return self::send('post', $url, $body, $headers, $timeout, $options);
    }

    /** @param array<string, string> $headers @param array<string, mixed> $options */
    private static function send(string $method, string $url, string $body, array $headers, int $timeout, array $options): object
    {
        $call = static fn (object $http): object => $method === 'post' ? $http->post($url, $body, $headers, $timeout) : $http->get($url, $headers, $timeout);

        $options['timeout'] = $timeout;
        $host = (string) parse_url($url, PHP_URL_HOST);
        $cacheKey = 'stream-' . hash('sha256', strtolower($host));

        if (defined('CURLOPT_CONNECTTIMEOUT') && !self::streamPreferred($cacheKey)) {
            try {
                return $call(HttpFactory::getHttp($options + ['transport.curl' => [CURLOPT_CONNECTTIMEOUT => min(4, $timeout)]], ['curl']));
            } catch (\RuntimeException $e) {
                // Повторяем POST только при сбое DNS/подключения до начала отправки запроса.
                if (!preg_match('~resolv|failed to connect to|could not connect to~i', $e->getMessage())) {
                    throw $e;
                }

                self::rememberStream($cacheKey);
            }
        }

        return $call(HttpFactory::getHttp($options, ['stream']));
    }

    /** @return array<string, string> */
    public static function certificateOptions(string $ca): array
    {
        if ($ca === '') {
            return [];
        }
        if (!is_file($ca) || !is_readable($ca)) {
            throw new \RuntimeException('max_invalid_ca_bundle');
        }

        return ['curl.certpath' => $ca, 'stream.certpath' => $ca];
    }

    private static function cache(): object
    {
        return Factory::getContainer()->get(CacheControllerFactoryInterface::class)->createCacheController('output', ['defaultgroup' => self::GROUP, 'caching' => true, 'lifetime' => 60]);
    }

    private static function streamPreferred(string $key): bool
    {
        try {
            return self::cache()->contains($key);
        } catch (\Throwable) {
            return false;
        }
    }

    private static function rememberStream(string $key): void
    {
        try {
            self::cache()->store('1', $key);
        } catch (\Throwable) {
            // Без кэша каждый запрос сначала пробует curl.
        }
    }
}
