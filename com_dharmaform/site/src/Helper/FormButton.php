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

use Dharma\Component\DharmaForm\Site\Service\FormService;
use Joomla\CMS\Router\Route;

\defined('_JEXEC') or die;

/**
 * Кнопка, открывающая форму в окне: обычная ссылка на страницу формы (без JS ведёт туда), скрипт перехватывает клик.
 * Нет формы или она не опубликована — пустая строка.
 */
final class FormButton
{
    /** @var array<string, bool> */
    private static array $exists = [];

    public static function render(string $code, string $text, string $class = 'dharmaform__open'): string
    {
        self::$exists[$code] ??= (bool) (new FormService())->byCode($code);

        if (!self::$exists[$code]) {
            return '';
        }

        FormAssets::enable();

        $esc  = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
        $href = Route::_('index.php?option=com_dharmaform&view=form&code=' . rawurlencode($code), false);

        return '<a class="' . $esc($class) . '" href="' . $esc($href) . '" rel="nofollow" role="button" data-dharmaform="' . $esc($code) . '">' . $esc($text) . '</a>';
    }
}
