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

use Joomla\CMS\Layout\LayoutHelper;

$displayData = [
    'textPrefix'    => 'COM_DHARMAFORM_FORM',
    'formURL'       => 'index.php?option=com_dharmaform&view=forms',
    'icon'          => 'icon-list-2',
    'controlFields' => $this->filterForm->renderControlFields(),
];

if ($this->getCurrentUser()->authorise('core.create', 'com_dharmaform')) {
    $displayData['createURL'] = 'index.php?option=com_dharmaform&task=form.add';
}

echo LayoutHelper::render('joomla.content.emptystate', $displayData);
