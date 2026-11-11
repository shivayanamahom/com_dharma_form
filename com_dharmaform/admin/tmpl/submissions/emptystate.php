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

echo LayoutHelper::render('joomla.content.emptystate', [
    'textPrefix'    => 'COM_DHARMAFORM_SUBMISSION',
    'formURL'       => 'index.php?option=com_dharmaform&view=submissions',
    'icon'          => 'icon-envelope',
    'controlFields' => $this->filterForm->renderControlFields(),
]);
