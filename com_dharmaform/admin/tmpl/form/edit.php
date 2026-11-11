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
use Joomla\CMS\Router\Route;

/** @var \Dharma\Component\DharmaForm\Administrator\View\Form\HtmlView $this */
$this->getDocument()->getWebAssetManager()->useScript('keepalive')->useScript('form.validate');

$group = function (string $legend, array $names, string $note = '') : string {
    $html = '<fieldset class="options-form mb-3"><legend>' . Text::_($legend) . '</legend>';

    if ($note !== '') {
        $html .= '<div class="alert alert-info"><span class="icon-info-circle" aria-hidden="true"></span> ' . Text::_($note) . '</div>';
    }

    foreach ($names as $name) {
        $html .= $this->form->renderField($name, 'params');
    }

    return $html . '</fieldset>';
};
?>
<form action="<?php echo Route::_('index.php?option=com_dharmaform&layout=edit&id=' . (int) $this->item->id); ?>" method="post" name="adminForm" id="form-form" aria-label="<?php echo Text::_('COM_DHARMAFORM_FORM_FORM_' . ((int) $this->item->id === 0 ? 'NEW' : 'EDIT'), true); ?>" class="form-validate">
    <div class="row title-alias form-vertical mb-3">
        <div class="col-12 col-md-6"><?php echo $this->form->renderField('title'); ?></div>
        <div class="col-12 col-md-6"><?php echo $this->form->renderField('code'); ?></div>
    </div>
    <div class="main-card">
        <?php echo HTMLHelper::_('uitab.startTabSet', 'myTab', ['active' => 'general', 'recall' => true, 'breakpoint' => 768]); ?>

        <?php echo HTMLHelper::_('uitab.addTab', 'myTab', 'general', Text::_('COM_DHARMAFORM_FORM_TAB_GENERAL')); ?>
        <div class="row"><div class="col-lg-6">
            <?php
            echo $this->form->renderField('state');
            echo $this->form->renderField('access');
            echo $this->form->renderField('ordering');
            echo $this->form->renderField('id');
            ?>
        </div></div>
        <?php echo HTMLHelper::_('uitab.endTab'); ?>

        <?php echo HTMLHelper::_('uitab.addTab', 'myTab', 'fields', Text::_('COM_DHARMAFORM_FORM_TAB_FIELDS')); ?>
        <?php echo $this->form->renderField('fields'); ?>
        <?php echo HTMLHelper::_('uitab.endTab'); ?>

        <?php echo HTMLHelper::_('uitab.addTab', 'myTab', 'window', Text::_('COM_DHARMAFORM_FORM_TAB_LOOK')); ?>
        <div class="row"><div class="col-lg-8">
            <?php echo $group('COM_DHARMAFORM_FORM_GROUP_LOOK', ['modal_title', 'modal_intro', 'submit_text', 'button_class', 'show_labels', 'trigger_class'], 'COM_DHARMAFORM_FORM_LOOK_NOTE'); ?>
            <?php echo $group('COM_DHARMAFORM_FORM_GROUP_AFTER', ['after_action', 'success_message', 'success_redirect', 'after_delay', 'close_modal'], ''); ?>
            <?php echo $group('COM_DHARMAFORM_FORM_GROUP_ANALYTICS', ['goal_name', 'metrika_counter'], 'COM_DHARMAFORM_FORM_ANALYTICS_NOTE'); ?>
            <?php echo $group('COM_DHARMAFORM_FORM_GROUP_CONSENT', ['consent_url'], ''); ?>
        </div></div>
        <?php echo HTMLHelper::_('uitab.endTab'); ?>

        <?php echo HTMLHelper::_('uitab.addTab', 'myTab', 'auto', Text::_('COM_DHARMAFORM_FORM_TAB_AUTO')); ?>
        <div class="row"><div class="col-lg-8">
            <?php echo $group('COM_DHARMAFORM_FORM_GROUP_AUTO', ['auto_enabled', 'auto_triggers', 'auto_delay', 'auto_scroll', 'auto_idle', 'auto_frequency', 'auto_days', 'auto_devices', 'auto_pages_mode', 'auto_pages'], 'COM_DHARMAFORM_FORM_AUTO_NOTE'); ?>
        </div></div>
        <?php echo HTMLHelper::_('uitab.endTab'); ?>

        <?php echo HTMLHelper::_('uitab.addTab', 'myTab', 'protect', Text::_('COM_DHARMAFORM_FORM_TAB_PROTECT')); ?>
        <div class="row"><div class="col-lg-8">
            <?php echo $group('COM_DHARMAFORM_FORM_GROUP_PROTECT', ['protect_honeypot', 'protect_min_seconds', 'protect_rate_limit', 'protect_flood_limit'], 'COM_DHARMAFORM_FORM_PROTECT_NOTE'); ?>
            <?php echo $group('COM_DHARMAFORM_FORM_GROUP_PROTECT_BROWSER', ['protect_pow', 'protect_pow_bits', 'protect_behavior', 'protect_replay'], ''); ?>
            <?php echo $group('COM_DHARMAFORM_FORM_GROUP_PROTECT_CONTENT', ['protect_links', 'protect_scripts', 'protect_gibberish', 'protect_stopwords'], ''); ?>
            <?php echo $group('COM_DHARMAFORM_FORM_GROUP_PROTECT_MAIL', ['protect_disposable', 'protect_mx'], ''); ?>
            <?php echo $group('COM_DHARMAFORM_FORM_GROUP_PROTECT_SCORE', ['protect_score_limit'], 'COM_DHARMAFORM_FORM_PROTECT_SCORE_NOTE'); ?>
            <?php echo $group('COM_DHARMAFORM_FORM_GROUP_RETENTION', ['retention_days'], ''); ?>
        </div></div>
        <?php echo HTMLHelper::_('uitab.endTab'); ?>

        <?php echo HTMLHelper::_('uitab.addTab', 'myTab', 'notify', Text::_('COM_DHARMAFORM_FORM_TAB_NOTIFY')); ?>
        <div class="row"><div class="col-lg-8">
            <?php echo $group('COM_DHARMAFORM_FORM_GROUP_NOTIFY', ['notify_channels', 'notify_emails', 'notify_chats', 'notify_max_chats', 'notify_subject'], 'COM_DHARMAFORM_FORM_NOTIFY_NOTE'); ?>
        </div></div>
        <?php echo HTMLHelper::_('uitab.endTab'); ?>

        <?php echo HTMLHelper::_('uitab.endTabSet'); ?>
    </div>
    <?php echo $this->form->renderControlFields(); ?>
</form>
