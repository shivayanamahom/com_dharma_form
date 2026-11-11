<?php

/**
 * Dharma Form — универсальные формы для Joomla 5.2+ и 6+.
 *
 * @package    com_dharmaform
 * @copyright  (C) 2026 Dharma. Все права защищены.
 * @license    GNU General Public License версии 2 или новее; см. LICENSE.txt
 */

declare(strict_types=1);

namespace Dharma\Component\DharmaForm\Administrator\View\Submission;

use Joomla\CMS\Factory;
use Joomla\CMS\Helper\ContentHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Toolbar\ToolbarHelper;

\defined('_JEXEC') or die;

class HtmlView extends BaseHtmlView
{
    protected $item;

    protected $canDo;

    public function display($tpl = null): void
    {
        $model       = $this->getModel();
        $this->canDo = ContentHelper::getActions('com_dharmaform');

        if (!$this->canDo->get('submission.view')) {
            throw new \RuntimeException(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }

        $this->item = $model->getItem((int) Factory::getApplication()->getInput()->getInt('id'));

        if (!$this->item) {
            throw new \RuntimeException(Text::_('COM_DHARMAFORM_SUBMISSION_NOT_FOUND'), 404);
        }

        // Открытая заявка считается прочитанной.
        if (!(int) $this->item->is_read && $this->canDo->get('core.edit.state')) {
            $model->markRead((int) $this->item->id);
            $this->item->is_read = 1;
        }

        $this->addToolbar();

        parent::display($tpl);
    }

    protected function addToolbar(): void
    {
        Factory::getApplication()->getInput()->set('hidemainmenu', true);

        $toolbar = $this->getDocument()->getToolbar();

        ToolbarHelper::title(Text::sprintf('COM_DHARMAFORM_MANAGER_SUBMISSION', (int) $this->item->id), 'envelope');

        if ($this->canDo->get('core.edit.state')) {
            $toolbar->standardButton($this->item->is_spam ? 'notspam' : 'spam', $this->item->is_spam ? 'COM_DHARMAFORM_TOOLBAR_MARK_NOTSPAM' : 'COM_DHARMAFORM_TOOLBAR_MARK_SPAM', $this->item->is_spam ? 'submission.notspam' : 'submission.spam')
                ->icon($this->item->is_spam ? 'icon-check' : 'icon-ban')
                ->listCheck(false);
        }

        $toolbar->cancel('submission.cancel', 'JTOOLBAR_CLOSE');
    }
}
