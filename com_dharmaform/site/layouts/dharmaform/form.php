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

use Dharma\Component\DharmaForm\Administrator\Forms\FormSanitizer;
use Dharma\Component\DharmaForm\Site\Helper\FormMarkup;
use Dharma\Component\DharmaForm\Site\Service\SubmissionGuard;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Uri\Uri;

/**
 * Разметка формы. $displayData:
 *  - form   — форма (`FormConfig::fromRow`): id, code, title, fields, params;
 *  - action — адрес отправки (по умолчанию `task=form.submit`);
 *  - inline — форма на странице (не в окне): без заголовка окна, адрес страницы подставляется и без JS;
 *  - title  — необязательный заголовок над формой (для формы на странице).
 * Переопределяется в шаблоне: html/layouts/dharmaform/form.php.
 */
$form   = $displayData['form'];
$params = $form['params'];
$inline = !empty($displayData['inline']);
$action = (string) ($displayData['action'] ?? Route::_('index.php?option=com_dharmaform&task=form.submit', false));
$uid    = 'dform' . substr(md5($form['code'] . microtime()), 0, 6);
$labels = !empty($params['show_labels']);
$esc    = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
$t      = static fn (string $key, string $fallback): string => FormMarkup::text($key, $fallback);
$stamp  = SubmissionGuard::stamp();
$pow    = !empty($params['protect_pow']);
$submit = trim((string) $params['submit_text']) !== '' ? (string) $params['submit_text'] : $t('COM_DHARMAFORM_FORM_SUBMIT', 'Отправить');

$errorBox = static function (array $field, string $key = 'COM_DHARMAFORM_FORM_ERROR_REQUIRED', string $fallback = 'Заполните поле') use ($esc, $t): string {
    $custom = trim((string) $field['error_text']);

    return '<div class="dharmaform__error" data-msg-empty="' . $esc($t($key, $fallback)) . '"' . ($custom !== '' ? ' data-msg="' . $esc($custom) . '"' : '') . ' aria-live="polite"></div>';
};

$accept = static function (string $raw): string {
    $out = [];

    foreach (preg_split('~[\s,;]+~', strtolower($raw), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $ext) {
        $ext = ltrim($ext, '.');

        if (preg_match('~^[a-z0-9]{1,8}$~', $ext)) {
            $out[$ext] = '.' . $ext;
        }
    }

    return implode(',', $out);
};
?>
<form class="dharmaform" id="<?php echo $uid; ?>" action="<?php echo $esc($action); ?>" method="post" enctype="multipart/form-data" novalidate<?php echo $pow ? ' data-dharma-pow="' . min(24, max(8, (int) ($params['protect_pow_bits'] ?? 14))) . '"' : ''; ?> data-dharmaform-root="<?php echo $esc($form['code']); ?>">
    <?php if (trim((string) ($displayData['title'] ?? '')) !== '') : ?>
        <h2 class="dharmaform__title"><?php echo $esc((string) $displayData['title']); ?></h2>
    <?php endif; ?>

    <?php if (trim((string) $params['modal_intro']) !== '') : ?>
        <div class="dharmaform__intro"><?php echo FormMarkup::html((string) $params['modal_intro']); ?></div>
    <?php endif; ?>

    <div class="dharmaform__grid">
    <?php foreach ($form['fields'] as $field) :
        $type    = (string) $field['type'];
        $name    = (string) $field['name'];

        if ($name === '' && $type !== 'content') {
            continue;
        }

        $id      = $uid . '-' . $name;
        $col     = (string) $field['width'] === '6' ? 'dharmaform__col-6' : 'dharmaform__col-12';
        $label   = (string) $field['label'];
        $holder  = (string) $field['placeholder'] !== '' ? (string) $field['placeholder'] : (!$labels ? $label : '');
        $req     = !empty($field['required']);
        $reqAttr = $req ? ' required aria-required="true"' : '';
        $star    = $req ? ' <span class="dharmaform__star" aria-hidden="true">*</span>' : '';
        $box     = 'class="dharmaform__field dharmaform__field--' . $esc($type) . ' ' . $col . '" data-dharma-field="' . $esc($name) . '"' . ($req ? ' data-required="1"' : '');
        $opts    = \in_array($type, ['select', 'radio', 'checkboxes'], true) ? FormSanitizer::options((string) $field['options']) : [];
        ?>
        <?php if ($type === 'content') : ?>
            <div class="dharmaform__content <?php echo $col; ?>"><?php echo FormMarkup::html((string) $field['content']); ?></div>
        <?php elseif ($type === 'hidden') : ?>
            <input type="hidden" name="f[<?php echo $esc($name); ?>]" value="<?php echo $field['hidden_source'] === 'custom' ? $esc((string) $field['hidden_value']) : ''; ?>" data-dharma-hidden="<?php echo $esc((string) $field['hidden_source']); ?>">
        <?php elseif ($type === 'consent') : ?>
            <div <?php echo $box; ?>>
                <label class="dharmaform__check">
                    <input class="dharmaform__check-input" type="checkbox" id="<?php echo $esc($id); ?>" name="f[<?php echo $esc($name); ?>]" value="1"<?php echo $reqAttr; ?>>
                    <span><?php echo FormMarkup::consent((string) $field['consent_text'], (string) $field['link_text'], (string) $field['link_url']) . $star; ?></span>
                </label>
                <?php echo $errorBox($field, 'COM_DHARMAFORM_FORM_ERROR_CONSENT', 'Необходимо ваше согласие'); ?>
            </div>
        <?php elseif ($type === 'radio' || $type === 'checkboxes') : ?>
            <div <?php echo $box; ?> role="<?php echo $type === 'radio' ? 'radiogroup' : 'group'; ?>" aria-labelledby="<?php echo $esc($id); ?>-l">
                <?php if ($label !== '') : ?><span class="dharmaform__label" id="<?php echo $esc($id); ?>-l"><?php echo $esc($label) . $star; ?></span><?php endif; ?>
                <?php foreach ($opts as $n => $opt) : ?>
                    <label class="dharmaform__check">
                        <input class="dharmaform__check-input" type="<?php echo $type === 'radio' ? 'radio' : 'checkbox'; ?>" id="<?php echo $esc($id . '-' . $n); ?>" name="f[<?php echo $esc($name); ?>]<?php echo $type === 'checkboxes' ? '[]' : ''; ?>" value="<?php echo $esc($opt['value']); ?>">
                        <span><?php echo $esc($opt['label']); ?></span>
                    </label>
                <?php endforeach; ?>
                <?php echo $errorBox($field, 'COM_DHARMAFORM_FORM_ERROR_CHOICE', 'Выберите вариант'); ?>
            </div>
        <?php elseif ($type === 'file') :
            $multiple = !empty($field['file_multiple']);
            $dropzone = $field['file_mode'] === 'dropzone';
            $maxMb    = max(1, (int) $field['file_max_mb']);
            $exts     = $accept((string) $field['file_accept']);
            $hint     = sprintf($t('COM_DHARMAFORM_FORM_FILE_HINT', 'до %s МБ'), (string) $maxMb);
            ?>
            <div <?php echo $box; ?>>
                <?php if ($label !== '') : ?><label class="dharmaform__label" for="<?php echo $esc($id); ?>"><?php echo $esc($label) . $star; ?></label><?php endif; ?>
                <div class="dharmaform__file dharmaform__file--<?php echo $dropzone ? 'dropzone' : 'button'; ?>" data-dharma-file data-max-mb="<?php echo $maxMb; ?>" data-max-count="<?php echo $multiple ? max(1, (int) $field['file_max_count']) : 1; ?>" data-accept="<?php echo $esc($exts); ?>">
                    <div class="dharmaform__file-area">
                        <input class="dharmaform__file-input" type="file" id="<?php echo $esc($id); ?>" name="f[<?php echo $esc($name); ?>]<?php echo $multiple ? '[]' : ''; ?>"<?php echo $multiple ? ' multiple' : ''; ?><?php echo $exts !== '' ? ' accept="' . $esc($exts) . '"' : ''; ?> title="">
                        <?php if ($dropzone) : ?>
                            <span class="dharmaform__drop-icon" aria-hidden="true"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 16V4m0 0-4 4m4-4 4 4M4 16v3a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-3"/></svg></span>
                            <span><?php echo $esc($t($multiple ? 'COM_DHARMAFORM_FORM_FILE_DROP_MULTI' : 'COM_DHARMAFORM_FORM_FILE_DROP', $multiple ? 'Перетащите файлы сюда или нажмите' : 'Перетащите файл сюда или нажмите')); ?></span>
                            <span class="dharmaform__hint"><?php echo $esc($hint); ?></span>
                        <?php else : ?>
                            <span class="dharmaform__upload-btn"><?php echo $esc($t($multiple ? 'COM_DHARMAFORM_FORM_FILE_CHOOSE_MULTI' : 'COM_DHARMAFORM_FORM_FILE_CHOOSE', $multiple ? 'Выбрать файлы' : 'Выбрать файл')); ?></span>
                        <?php endif; ?>
                    </div>
                    <ul class="dharmaform__file-list"></ul>
                </div>
                <?php echo $errorBox($field); ?>
            </div>
        <?php else : ?>
            <div <?php echo $box; ?>>
                <?php if ($labels && $label !== '') : ?><label class="dharmaform__label" for="<?php echo $esc($id); ?>"><?php echo $esc($label) . $star; ?></label><?php endif; ?>
                <?php if ($type === 'textarea') : ?>
                    <textarea class="dharmaform__control" id="<?php echo $esc($id); ?>" name="f[<?php echo $esc($name); ?>]" rows="<?php echo max(1, (int) $field['rows']); ?>" maxlength="5000"<?php echo $reqAttr; ?><?php echo $holder !== '' ? ' placeholder="' . $esc($holder) . '"' : ''; ?>></textarea>
                <?php elseif ($type === 'select') : ?>
                    <select class="dharmaform__control" id="<?php echo $esc($id); ?>" name="f[<?php echo $esc($name); ?>]"<?php echo $reqAttr; ?>>
                        <option value=""><?php echo $esc($holder !== '' ? $holder : $t('COM_DHARMAFORM_FORM_CHOOSE', 'Выберите')); ?></option>
                        <?php foreach ($opts as $opt) : ?><option value="<?php echo $esc($opt['value']); ?>"><?php echo $esc($opt['label']); ?></option><?php endforeach; ?>
                    </select>
                <?php else :
                    $mask = $type === 'tel' ? FormSanitizer::mask((string) $field['mask']) : '';
                    $html = $type === 'tel' ? 'tel' : ($type === 'email' ? 'email' : ($type === 'date' ? 'date' : 'text'));
                    ?>
                    <input class="dharmaform__control" type="<?php echo $html; ?>" id="<?php echo $esc($id); ?>" name="f[<?php echo $esc($name); ?>]" maxlength="<?php echo $type === 'tel' ? 50 : 500; ?>"<?php echo $mask !== '' ? ' data-dharma-mask="' . $esc($mask) . '" inputmode="tel" autocomplete="tel"' : ''; ?><?php echo $type === 'email' ? ' autocomplete="email"' : ''; ?><?php echo $reqAttr; ?><?php echo $holder !== '' ? ' placeholder="' . $esc($holder) . '"' : ($mask !== '' ? ' placeholder="' . $esc($mask) . '"' : ''); ?>>
                <?php endif; ?>
                <?php echo $errorBox($field, $type === 'tel' ? 'COM_DHARMAFORM_FORM_ERROR_PHONE' : ($type === 'email' ? 'COM_DHARMAFORM_FORM_ERROR_EMAIL' : 'COM_DHARMAFORM_FORM_ERROR_REQUIRED'), $type === 'tel' ? 'Введите номер телефона полностью' : ($type === 'email' ? 'Введите корректный e-mail' : 'Заполните поле')); ?>
            </div>
        <?php endif; ?>
    <?php endforeach; ?>
    </div>

    <div style="position:absolute;left:-10000px" aria-hidden="true">
        <label>Website <input type="text" name="website" value="" tabindex="-1" autocomplete="off"></label>
        <label>Comment <input type="text" name="<?php echo $esc(SubmissionGuard::honeypotName($stamp)); ?>" value="" tabindex="-1" autocomplete="off"></label>
    </div>
    <input type="hidden" name="form" value="<?php echo $esc($form['code']); ?>">
    <input type="hidden" name="stamp" value="<?php echo $esc($stamp); ?>">
    <?php if ($pow) : ?><input type="hidden" name="pow" value=""><?php endif; ?>
    <?php if (!empty($params['protect_behavior'])) : ?><input type="hidden" name="dfh" value=""><?php endif; ?>
    <input type="hidden" name="page_url" value="<?php echo $inline ? $esc(Uri::getInstance()->toString(['scheme', 'host', 'port', 'path', 'query'])) : ''; ?>">
    <?php echo HTMLHelper::_('form.token'); ?>

    <div class="dharmaform__alert" role="alert" hidden></div>
    <button type="submit" class="dharmaform__submit<?php echo trim((string) $params['button_class']) !== '' ? ' ' . $esc(trim((string) $params['button_class'])) : ''; ?>"><?php echo $esc($submit); ?></button>
</form>
