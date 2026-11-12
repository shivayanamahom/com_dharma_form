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
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Document\HtmlDocument;
use Joomla\CMS\Factory;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Uri\Uri;

\defined('_JEXEC') or die;

/** Подключение стиля и скрипта форм через Web Asset Manager: один раз за страницу, только там, где форма нужна. */
final class FormAssets
{
    private static bool $enabled = false;

    private const STRINGS = [
        'close'      => ['COM_DHARMAFORM_JS_CLOSE', 'Закрыть'],
        'required'   => ['COM_DHARMAFORM_FORM_ERROR_REQUIRED', 'Заполните поле'],
        'email'      => ['COM_DHARMAFORM_FORM_ERROR_EMAIL', 'Введите корректный e-mail'],
        'phone'      => ['COM_DHARMAFORM_FORM_ERROR_PHONE', 'Введите номер телефона полностью'],
        'consent'    => ['COM_DHARMAFORM_FORM_ERROR_CONSENT', 'Необходимо ваше согласие'],
        'choice'     => ['COM_DHARMAFORM_FORM_ERROR_CHOICE', 'Выберите вариант'],
        'file_type'  => ['COM_DHARMAFORM_FORM_ERROR_FILE_TYPE', 'Недопустимый тип файла'],
        'file_size'  => ['COM_DHARMAFORM_FORM_ERROR_FILE_SIZE', 'Файл слишком большой'],
        'file_count' => ['COM_DHARMAFORM_FORM_ERROR_FILE_COUNT', 'Слишком много файлов'],
        'send_error' => ['COM_DHARMAFORM_FORM_ERROR_SAVE', 'Не удалось отправить форму. Попробуйте ещё раз.'],
        'load_error' => ['COM_DHARMAFORM_FORM_ERROR_LOAD', 'Не удалось открыть форму.'],
    ];

    /** Путь текущей страницы от корня сайта со слешем в начале («/kontakty»), без параметров. */
    public static function currentPath(): string
    {
        $base = rtrim(Uri::base(true), '/');
        $path = (string) Uri::getInstance()->getPath();

        return '/' . trim(substr($path, \strlen($base)), '/');
    }

    private static bool $full = false;

    /** Нужно ли обновлять токен и метку у форм на странице (страница может лежать в кэше). Настройка `inline_refresh`: auto — только если включён кэш Joomla. */
    public static function refreshInline(): bool
    {
        $mode = (string) ComponentHelper::getParams('com_dharmaform')->get('inline_refresh', 'auto');

        if ($mode === 'always' || $mode === 'never') {
            return $mode === 'always';
        }

        return (int) Factory::getApplication()->get('caching', 0) > 0 || PluginHelper::isEnabled('system', 'cache');
    }

    /**
     * Подключение скриптов. Без `$inline` — лёгкий загрузчик (кнопки, автопоказ): его тяжёлая часть и стили подгружаются, когда
     * форма действительно нужна. С `$inline` (форма выведена на странице) тяжёлая часть и стили идут в страницу сразу.
     */
    public static function enable(bool $inline = false): void
    {
        $document = Factory::getApplication()->getDocument();

        if (!$document instanceof HtmlDocument) {
            return;
        }

        $wa = $document->getWebAssetManager();

        if (!self::$enabled) {
            self::$enabled = true;
            $wa->getRegistry()->addExtensionRegistryFile('com_dharmaform');
            $wa->useScript('com_dharmaform.form');

            $strings = [];

            foreach (self::STRINGS as $key => [$constant, $fallback]) {
                $strings[$key] = FormMarkup::text($constant, $fallback);
            }

            $service = new FormService();
            $media   = ['version' => 'auto', 'relative' => true, 'pathOnly' => true];
            $document->addScriptOptions('com_dharmaform.form', [
                'render'   => Route::_('index.php?option=com_dharmaform&task=form.render&format=json', false),
                'ui'       => (string) HTMLHelper::_('script', 'com_dharmaform/form-ui.js', $media),
                'css'      => (string) HTMLHelper::_('stylesheet', 'com_dharmaform/form.css', $media),
                'triggers' => (object) $service->triggers(),
                'auto'     => (object) $service->autoPopups(self::currentPath()),
                'contexts' => (object) $service->contexts(),
                'dev'      => (bool) (int) ComponentHelper::getParams('com_dharmaform')->get('dev_mode', 0),
                'strings'  => $strings,
            ]);
        }

        if ($inline && !self::$full) {
            self::$full = true;
            $wa->useStyle('com_dharmaform.form')->useScript('com_dharmaform.form-ui');
            $document->addScriptOptions('com_dharmaform.form', ['css' => '']);
        }
    }
}
