<?php

/**
 * Dharma Form — универсальные формы для Joomla 5.2+ и 6+.
 *
 * @package    com_dharmaform
 * @copyright  (C) 2026 Dharma. Все права защищены.
 * @license    GNU General Public License версии 2 или новее; см. LICENSE.txt
 */

declare(strict_types=1);

namespace Dharma\Component\DharmaForm\Administrator\Model;

use Joomla\CMS\MVC\Factory\MVCFactoryInterface;
use Joomla\CMS\MVC\Model\ListModel;
use Joomla\Database\ParameterType;

\defined('_JEXEC') or die;

/** Список форм: одним запросом, число заявок — подзапросом по индексу. */
class FormsModel extends ListModel
{
    public function __construct($config = [], ?MVCFactoryInterface $factory = null)
    {
        $config['filter_fields'] = ['id', 'a.id', 'title', 'a.title', 'code', 'a.code', 'state', 'a.state', 'ordering', 'a.ordering', 'created', 'a.created', 'submissions'];

        parent::__construct($config, $factory);
    }

    protected function populateState($ordering = 'a.ordering', $direction = 'asc')
    {
        parent::populateState($ordering, $direction);
    }

    protected function getStoreId($id = '')
    {
        return parent::getStoreId($id . ':' . $this->getState('filter.search') . ':' . $this->getState('filter.state'));
    }

    protected function getListQuery()
    {
        $db    = $this->getDatabase();
        $query = $db->createQuery()
            ->select($db->quoteName(['a.id', 'a.code', 'a.title', 'a.state', 'a.ordering', 'a.created']))
            ->select('(SELECT COUNT(*) FROM ' . $db->quoteName('#__dharmaform_submissions', 's') . ' WHERE s.form_id = a.id) AS ' . $db->quoteName('submissions'))
            ->from($db->quoteName('#__dharmaform_forms', 'a'));

        $state = $this->getState('filter.state');

        if (is_numeric($state)) {
            $state = (int) $state;
            $query->where($db->quoteName('a.state') . ' = :state')->bind(':state', $state, ParameterType::INTEGER);
        } else {
            $query->whereIn($db->quoteName('a.state'), [0, 1]);
        }

        $search = trim((string) $this->getState('filter.search'));

        if ($search !== '') {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search) . '%';
            $query->where('(' . $db->quoteName('a.title') . ' LIKE :s1 OR ' . $db->quoteName('a.code') . ' LIKE :s2)')
                ->bind([':s1', ':s2'], $like);
        }

        $order = $this->getState('list.ordering', 'a.ordering');
        $order = \in_array($order, $this->filter_fields, true) ? $order : 'a.ordering';
        $order = $order === 'submissions' ? $db->quoteName('submissions') : $db->escape($order);
        $query->order($order . ' ' . ($this->getState('list.direction', 'ASC') === 'DESC' ? 'DESC' : 'ASC'));

        return $query;
    }
}
