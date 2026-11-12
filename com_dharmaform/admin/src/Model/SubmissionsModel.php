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

/** Список заявок: фильтры и сводка по доставке — одним запросом с подзапросами по индексам. */
class SubmissionsModel extends ListModel
{
    public function __construct($config = [], ?MVCFactoryInterface $factory = null)
    {
        $config['filter_fields'] = ['id', 'a.id', 'created', 'a.created', 'name', 'a.name', 'phone', 'a.phone', 'email', 'a.email', 'form_id', 'a.form_id', 'is_spam', 'a.is_spam', 'is_read', 'a.is_read', 'form_title'];

        parent::__construct($config, $factory);
    }

    protected function populateState($ordering = 'a.created', $direction = 'desc')
    {
        parent::populateState($ordering, $direction);
    }

    protected function getStoreId($id = '')
    {
        foreach (['search', 'form_id', 'is_spam', 'is_read', 'date_from', 'date_to'] as $key) {
            $id .= ':' . $this->getState('filter.' . $key);
        }

        return parent::getStoreId($id);
    }

    protected function getListQuery()
    {
        $db    = $this->getDatabase();
        $query = $db->createQuery()
            ->select($db->quoteName(['a.id', 'a.form_id', 'a.name', 'a.phone', 'a.email', 'a.created', 'a.is_spam', 'a.is_read', 'a.spam_score', 'a.spam_reason']))
            ->select("(a.files IS NOT NULL AND a.files NOT IN ('', '[]')) AS " . $db->quoteName('has_files'))
            ->select($db->quoteName('f.title', 'form_title'))
            ->select('(SELECT COUNT(*) FROM ' . $db->quoteName('#__dharmaform_deliveries', 'd1') . ' WHERE d1.submission_id = a.id AND d1.state = 1) AS ' . $db->quoteName('delivered'))
            ->select('(SELECT COUNT(*) FROM ' . $db->quoteName('#__dharmaform_deliveries', 'd2') . ' WHERE d2.submission_id = a.id AND d2.state = 2) AS ' . $db->quoteName('failed'))
            ->select('(SELECT COUNT(*) FROM ' . $db->quoteName('#__dharmaform_deliveries', 'd3') . ' WHERE d3.submission_id = a.id AND d3.state = 0) AS ' . $db->quoteName('pending'))
            ->from($db->quoteName('#__dharmaform_submissions', 'a'))
            ->join('LEFT', $db->quoteName('#__dharmaform_forms', 'f') . ' ON f.id = a.form_id');

        $formId = (int) $this->getState('filter.form_id');

        if ($formId > 0) {
            $query->where($db->quoteName('a.form_id') . ' = :form')->bind(':form', $formId, ParameterType::INTEGER);
        }

        foreach (['is_spam', 'is_read'] as $flag) {
            $value = $this->getState('filter.' . $flag);

            if ($value === '0' || $value === '1' || $value === 0 || $value === 1) {
                $value = (int) $value;
                $query->where($db->quoteName('a.' . $flag) . ' = :' . $flag)->bind(':' . $flag, $value, ParameterType::INTEGER);
            }
        }

        $from = trim((string) $this->getState('filter.date_from'));
        $to   = trim((string) $this->getState('filter.date_to'));

        if (preg_match('~^\d{4}-\d{2}-\d{2}~', $from)) {
            $from = substr($from, 0, 10) . ' 00:00:00';
            $query->where($db->quoteName('a.created') . ' >= :dfrom')->bind(':dfrom', $from);
        }

        if (preg_match('~^\d{4}-\d{2}-\d{2}~', $to)) {
            $to = substr($to, 0, 10) . ' 23:59:59';
            $query->where($db->quoteName('a.created') . ' <= :dto')->bind(':dto', $to);
        }

        $search = trim((string) $this->getState('filter.search'));

        if ($search !== '') {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search) . '%';
            $query->where('(' . $db->quoteName('a.name') . ' LIKE :s1 OR ' . $db->quoteName('a.phone') . ' LIKE :s2 OR ' . $db->quoteName('a.email') . ' LIKE :s3)')
                ->bind([':s1', ':s2', ':s3'], $like);
        }

        $order = (string) $this->getState('list.ordering', 'a.created');
        $order = \in_array($order, $this->filter_fields, true) ? $order : 'a.created';
        $order = $order === 'form_title' ? $db->quoteName('form_title') : $db->escape($order);
        $query->order($order . ' ' . ($this->getState('list.direction', 'DESC') === 'ASC' ? 'ASC' : 'DESC'));

        return $query;
    }

    /** База данных для экспорта (метод родителя защищённый, а выгрузку строит контроллер). */
    public function getExportDatabase(): \Joomla\Database\DatabaseInterface
    {
        return $this->getDatabase();
    }

    /** Запрос текущей выборки без постраничности — для выгрузки в CSV. */
    public function getExportQuery()
    {
        return $this->getListQuery();
    }
}
