<?php

/**
 * Dharma Form — универсальные формы для Joomla 5.2+ и 6+.
 *
 * @package    com_dharmaform
 * @copyright  (C) 2026 Dharma. Все права защищены.
 * @license    GNU General Public License версии 2 или новее; см. LICENSE.txt
 */

declare(strict_types=1);

namespace Dharma\Component\DharmaForm\Administrator\Field;

use Joomla\CMS\Factory;
use Joomla\CMS\Form\Field\ListField;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\Database\DatabaseInterface;

\defined('_JEXEC') or die;

/** Список опубликованных форм (значение — код формы): для пункта меню и других настроек. */
class FormcodeField extends ListField
{
    protected $type = 'Formcode';

    protected function getOptions(): array
    {
        $options = parent::getOptions();
        $db      = Factory::getContainer()->get(DatabaseInterface::class);
        $query   = $db->createQuery()
            ->select($db->quoteName(['code', 'title']))
            ->from($db->quoteName('#__dharmaform_forms'))
            ->where($db->quoteName('state') . ' = 1')
            ->order($db->quoteName('ordering') . ', ' . $db->quoteName('title'));

        foreach ($db->setQuery($query)->loadObjectList() ?: [] as $row) {
            $options[] = HTMLHelper::_('select.option', (string) $row->code, (string) $row->title);
        }

        return $options;
    }
}
