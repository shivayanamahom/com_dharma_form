<?php

/**
 * Dharma Form — универсальные формы для Joomla 5.2+ и 6+.
 *
 * @package    com_dharmaform
 * @copyright  (C) 2026 Dharma. Все права защищены.
 * @license    GNU General Public License версии 2 или новее; см. LICENSE.txt
 */

declare(strict_types=1);

namespace Dharma\Component\DharmaForm\Site\Helper;

use Dharma\Component\DharmaForm\Administrator\Forms\FormSanitizer;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Language\Text;

\defined('_JEXEC') or die;

/** Разметка текстов форм: согласие с меткой `{link}`, безопасный HTML. Очистка — при каждом выводе. */
final class FormMarkup
{
    /** Строка из языкового файла; без перевода — запасной текст, а не имя ключа. */
    public static function text(string $key, string $fallback): string
    {
        $text = Text::_($key);

        return $text === $key ? $fallback : $text;
    }

    /**
     * Текст согласия: безопасный HTML; метка {link} заменяется ссылкой, без метки ссылка дописывается. Адрес — из поля,
     * иначе общий адрес политики из настроек компонента (`privacy_url`).
     */
    public static function consent(string $text, string $linkText, string $url): string
    {
        $html = FormSanitizer::html($text);
        $url  = FormSanitizer::url($url !== '' ? $url : (string) ComponentHelper::getParams('com_dharmaform')->get('privacy_url', ''));
        $link = '';

        if ($linkText === '' && ($url !== '' || str_contains($html, '{link}'))) {
            $linkText = self::text('COM_DHARMAFORM_FORM_POLICY', 'Политикой конфиденциальности');
        }

        if ($linkText !== '') {
            $label = htmlspecialchars($linkText, ENT_QUOTES, 'UTF-8');
            $link  = $url !== '' ? '<a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" target="_blank" rel="noopener">' . $label . '</a>' : $label;
        }

        if (str_contains($html, '{link}')) {
            return str_replace('{link}', $link, $html);
        }

        return $link !== '' && $url !== '' ? $html . ' ' . $link : $html;
    }

    public static function html(string $html): string
    {
        return FormSanitizer::html($html);
    }
}
