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

use Dharma\Component\DharmaForm\Site\Helper\FormAssets;
use Joomla\CMS\Layout\LayoutHelper;

/** @var \Dharma\Component\DharmaForm\Site\View\Form\HtmlView $this */
FormAssets::enable(true);
?>
<div class="com-dharmaform dharmaform-page">
    <?php if ($this->params->get('show_page_heading')) : ?>
        <h1><?php echo $this->escape($this->params->get('page_heading') ?: $this->form['title']); ?></h1>
    <?php endif; ?>
    <?php echo LayoutHelper::render('dharmaform.form', ['form' => $this->form, 'inline' => true, 'ctx' => $this->ctx], JPATH_SITE . '/components/com_dharmaform/layouts'); ?>
</div>
