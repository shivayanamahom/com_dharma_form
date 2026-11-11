<?php

/**
 * Dharma Form — универсальные формы для Joomla 5.2+ и 6+.
 *
 * @package    com_dharmaform
 * @copyright  (C) 2026 Dharma. Все права защищены.
 * @license    GNU General Public License версии 2 или новее; см. LICENSE.txt
 */

declare(strict_types=1);

namespace Dharma\Component\DharmaForm\Administrator\View\Submissions;

use Joomla\CMS\Helper\ContentHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Toolbar\ToolbarHelper;

\defined('_JEXEC') or die;

class HtmlView extends BaseHtmlView
{
    public $filterForm;

    public $activeFilters = [];

    protected $items = [];

    protected $pagination;

    protected $state;

    private bool $isEmptyState = false;

    public function display($tpl = null): void
    {
        $model = $this->getModel();
        $model->setUseExceptions(true);

        $this->items         = $model->getItems();
        $this->pagination    = $model->getPagination();
        $this->state         = $model->getState();
        $this->filterForm    = $model->getFilterForm();
        $this->activeFilters = $model->getActiveFilters();

        if (!\count($this->items) && $this->isEmptyState = $model->getIsEmptyState()) {
            $this->setLayout('emptystate');
        }

        $this->addToolbar();

        $this->filterForm->addControlField('task')->addControlField('boxchecked', '0');

        parent::display($tpl);
    }

    protected function addToolbar(): void
    {
        $canDo   = ContentHelper::getActions('com_dharmaform');
        $toolbar = $this->getDocument()->getToolbar();

        ToolbarHelper::title(Text::_('COM_DHARMAFORM_MANAGER_SUBMISSIONS'), 'envelope');

        if (!$this->isEmptyState && $canDo->get('core.edit.state')) {
            $dropdown = $toolbar->dropdownButton('status-group', 'JTOOLBAR_CHANGE_STATUS')
                ->toggleSplit(false)
                ->icon('icon-ellipsis-h')
                ->buttonClass('btn btn-action')
                ->listCheck(true);

            $childBar = $dropdown->getChildToolbar();
            $childBar->standardButton('read', 'COM_DHARMAFORM_TOOLBAR_MARK_READ', 'submissions.read')->icon('icon-eye')->listCheck(true);
            $childBar->standardButton('unread', 'COM_DHARMAFORM_TOOLBAR_MARK_UNREAD', 'submissions.unread')->icon('icon-eye-slash')->listCheck(true);
            $childBar->standardButton('spam', 'COM_DHARMAFORM_TOOLBAR_MARK_SPAM', 'submissions.spam')->icon('icon-ban')->listCheck(true);
            $childBar->standardButton('notspam', 'COM_DHARMAFORM_TOOLBAR_MARK_NOTSPAM', 'submissions.notspam')->icon('icon-check')->listCheck(true);
        }

        if (!$this->isEmptyState && $canDo->get('submission.export')) {
            $toolbar->standardButton('export', 'COM_DHARMAFORM_TOOLBAR_EXPORT', 'submissions.export')->icon('icon-download');
        }

        if (!$this->isEmptyState && $canDo->get('core.delete')) {
            $toolbar->delete('submissions.delete', 'JTOOLBAR_DELETE')->message('COM_DHARMAFORM_SUBMISSIONS_CONFIRM_DELETE')->listCheck(true);
        }

        if ($canDo->get('core.admin') || $canDo->get('core.options')) {
            $toolbar->preferences('com_dharmaform');
        }
    }
}
