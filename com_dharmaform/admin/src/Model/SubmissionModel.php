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

use Dharma\Component\DharmaForm\Administrator\Notification\SubmissionNotificationService;
use Dharma\Component\DharmaForm\Administrator\Service\SubmissionRetentionService;
use Joomla\CMS\MVC\Model\AdminModel;

\defined('_JEXEC') or die;

/** Заявка: просмотр, пометки «прочитано» и «спам», удаление вместе с файлами. */
class SubmissionModel extends AdminModel
{
    public $typeAlias = 'com_dharmaform.submission';

    protected $text_prefix = 'COM_DHARMAFORM';

    public function getTable($name = 'Submission', $prefix = 'Administrator', $options = [])
    {
        return parent::getTable($name, $prefix, $options);
    }

    public function getForm($data = [], $loadData = true)
    {
        return false;
    }

    /** Заявка с названием формы, разобранными полями и файлами; null, если такой нет. */
    public function getItem($pk = null)
    {
        $pk = (int) ($pk ?: $this->getState($this->getName() . '.id'));
        $db = $this->getDatabase();

        $query = $db->createQuery()
            ->select('a.*, f.title AS form_title, f.code AS form_code')
            ->from($db->quoteName('#__dharmaform_submissions', 'a'))
            ->join('LEFT', $db->quoteName('#__dharmaform_forms', 'f') . ' ON f.id = a.form_id')
            ->where($db->quoteName('a.id') . ' = :id')
            ->bind(':id', $pk, \Joomla\Database\ParameterType::INTEGER);
        $item = $db->setQuery($query)->loadObject();

        if (!$item) {
            return null;
        }

        $decode        = static fn (mixed $json): array => \is_string($json) && $json !== '' ? (json_decode($json, true) ?: []) : [];
        $item->fields  = $decode($item->fields);
        $item->files   = array_values(array_filter($decode($item->files), 'is_array'));
        $item->utm     = $decode($item->utm);
        $item->deliveries = SubmissionNotificationService::fromContainer()->deliveries($pk);

        return $item;
    }

    public function markRead(int $id, int $value = 1): bool
    {
        $db    = $this->getDatabase();
        $query = $db->createQuery()
            ->update($db->quoteName('#__dharmaform_submissions'))
            ->set($db->quoteName('is_read') . ' = :v')
            ->where($db->quoteName('id') . ' = :id')
            ->bind(':v', $value, \Joomla\Database\ParameterType::INTEGER)
            ->bind(':id', $id, \Joomla\Database\ParameterType::INTEGER);

        return (bool) $db->setQuery($query)->execute();
    }

    /** @param int[] $pks */
    public function setFlag(array $pks, string $column, int $value): bool
    {
        if (!\in_array($column, ['is_read', 'is_spam'], true)) {
            return false;
        }

        $pks   = array_values(array_filter(array_map('intval', $pks)));
        $db    = $this->getDatabase();
        $query = $db->createQuery()
            ->update($db->quoteName('#__dharmaform_submissions'))
            ->set($db->quoteName($column) . ' = :v')
            ->whereIn($db->quoteName('id'), $pks)
            ->bind(':v', $value, \Joomla\Database\ParameterType::INTEGER);

        return $pks && $db->setQuery($query)->execute();
    }

    protected function canDelete($record)
    {
        return !empty($record->id) && $this->getCurrentUser()->authorise('core.delete', 'com_dharmaform');
    }

    protected function canEditState($record)
    {
        return $this->getCurrentUser()->authorise('core.edit.state', 'com_dharmaform');
    }

    public function delete(&$pks)
    {
        $ids = array_values(array_filter(array_map('intval', (array) $pks)));

        if (!$this->getCurrentUser()->authorise('core.delete', 'com_dharmaform')) {
            $this->setError(\Joomla\CMS\Language\Text::_('JLIB_APPLICATION_ERROR_DELETE_NOT_PERMITTED'));

            return false;
        }

        SubmissionRetentionService::fromContainer()->delete($ids);

        return true;
    }
}
