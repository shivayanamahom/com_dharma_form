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

use Dharma\Component\DharmaForm\Administrator\Helper\SpamReasons;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Layout\LayoutHelper;
use Joomla\CMS\Router\Route;

/** @var \Dharma\Component\DharmaForm\Administrator\View\Submissions\HtmlView $this */
$this->getDocument()->getWebAssetManager()->useScript('table.columns')->useScript('multiselect');

$user      = $this->getCurrentUser();
$canView   = $user->authorise('submission.view', 'com_dharmaform');
$listOrder = $this->escape($this->state->get('list.ordering'));
$listDirn  = $this->escape($this->state->get('list.direction'));
?>
<form action="<?php echo Route::_('index.php?option=com_dharmaform&view=submissions'); ?>" method="post" name="adminForm" id="adminForm">
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
                    <table class="table">
                        <caption class="visually-hidden">
                            <?php echo Text::_('COM_DHARMAFORM_SUBMISSIONS_TABLE_CAPTION'); ?>,
                            <span id="orderedBy"><?php echo Text::_('JGLOBAL_SORTED_BY'); ?> </span>,
                            <span id="filteredBy"><?php echo Text::_('JGLOBAL_FILTERED_BY'); ?></span>
                        </caption>
                        <thead>
                            <tr>
                                <td class="w-1 text-center"><?php echo HTMLHelper::_('grid.checkall'); ?></td>
                                <th scope="col" class="w-15"><?php echo HTMLHelper::_('searchtools.sort', 'COM_DHARMAFORM_HEADING_CREATED', 'a.created', $listDirn, $listOrder); ?></th>
                                <th scope="col"><?php echo HTMLHelper::_('searchtools.sort', 'COM_DHARMAFORM_HEADING_NAME', 'a.name', $listDirn, $listOrder); ?></th>
                                <th scope="col" class="d-none d-md-table-cell"><?php echo Text::_('COM_DHARMAFORM_HEADING_CONTACTS'); ?></th>
                                <th scope="col" class="d-none d-md-table-cell"><?php echo HTMLHelper::_('searchtools.sort', 'COM_DHARMAFORM_HEADING_FORM', 'form_title', $listDirn, $listOrder); ?></th>
                                <th scope="col" class="w-10 d-none d-md-table-cell"><?php echo Text::_('COM_DHARMAFORM_HEADING_DELIVERY'); ?></th>
                                <th scope="col" class="w-5 d-none d-md-table-cell"><?php echo HTMLHelper::_('searchtools.sort', 'JGRID_HEADING_ID', 'a.id', $listDirn, $listOrder); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($this->items as $i => $item) : ?>
                                <tr class="row<?php echo $i % 2; ?>">
                                    <td class="text-center"><?php echo HTMLHelper::_('grid.id', $i, $item->id, false, 'cid', 'cb', '#' . $item->id); ?></td>
                                    <td class="small"><?php echo HTMLHelper::_('date', $item->created, Text::_('DATE_FORMAT_LC5')); ?></td>
                                    <th scope="row">
                                        <?php $name = trim((string) $item->name) !== '' ? $item->name : Text::_('COM_DHARMAFORM_NO_NAME'); ?>
                                        <?php if ($canView) : ?>
                                            <a href="<?php echo Route::_('index.php?option=com_dharmaform&view=submission&id=' . (int) $item->id); ?>"<?php echo !$item->is_read ? ' class="fw-bold"' : ''; ?>><?php echo $this->escape($name); ?></a>
                                        <?php else : ?>
                                            <?php echo $this->escape($name); ?>
                                        <?php endif; ?>
                                        <?php if (!$item->is_read) : ?><span class="badge bg-primary ms-1"><?php echo Text::_('COM_DHARMAFORM_BADGE_NEW'); ?></span><?php endif; ?>
                                        <?php if ($item->is_spam || (int) $item->spam_score > 0) : ?>
                                            <?php $why = implode(', ', SpamReasons::labels((string) $item->spam_reason)); ?>
                                            <span class="badge bg-<?php echo $item->is_spam ? 'warning text-dark' : 'light text-dark border'; ?> ms-1" title="<?php echo $this->escape(Text::_('COM_DHARMAFORM_SUBMISSION_SPAM_SCORE') . ': ' . (int) $item->spam_score . ($why !== '' ? ' — ' . $why : '')); ?>"><?php echo Text::_($item->is_spam ? 'COM_DHARMAFORM_BADGE_SPAM' : 'COM_DHARMAFORM_BADGE_SUSPECT'); ?> · <?php echo (int) $item->spam_score; ?></span>
                                        <?php endif; ?>
                                        <?php if ($item->has_files) : ?><span class="icon-paperclip ms-1" aria-hidden="true" title="<?php echo Text::_('COM_DHARMAFORM_HAS_FILES'); ?>"></span><?php endif; ?>
                                    </th>
                                    <td class="d-none d-md-table-cell small">
                                        <?php echo $this->escape((string) $item->phone); ?><?php echo $item->phone !== '' && $item->email !== '' ? '<br>' : ''; ?><?php echo $this->escape((string) $item->email); ?>
                                    </td>
                                    <td class="d-none d-md-table-cell small"><?php echo $item->form_title !== null ? $this->escape($item->form_title) : '—'; ?></td>
                                    <td class="d-none d-md-table-cell small">
                                        <?php if ((int) $item->failed > 0) : ?><span class="badge bg-danger" title="<?php echo Text::_('COM_DHARMAFORM_DELIVERY_FAILED'); ?>"><?php echo (int) $item->failed; ?></span><?php endif; ?>
                                        <?php if ((int) $item->pending > 0) : ?><span class="badge bg-secondary" title="<?php echo Text::_('COM_DHARMAFORM_DELIVERY_PENDING'); ?>"><?php echo (int) $item->pending; ?></span><?php endif; ?>
                                        <?php if ((int) $item->delivered > 0) : ?><span class="badge bg-success" title="<?php echo Text::_('COM_DHARMAFORM_DELIVERY_SENT'); ?>"><?php echo (int) $item->delivered; ?></span><?php endif; ?>
                                        <?php if (!$item->failed && !$item->pending && !$item->delivered) : ?>—<?php endif; ?>
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
