<?php

/**
 * Dharma Form — универсальные формы для Joomla 5.2+ и 6+.
 *
 * @package    com_dharmaform
 * @copyright  (C) 2026 Dharma. Все права защищены.
 * @license    GNU General Public License версии 2 или новее; см. LICENSE.txt
 */

declare(strict_types=1);

namespace Dharma\Component\DharmaForm\Administrator\Helper;

\defined('_JEXEC') or die;

/** Подписи дополнительных данных о посетителе (уведомления и выгрузка пишутся по-русски, как и остальной текст сообщений). */
final class VisitorMeta
{
    public const LABELS = [
        'ip'      => 'IP-адрес',
        'browser' => 'Браузер',
        'os'      => 'Система',
        'device'  => 'Устройство',
        'lang'    => 'Язык браузера',
        'ref'     => 'Пришёл со страницы',
        'screen'  => 'Экран',
        'tz'      => 'Часовой пояс',
    ];

    private const DEVICES = ['desktop' => 'компьютер', 'mobile' => 'телефон', 'tablet' => 'планшет'];

    /** @param array<string, mixed> $meta @return array<string, string> подпись => значение */
    public static function pairs(array $meta): array
    {
        $out = [];

        foreach (self::LABELS as $key => $label) {
            $value = trim((string) ($meta[$key] ?? ''));

            if ($value !== '') {
                $out[$label] = $key === 'device' ? (self::DEVICES[$value] ?? $value) : $value;
            }
        }

        return $out;
    }
}
