<?php

/**
 * Dharma Form — универсальные формы для Joomla 5.2+ и 6+.
 *
 * @package    com_dharmaform
 * @copyright  (C) 2026 Dharma. Все права защищены.
 * @license    GNU General Public License версии 2 или новее; см. LICENSE.txt
 */

declare(strict_types=1);

namespace Dharma\Component\DharmaForm\Administrator\Forms;

use Joomla\CMS\Application\ApplicationHelper;
use Joomla\CMS\Filter\InputFilter;

\defined('_JEXEC') or die;

/**
 * Очистка текстов и адресов форм сайта (D41). Применяется при сохранении формы и при каждом выводе на сайте:
 * HTML пропускается через белый список тегов и атрибутов, адреса — через белый список схем.
 */
final class FormSanitizer
{
    public const MAX_FIELDS = 50;

    public const MAX_OPTIONS = 100;

    public const MAX_MASK = 40;

    public const DEFAULT_MASK = '+7 (###) ###-##-##';

    private const TAGS = ['a', 'b', 'strong', 'i', 'em', 'br', 'p', 'span', 'ul', 'ol', 'li'];

    private const ATTRIBUTES = ['href', 'target', 'rel', 'class'];

    /** Имена, которые занимает сам механизм форм и Joomla: поле с таким именем получает префикс `f_`. */
    private const RESERVED = ['option', 'task', 'view', 'layout', 'format', 'tmpl', 'id', 'form', 'form_code', 'website', 'stamp', 'return', 'token', 'ctx', 'jform', 'page_url', 'page_title'];

    /** Безопасный HTML: только разрешённые теги и атрибуты, ссылки только допустимых схем. */
    public static function html(string $html): string
    {
        $html = trim($html);

        if ($html === '') {
            return '';
        }

        $filter = InputFilter::getInstance(self::TAGS, self::ATTRIBUTES, InputFilter::ONLY_ALLOW_DEFINED_TAGS, InputFilter::ONLY_ALLOW_DEFINED_ATTRIBUTES);
        $clean  = (string) $filter->clean($html, 'html');

        // Ссылки с недопустимой схемой теряют адрес.
        return (string) preg_replace_callback('~href="([^"]*)"~i', static fn (array $m): string => self::url(html_entity_decode($m[1])) !== '' ? 'href="' . htmlspecialchars(self::url(html_entity_decode($m[1])), ENT_QUOTES, 'UTF-8') . '"' : '', $clean);
    }

    /** Допустимый адрес: `https://`, `http://`, путь от корня (`/…`, но не `//host`), `#`, `mailto:`, `tel:`; иначе пустая строка. */
    /**
     * Адрес страницы, с которой отправлена форма (хранится в заявке и показывается в админке ссылкой): только страница этого сайта.
     * Чужой адрес в поле подменить может любой посетитель, поэтому он отбрасывается: иначе в журнале заявок окажется ссылка на
     * чужой сайт, по которой сотрудник может пройти.
     */
    public static function pageUrl(string $url): string
    {
        $url = self::url($url);

        if ($url === '' || !preg_match('~^https?://([^\s/:]+)~i', $url, $m)) {
            return str_starts_with($url, '/') || str_starts_with($url, '#') ? $url : '';
        }

        $host = strtolower(preg_replace('/^www\./i', '', $m[1]) ?? '');
        $own  = \Dharma\Component\DharmaForm\Administrator\Service\SiteUrl::host();

        return $host !== '' && $host === $own ? $url : '';
    }

    public static function url(string $url): string
    {
        $url = trim($url);

        if ($url === '' || preg_match('~[\x00-\x20\\\\]~', $url) || mb_strlen($url) > 500) {
            return '';
        }

        if (preg_match('~^(?:https?://[^\s/]+|/(?!/)|#|mailto:[^\s]+|tel:[+0-9()\-.\s]+)~i', $url)) {
            return $url;
        }

        return '';
    }

    /** Маска телефона: `#` — цифра; допустимы цифры, пробел, `+ - ( ) .`; пусто или недопустимое — российская по умолчанию. */
    public static function mask(string $mask): string
    {
        $mask = trim($mask);

        return $mask !== '' && mb_strlen($mask) <= self::MAX_MASK && preg_match('~^[#0-9 ()+\-.]+$~', $mask) ? $mask : self::DEFAULT_MASK;
    }

    /** Имя поля из подписи: транслитерация, латиница, цифры и подчёркивание, до 32 знаков. */
    public static function nameFromLabel(string $label): string
    {
        return self::name(str_replace('-', '_', ApplicationHelper::stringURLSafe($label)));
    }

    /** Допустимое имя поля: недопустимые знаки вырезаются, начало с цифры и зарезервированные имена получают `f_`. */
    public static function name(string $name): string
    {
        $name = strtolower((string) preg_replace('~[^a-zA-Z0-9_]+~', '', $name));

        if ($name === '') {
            return '';
        }

        if (!preg_match('~^[a-z]~', $name) || \in_array($name, self::RESERVED, true) || str_starts_with($name, 'dc_')) {
            $name = 'f_' . $name;
        }

        return substr($name, 0, 32);
    }

    /**
     * Варианты списка: по одному в строке, `значение|подпись` (без `|` значение и подпись одинаковые).
     *
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(string $text): array
    {
        $out = [];

        foreach (preg_split('~\R~u', $text) ?: [] as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            [$value, $label] = array_pad(explode('|', $line, 2), 2, '');
            $value           = trim($value);
            $label           = trim($label) !== '' ? trim($label) : $value;

            if ($value !== '') {
                $out[] = ['value' => mb_substr($value, 0, 255), 'label' => mb_substr($label, 0, 255)];
            }

            if (\count($out) >= self::MAX_OPTIONS) {
                break;
            }
        }

        return $out;
    }
}
