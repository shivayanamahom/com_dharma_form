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
 * Чтение опубликованных форм. Лёгкий список (без полей) читается одним запросом и лежит в кэше Joomla (группа `com_dharmaform`),
 * который сбрасывается при любом изменении формы (`clearCache`): на странице без форм и открывателей запросов к базе нет.
 */
final class FormService
{
    private const CACHE_GROUP = 'com_dharmaform';

    private const CACHE_KEY = 'published_forms';

    /** @var array<string, array<string, mixed>>|null */
    private static ?array $memory = null;

    /** @var array<string, array<string, mixed>|null> */
    private static array $full = [];

    /** Сбросить кэш форм (после сохранения, удаления, смены состояния формы). */
    public static function clearCache(): void
    {
        self::$memory = null;
        self::$full   = [];

        try {
            Factory::getContainer()->get(CacheControllerFactoryInterface::class)->createCacheController('callback', ['defaultgroup' => self::CACHE_GROUP])->clean();
        } catch (\Throwable) {
            // Кэш недоступен: формы читаются из базы.
        }
    }

    /**
     * Лёгкий список опубликованных форм для каждой страницы сайта (открыватели, автопоказ, привязки): без описания полей, которое
     * может быть большим и нужно только при показе и отправке формы.
     *
     * @return array<string, array<string, mixed>> код => форма без `fields`
     */
    private static function all(): array
    {
        if (self::$memory !== null) {
            return self::$memory;
        }

        $load = static function (): array {
            $db   = Factory::getContainer()->get(DatabaseInterface::class);
            $rows = $db->setQuery('SELECT id, code, title, access, params FROM ' . $db->quoteName('#__dharmaform_forms') . ' WHERE state = 1 ORDER BY ordering, title')->loadObjectList() ?: [];
            $out  = [];

            foreach ($rows as $row) {
                $row->fields = '[]';
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

    /** Доступна ли форма посетителю по уровню доступа, заданному в форме (кэш форм один на всех, фильтр — по текущему пользователю). */
    private static function visible(array $form): bool
    {
        $levels = Factory::getApplication()->getIdentity()->getAuthorisedViewLevels();

        return \in_array((int) ($form['access'] ?? 1), array_map('intval', $levels), true);
    }

    /** @return array<string, array<string, mixed>> формы, доступные текущему посетителю */
    private static function allVisible(): array
    {
        return array_filter(self::all(), static fn (array $form): bool => self::visible($form));
    }

    /** @return array<string, mixed>|null форма (`FormConfig::fromRow`) или null: нет, не опубликована или нет доступа */
    public function byCode(string $code): ?array
    {
        $code = (string) preg_replace('~[^A-Za-z0-9._-]+~', '', $code);

        if ($code === '') {
            return null;
        }

        // Полное описание (с полями) читается одной строкой и только для показа или отправки формы.
        if (!isset(self::$full[$code])) {
            $db  = Factory::getContainer()->get(DatabaseInterface::class);
            $row = $db->setQuery('SELECT * FROM ' . $db->quoteName('#__dharmaform_forms') . ' WHERE state = 1 AND code = ' . $db->quote($code))->loadObject();

            self::$full[$code] = $row ? FormConfig::fromRow($row) : null;
        }

        $form = self::$full[$code];

        return $form !== null && self::visible($form) ? $form : null;
    }

    /** Классы-открыватели всплывающих форм: код формы => список классов (первый — сам код). @return array<string, string[]> */
    public function triggers(): array
    {
        $out = [];

        foreach (self::allVisible() as $code => $form) {
            if ($form['params']['display'] !== 'popup') {
                continue;
            }

            // Открывает форму сам код как класс (и его безопасный вариант с префиксом) и классы из настроек формы.
            $classes = preg_split('~\s+~', trim((string) $form['params']['trigger_class']), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $out[$code] = array_values(array_unique(array_merge([$code, 'dharmaform-' . $code], $classes)));
        }

        return $out;
    }

    /**
     * Правила привязки к объекту (товару) для всплывающих форм: код формы => правила (`ContextResolver::rulesFor`).
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    public function contexts(): array
    {
        $out = [];

        foreach (self::allVisible() as $code => $form) {
            $rules = $form['params']['display'] === 'popup' ? ContextResolver::rulesFor($form['params']) : [];

            if ($rules) {
                $out[$code] = $rules;
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

        foreach (self::allVisible() as $code => $form) {
            $p = $form['params'];

            if ($p['display'] !== 'popup' || empty($p['auto_enabled']) || !(array) $p['auto_triggers']) {
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
                'sent'      => max(0, (int) $p['auto_sent_days']),
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
        return array_map(static fn (array $form): string => (string) $form['title'], self::allVisible());
    }
}
