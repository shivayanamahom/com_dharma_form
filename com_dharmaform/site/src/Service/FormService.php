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

use Dharma\Component\DharmaForm\Administrator\Forms\FormConfig;
use Joomla\CMS\Cache\CacheControllerFactoryInterface;
use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;

\defined('_JEXEC') or die;

/**
 * Чтение опубликованных форм. Все формы читаются одним запросом и лежат в кэше Joomla (группа `com_dharmaform`),
 * который сбрасывается при любом изменении формы (`clearCache`): на странице без форм и открывателей запросов к базе нет.
 */
final class FormService
{
    private const CACHE_GROUP = 'com_dharmaform';

    private const CACHE_KEY = 'published_forms';

    /** @var array<string, array<string, mixed>>|null */
    private static ?array $memory = null;

    /** Сбросить кэш форм (после сохранения, удаления, смены состояния формы). */
    public static function clearCache(): void
    {
        self::$memory = null;

        try {
            Factory::getContainer()->get(CacheControllerFactoryInterface::class)->createCacheController('callback', ['defaultgroup' => self::CACHE_GROUP])->clean();
        } catch (\Throwable) {
            // Кэш недоступен: формы читаются из базы.
        }
    }

    /** @return array<string, array<string, mixed>> опубликованные формы по коду (`FormConfig::fromRow`) */
    private static function all(): array
    {
        if (self::$memory !== null) {
            return self::$memory;
        }

        $load = static function (): array {
            $db   = Factory::getContainer()->get(DatabaseInterface::class);
            $rows = $db->setQuery('SELECT * FROM ' . $db->quoteName('#__dharmaform_forms') . ' WHERE state = 1 ORDER BY ordering, title')->loadObjectList() ?: [];
            $out  = [];

            foreach ($rows as $row) {
                $out[(string) $row->code] = FormConfig::fromRow($row);
            }

            return $out;
        };

        try {
            $cache = Factory::getContainer()->get(CacheControllerFactoryInterface::class)->createCacheController('callback', ['defaultgroup' => self::CACHE_GROUP]);

            return self::$memory = $cache->get($load, [], self::CACHE_KEY, false);
        } catch (\Throwable) {
            return self::$memory = $load();
        }
    }

    /** @return array<string, mixed>|null форма (`FormConfig::fromRow`) или null */
    public function byCode(string $code): ?array
    {
        $code = (string) preg_replace('~[^A-Za-z0-9._-]+~', '', $code);

        return $code !== '' ? (self::all()[$code] ?? null) : null;
    }

    /** Классы-открыватели опубликованных форм: код формы => список классов. @return array<string, string[]> */
    public function triggers(): array
    {
        $out = [];

        foreach (self::all() as $code => $form) {
            $classes = preg_split('~\s+~', trim((string) $form['params']['trigger_class']), -1, PREG_SPLIT_NO_EMPTY) ?: [];

            if ($classes) {
                $out[$code] = array_values($classes);
            }
        }

        return $out;
    }

    /**
     * Формы с автопоказом для страницы с путём `$path` (без домена и строки запроса). Страницы вне условия «где показывать»
     * отбрасываются здесь, на сервере: на них скрипты форм не подключаются. Устройство и частота показа — дело скрипта.
     *
     * @return array<string, array<string, mixed>> код формы => настройки автопоказа
     */
    public function autoPopups(string $path): array
    {
        $out = [];

        foreach (self::all() as $code => $form) {
            $p = $form['params'];

            if (empty($p['auto_enabled']) || !(array) $p['auto_triggers']) {
                continue;
            }

            if (!self::pageAllowed($path, (string) $p['auto_pages'], (string) $p['auto_pages_mode'])) {
                continue;
            }

            $out[$code] = [
                'triggers'  => array_values(array_intersect(['delay', 'scroll', 'exit', 'idle'], (array) $p['auto_triggers'])),
                'delay'     => max(0, (int) $p['auto_delay']),
                'scroll'    => min(100, max(1, (int) $p['auto_scroll'])),
                'idle'      => max(5, (int) $p['auto_idle']),
                'frequency' => \in_array($p['auto_frequency'], ['always', 'session', 'days'], true) ? (string) $p['auto_frequency'] : 'session',
                'days'      => max(1, (int) $p['auto_days']),
                'devices'   => \in_array($p['auto_devices'], ['desktop', 'mobile'], true) ? (string) $p['auto_devices'] : 'all',
            ];
        }

        return $out;
    }

    /** Подходит ли путь под список шаблонов (по одному в строке, `*` — любые знаки); пустой список — везде. */
    private static function pageAllowed(string $path, string $patterns, string $mode): bool
    {
        $path  = '/' . trim($path, '/');
        $lines = array_filter(array_map('trim', preg_split('~\R~u', $patterns) ?: []));

        if (!$lines) {
            return true;
        }

        $hit = false;

        foreach ($lines as $line) {
            $regex = '~^' . str_replace('\*', '.*', preg_quote('/' . trim($line, '/'), '~')) . '/?$~iu';

            if (preg_match($regex, $path)) {
                $hit = true;
                break;
            }
        }

        return $mode === 'exclude' ? !$hit : $hit;
    }

    /** Коды и названия опубликованных форм. @return array<string, string> */
    public function options(): array
    {
        return array_map(static fn (array $form): string => (string) $form['title'], self::all());
    }
}
