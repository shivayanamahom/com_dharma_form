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
use Joomla\CMS\Router\Route;

/** @var \Dharma\Component\DharmaForm\Administrator\View\Submission\HtmlView $this */
$item  = $this->item;
$esc   = fn (mixed $v): string => $this->escape((string) $v);
$bytes = static fn (int $n): string => $n >= 1048576 ? round($n / 1048576, 1) . ' MB' : max(1, (int) round($n / 1024)) . ' KB';
$known = [(string) $item->name, (string) $item->phone, (string) $item->email, (string) $item->message];
$state = [0 => 'COM_DHARMAFORM_DELIVERY_PENDING', 1 => 'COM_DHARMAFORM_DELIVERY_SENT', 2 => 'COM_DHARMAFORM_DELIVERY_FAILED'];
?>
<form action="<?php echo Route::_('index.php?option=com_dharmaform&view=submission&id=' . (int) $item->id); ?>" method="post" name="adminForm" id="adminForm">
    <div class="main-card">
        <?php echo HTMLHelper::_('uitab.startTabSet', 'submissionTab', ['active' => 'data', 'recall' => true, 'breakpoint' => 768]); ?>

        <?php echo HTMLHelper::_('uitab.addTab', 'submissionTab', 'data', Text::_('COM_DHARMAFORM_SUBMISSION_TAB_DATA')); ?>
        <div class="row">
            <div class="col-lg-8">
                <?php if ($item->is_spam) : ?>
                    <div class="alert alert-warning"><span class="icon-warning" aria-hidden="true"></span> <?php echo Text::_('COM_DHARMAFORM_SUBMISSION_SPAM_NOTE'); ?></div>
                <?php endif; ?>
                <table class="table">
                    <tbody>
                        <tr><th scope="row" class="w-25"><?php echo Text::_('COM_DHARMAFORM_HEADING_FORM'); ?></th><td><?php echo $item->form_title !== null ? $esc($item->form_title) : '—'; ?></td></tr>
                        <tr><th scope="row"><?php echo Text::_('COM_DHARMAFORM_HEADING_CREATED'); ?></th><td><?php echo HTMLHelper::_('date', $item->created, Text::_('DATE_FORMAT_LC6')); ?></td></tr>
                        <?php foreach (['name' => 'COM_DHARMAFORM_HEADING_NAME', 'phone' => 'COM_DHARMAFORM_CSV_PHONE', 'email' => 'COM_DHARMAFORM_CSV_EMAIL'] as $key => $label) : ?>
                            <?php if (trim((string) $item->$key) !== '') : ?>
                                <tr><th scope="row"><?php echo Text::_($label); ?></th><td><?php echo $esc($item->$key); ?></td></tr>
                            <?php endif; ?>
                        <?php endforeach; ?>
                        <?php foreach ($item->fields as $label => $value) : ?>
                            <?php if (\is_scalar($value) && !\in_array((string) $value, $known, true)) : ?>
                                <tr><th scope="row"><?php echo $esc($label); ?></th><td><?php echo nl2br($esc($value)); ?></td></tr>
                            <?php endif; ?>
                        <?php endforeach; ?>
                        <?php if (trim((string) $item->message) !== '') : ?>
                            <tr><th scope="row"><?php echo Text::_('COM_DHARMAFORM_CSV_MESSAGE'); ?></th><td><?php echo nl2br($esc($item->message)); ?></td></tr>
                        <?php endif; ?>
                        <?php if ($item->files) : ?>
                            <tr>
                                <th scope="row"><?php echo Text::_('COM_DHARMAFORM_SUBMISSION_FILES'); ?></th>
                                <td>
                                    <ul class="list-unstyled mb-0">
                                        <?php foreach ($item->files as $n => $file) : ?>
                                            <li>
                                                <span class="icon-paperclip" aria-hidden="true"></span>
                                                <a href="<?php echo Route::_('index.php?option=com_dharmaform&task=submission.download&id=' . (int) $item->id . '&n=' . (int) $n); ?>"><?php echo $esc($file['name'] ?? ''); ?></a>
                                                <?php if (!empty($file['size'])) : ?><span class="small text-muted">(<?php echo $bytes((int) $file['size']); ?>)</span><?php endif; ?>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                </td>
                            </tr>
                        <?php endif; ?>
                        <?php if ($item->page_url !== '') : ?>
                            <tr><th scope="row"><?php echo Text::_('COM_DHARMAFORM_CSV_PAGE'); ?></th><td><?php echo $esc($item->page_url); ?></td></tr>
                        <?php endif; ?>
                        <?php foreach ($item->utm as $key => $value) : ?>
                            <?php if (\is_scalar($value)) : ?>
                                <tr><th scope="row"><?php echo $esc($key); ?></th><td><?php echo $esc($value); ?></td></tr>
                            <?php endif; ?>
                        <?php endforeach; ?>
                        <?php if ((int) $item->spam_score > 0 || (string) $item->spam_reason !== '') : ?>
                            <tr>
                                <th scope="row"><?php echo Text::_('COM_DHARMAFORM_SUBMISSION_SPAM_SCORE'); ?></th>
                                <td>
                                    <span class="badge bg-<?php echo $item->is_spam ? 'warning text-dark' : 'secondary'; ?>"><?php echo (int) $item->spam_score; ?></span>
                                    <?php foreach (SpamReasons::labels((string) $item->spam_reason) as $reason) : ?>
                                        <span class="badge bg-light text-dark border"><?php echo $esc($reason); ?></span>
                                    <?php endforeach; ?>
                                </td>
                            </tr>
                        <?php endif; ?>
                        <?php if ($item->consent_at) : ?>
                            <tr><th scope="row"><?php echo Text::_('COM_DHARMAFORM_SUBMISSION_CONSENT'); ?></th><td><?php echo HTMLHelper::_('date', $item->consent_at, Text::_('DATE_FORMAT_LC6')); ?></td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php echo HTMLHelper::_('uitab.endTab'); ?>

        <?php echo HTMLHelper::_('uitab.addTab', 'submissionTab', 'delivery', Text::_('COM_DHARMAFORM_SUBMISSION_TAB_DELIVERY')); ?>
        <?php if (!$item->deliveries) : ?>
            <div class="alert alert-info"><span class="icon-info-circle" aria-hidden="true"></span> <?php echo Text::_('COM_DHARMAFORM_SUBMISSION_NO_DELIVERIES'); ?></div>
        <?php else : ?>
            <table class="table">
                <thead>
                    <tr>
                        <th scope="col"><?php echo Text::_('COM_DHARMAFORM_DELIVERY_CHANNEL'); ?></th>
                        <th scope="col"><?php echo Text::_('COM_DHARMAFORM_DELIVERY_RECIPIENT'); ?></th>
                        <th scope="col"><?php echo Text::_('JSTATUS'); ?></th>
                        <th scope="col"><?php echo Text::_('COM_DHARMAFORM_DELIVERY_ATTEMPTS'); ?></th>
                        <th scope="col"><?php echo Text::_('COM_DHARMAFORM_DELIVERY_SENT_AT'); ?></th>
                        <th scope="col"><?php echo Text::_('COM_DHARMAFORM_DELIVERY_ERROR'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($item->deliveries as $d) : ?>
                        <tr>
                            <td><?php echo $esc($d->channel); ?></td>
                            <td class="small"><?php echo $esc($d->recipient); ?></td>
                            <td><span class="badge bg-<?php echo [0 => 'secondary', 1 => 'success', 2 => 'danger'][(int) $d->state] ?? 'secondary'; ?>"><?php echo Text::_($state[(int) $d->state] ?? $state[0]); ?></span></td>
                            <td><?php echo (int) $d->attempts; ?></td>
                            <td class="small"><?php echo $d->sent ? HTMLHelper::_('date', $d->sent, Text::_('DATE_FORMAT_LC6')) : '—'; ?></td>
                            <td class="small"><?php echo $esc($d->error_code); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        <?php echo HTMLHelper::_('uitab.endTab'); ?>

        <?php echo HTMLHelper::_('uitab.endTabSet'); ?>
    </div>
    <input type="hidden" name="task" value="">
    <input type="hidden" name="id" value="<?php echo (int) $item->id; ?>">
    <?php echo HTMLHelper::_('form.token'); ?>
</form>
