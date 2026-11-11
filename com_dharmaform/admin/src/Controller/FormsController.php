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

use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\AdminController;
use Joomla\CMS\Router\Route;

\defined('_JEXEC') or die;

class FormsController extends AdminController
{
    protected $option = 'com_dharmaform';

    protected $text_prefix = 'COM_DHARMAFORM_FORMS';

    public function getModel($name = 'Form', $prefix = 'Administrator', $config = ['ignore_request' => true])
    {
        return parent::getModel($name, $prefix, $config);
    }

    public function duplicate(): void
    {
        $this->checkToken();

        $pks = array_filter(array_map('intval', (array) $this->input->get('cid', [], 'array')));

        if (!$pks || !$this->app->getIdentity()->authorise('core.create', 'com_dharmaform')) {
            $this->setMessage(Text::_('JGLOBAL_NO_ITEM_SELECTED'), 'warning');
        } elseif ($this->getModel()->duplicate($pks)) {
            $this->setMessage(Text::plural('COM_DHARMAFORM_FORMS_N_ITEMS_DUPLICATED', \count($pks)));
        } else {
            $this->setMessage($this->getModel()->getError(), 'error');
        }

        $this->setRedirect(Route::_('index.php?option=com_dharmaform&view=forms', false));
    }
}
