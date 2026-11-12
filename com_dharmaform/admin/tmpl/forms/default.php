<?php

/**
 * Dharma Form — универсальные формы для Joomla 5.2+ и 6+.
 *
 * @package    com_dharmaform
 * @copyright  (C) 2026 Dharma. Все права защищены.
 * @license    GNU General Public License версии 2 или новее; см. LICENSE.txt
 */

declare(strict_types=1);

\defined('_JEXEC') or die;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Layout\LayoutHelper;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Session\Session;

/** @var \Dharma\Component\DharmaForm\Administrator\View\Forms\HtmlView $this */
$wa = $this->getDocument()->getWebAssetManager();
$wa->useScript('table.columns')->useScript('multiselect');

$user      = $this->getCurrentUser();
$listOrder = $this->escape($this->state->get('list.ordering'));
$listDirn  = $this->escape($this->state->get('list.direction'));
$saveOrder = $listOrder === 'a.ordering';
$canEdit   = $user->authorise('core.edit', 'com_dharmaform');
$canChange = $user->authorise('core.edit.state', 'com_dharmaform');

if ($saveOrder && !empty($this->items)) {
    $saveOrderingUrl = 'index.php?option=com_dharmaform&task=forms.saveOrderAjax&tmpl=component&' . Session::getFormToken() . '=1';
    HTMLHelper::_('draggablelist.draggable');
}

$wa->useScript('com_dharmaform.copy');
?>
<form action="<?php echo Route::_('index.php?option=com_dharmaform&view=forms'); ?>" method="post" name="adminForm" id="adminForm">
    <div class="row">
        <div class="col-md-12">
            <div id="j-main-container" class="j-main-container">
                <?php echo LayoutHelper::render('joomla.searchtools.default', ['view' => $this]); ?>
                <?php if (empty($this->items)) : ?>
                    <div class="alert alert-info">
                        <span class="icon-info-circle" aria-hidden="true"></span><span class="visually-hidden"><?php echo Text::_('INFO'); ?></span>
                        <?php echo Text::_('JGLOBAL_NO_MATCHING_RESULTS'); ?>
                    </div>
                <?php else : ?>
                    <table class="table" id="formList">
                        <caption class="visually-hidden">
                            <?php echo Text::_('COM_DHARMAFORM_FORMS_TABLE_CAPTION'); ?>,
                            <span id="orderedBy"><?php echo Text::_('JGLOBAL_SORTED_BY'); ?> </span>,
                            <span id="filteredBy"><?php echo Text::_('JGLOBAL_FILTERED_BY'); ?></span>
                        </caption>
                        <thead>
                            <tr>
                                <td class="w-1 text-center"><?php echo HTMLHelper::_('grid.checkall'); ?></td>
                                <th scope="col" class="w-1 text-center d-none d-md-table-cell">
                                    <?php echo HTMLHelper::_('searchtools.sort', '', 'a.ordering', $listDirn, $listOrder, null, 'asc', 'JGRID_HEADING_ORDERING', 'icon-sort'); ?>
                                </th>
                                <th scope="col" class="w-1 text-center"><?php echo HTMLHelper::_('searchtools.sort', 'JSTATUS', 'a.state', $listDirn, $listOrder); ?></th>
                                <th scope="col"><?php echo HTMLHelper::_('searchtools.sort', 'JGLOBAL_TITLE', 'a.title', $listDirn, $listOrder); ?></th>
                                <th scope="col" class="w-10 d-none d-md-table-cell"><?php echo Text::_('COM_DHARMAFORM_HEADING_TYPE'); ?></th>
                                <th scope="col" class="w-25 d-none d-md-table-cell"><?php echo Text::_('COM_DHARMAFORM_HEADING_CALL'); ?></th>
                                <th scope="col" class="w-10 d-none d-md-table-cell"><?php echo HTMLHelper::_('searchtools.sort', 'COM_DHARMAFORM_HEADING_SUBMISSIONS', 'submissions', $listDirn, $listOrder); ?></th>
                                <th scope="col" class="w-5 d-none d-md-table-cell"><?php echo HTMLHelper::_('searchtools.sort', 'JGRID_HEADING_ID', 'a.id', $listDirn, $listOrder); ?></th>
                            </tr>
                        </thead>
                        <tbody<?php if ($saveOrder) : ?> class="js-draggable" data-url="<?php echo $saveOrderingUrl; ?>" data-direction="<?php echo strtolower($listDirn); ?>" data-nested="false"<?php endif; ?>>
                            <?php foreach ($this->items as $i => $item) : ?>
                                <tr class="row<?php echo $i % 2; ?>" data-draggable-group="1">
                                    <td class="text-center"><?php echo HTMLHelper::_('grid.id', $i, $item->id, false, 'cid', 'cb', $item->title); ?></td>
                                    <td class="text-center d-none d-md-table-cell">
                                        <?php $iconClass = !$canChange ? ' inactive' : (!$saveOrder ? ' inactive" title="' . Text::_('JORDERINGDISABLED') : ''); ?>
                                        <span class="sortable-handler<?php echo $iconClass; ?>"><span class="icon-ellipsis-v" aria-hidden="true"></span></span>
                                        <?php if ($canChange && $saveOrder) : ?>
                                            <input type="text" name="order[]" size="5" value="<?php echo (int) $item->ordering; ?>" class="width-20 text-area-order hidden">
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center"><?php echo HTMLHelper::_('jgrid.published', $item->state, $i, 'forms.', $canChange); ?></td>
                                    <th scope="row" class="has-context">
                                        <?php if ($canEdit) : ?>
                                            <a href="<?php echo Route::_('index.php?option=com_dharmaform&task=form.edit&id=' . (int) $item->id); ?>"><?php echo $this->escape($item->title); ?></a>
                                        <?php else : ?>
                                            <?php echo $this->escape($item->title); ?>
                                        <?php endif; ?>
                                    </th>
                                    <?php
                                    $params = json_decode((string) $item->params, true) ?: [];
                                    $popup  = ($params['display'] ?? 'popup') !== 'inline';
                                    $call   = $popup ? $item->code : '{dharmaform ' . $item->code . '}';
                                    ?>
                                    <td class="d-none d-md-table-cell small"><?php echo Text::_($popup ? 'COM_DHARMAFORM_TYPE_POPUP' : 'COM_DHARMAFORM_TYPE_INLINE'); ?></td>
                                    <td class="d-none d-md-table-cell">
                                        <button type="button" class="btn btn-sm btn-secondary" title="<?php echo Text::_($popup ? 'COM_DHARMAFORM_CALL_POPUP_TIP' : 'COM_DHARMAFORM_CALL_INLINE_TIP'); ?>" data-dharmaform-copy="<?php echo $this->escape($call); ?>" data-copied="<?php echo Text::_('COM_DHARMAFORM_SHORTCODE_COPIED'); ?>"><?php echo $this->escape($call); ?></button>
                                    </td>
                                    <td class="d-none d-md-table-cell">
                                        <?php if ((int) $item->submissions > 0) : ?>
                                            <a href="<?php echo Route::_('index.php?option=com_dharmaform&view=submissions&filter[form_id]=' . (int) $item->id); ?>"><?php echo (int) $item->submissions; ?></a>
                                        <?php else : ?>0<?php endif; ?>
                                    </td>
                                    <td class="d-none d-md-table-cell"><?php echo (int) $item->id; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php echo $this->pagination->getListFooter(); ?>
                <?php endif; ?>
                <input type="hidden" name="task" value="">
                <input type="hidden" name="boxchecked" value="0">
                <?php echo HTMLHelper::_('form.token'); ?>
            </div>
        </div>
    </div>
</form>
