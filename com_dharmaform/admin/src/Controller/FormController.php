<?php

/**
 * Dharma Form — универсальные формы для Joomla 5.2+ и 6+.
 *
 * @package    com_dharmaform
 * @copyright  (C) 2026 Dharma. Все права защищены.
 * @license    GNU General Public License версии 2 или новее; см. LICENSE.txt
 */

declare(strict_types=1);

namespace Dharma\Component\DharmaForm\Administrator\Controller;

use Joomla\CMS\MVC\Controller\FormController as BaseFormController;

\defined('_JEXEC') or die;

class FormController extends BaseFormController
{
    protected $option = 'com_dharmaform';

    protected $text_prefix = 'COM_DHARMAFORM_FORM';

    protected $view_list = 'forms';
}
