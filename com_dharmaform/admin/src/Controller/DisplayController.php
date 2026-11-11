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
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Router\Route;

\defined('_JEXEC') or die;

class DisplayController extends BaseController
{
    protected $option = 'com_dharmaform';

    protected $default_view = 'forms';

    public function display($cachable = false, $urlparams = [])
    {
        $view   = $this->input->get('view', 'forms');
        $layout = $this->input->get('layout', 'default');
        $id     = $this->input->getInt('id');

        if ($view === 'form' && $layout === 'edit' && !$this->checkEditId('com_dharmaform.edit.form', $id)) {
            if (!\count($this->app->getMessageQueue())) {
                $this->setMessage(Text::sprintf('JLIB_APPLICATION_ERROR_UNHELD_ID', $id), 'error');
            }

            $this->setRedirect(Route::_('index.php?option=com_dharmaform&view=forms', false));

            return false;
        }

        return parent::display();
    }
}
