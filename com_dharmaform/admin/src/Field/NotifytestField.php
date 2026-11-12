<?php

/**
 * Dharma Form — универсальные формы для Joomla 5.2+ и 6+.
 *
 * @package    com_dharmaform
 * @copyright  (C) 2026 Dharma. Все права защищены.
 * @license    GNU General Public License версии 2 или новее; см. LICENSE.txt
 */

declare(strict_types=1);

namespace Dharma\Component\DharmaForm\Administrator\Field;

use Joomla\CMS\Factory;
use Joomla\CMS\Form\FormField;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Session\Session;

\defined('_JEXEC') or die;

/** Кнопка «Отправить тест» канала уведомлений (`channel="email|telegram|max"`): отправка по сохранённым настройкам и понятный итог. */
class NotifytestField extends FormField
{
    protected $type = 'Notifytest';

    protected function getInput(): string
    {
        $channel = (string) ($this->element['channel'] ?? '');
        $url     = 'index.php?option=com_dharmaform&task=notify.test&format=json&channel=' . rawurlencode($channel) . '&' . Session::getFormToken() . '=1';

        $wa = Factory::getApplication()->getDocument()->getWebAssetManager();

        // Настройки открывает com_config: реестр ресурсов нашего компонента подключается явно.
        $wa->getRegistry()->addExtensionRegistryFile('com_dharmaform');
        $wa->useScript('com_dharmaform.notify-test');

        return '<button type="button" class="btn btn-outline-primary" data-dharmaform-test data-url="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">'
            . '<span class="icon-paper-plane" aria-hidden="true"></span> ' . Text::_('COM_DHARMAFORM_TEST_BUTTON') . '</button>'
            . '<div class="form-text" data-dharmaform-test-result role="status" aria-live="polite"></div>';
    }
}
