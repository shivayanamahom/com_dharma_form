<?php

/**
 * Dharma Form — универсальные формы для Joomla 5.2+ и 6+.
 *
 * @package    com_dharmaform
 * @copyright  (C) 2026 Dharma. Все права защищены.
 * @license    GNU General Public License версии 2 или новее; см. LICENSE.txt
 */

declare(strict_types=1);

namespace Dharma\Component\DharmaForm\Administrator\View\Forms;

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
        \Dharma\Component\DharmaForm\Administrator\Service\SiteUrl::remember();

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

        ToolbarHelper::title(Text::_('COM_DHARMAFORM_MANAGER_FORMS'), 'list-2');

        if ($canDo->get('core.create')) {
            $toolbar->addNew('form.add');
        }

        if (!$this->isEmptyState && ($canDo->get('core.edit.state') || $canDo->get('core.create'))) {
            $dropdown = $toolbar->dropdownButton('status-group', 'JTOOLBAR_CHANGE_STATUS')
                ->toggleSplit(false)
                ->icon('icon-ellipsis-h')
                ->buttonClass('btn btn-action')
                ->listCheck(true);

            $childBar = $dropdown->getChildToolbar();

            if ($canDo->get('core.edit.state')) {
                $childBar->publish('forms.publish')->listCheck(true);
                $childBar->unpublish('forms.unpublish')->listCheck(true);
                $childBar->archive('forms.archive')->listCheck(true);

                if ($this->state->get('filter.state') != -2) {
                    $childBar->trash('forms.trash')->listCheck(true);
                }
            }

            if ($canDo->get('core.create')) {
                $childBar->standardButton('duplicate', 'JTOOLBAR_DUPLICATE', 'forms.duplicate')->icon('icon-copy')->listCheck(true);
            }
        }

        if (!$this->isEmptyState && $this->state->get('filter.state') == -2 && $canDo->get('core.delete')) {
            $toolbar->delete('forms.delete', 'JTOOLBAR_DELETE_FROM_TRASH')->message('JGLOBAL_CONFIRM_DELETE')->listCheck(true);
        }

        if ($canDo->get('core.admin') || $canDo->get('core.options')) {
            $toolbar->preferences('com_dharmaform');
        }
    }
}
