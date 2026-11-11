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

use Dharma\Component\DharmaForm\Administrator\Forms\FormConfig;
use Dharma\Component\DharmaForm\Administrator\Forms\FormNormalizer;
use Dharma\Component\DharmaForm\Site\Service\FormService;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Model\AdminModel;

\defined('_JEXEC') or die;

/** Форма сайта: конструктор полей и настройки. */
class FormModel extends AdminModel
{
    public $typeAlias = 'com_dharmaform.form';

    protected $text_prefix = 'COM_DHARMAFORM';

    public function getTable($name = 'Form', $prefix = 'Administrator', $options = [])
    {
        return parent::getTable($name, $prefix, $options);
    }

    public function getForm($data = [], $loadData = true)
    {
        return $this->loadForm('com_dharmaform.form', 'form', ['control' => 'jform', 'load_data' => $loadData]) ?: false;
    }

    protected function loadFormData()
    {
        $data = Factory::getApplication()->getUserState('com_dharmaform.edit.form.data', []);

        if (empty($data)) {
            $data = $this->getItem();
        }

        $this->preprocessData('com_dharmaform.form', $data);

        return $data;
    }

    public function getItem($pk = null)
    {
        $item = parent::getItem($pk);

        if (!$item) {
            return $item;
        }

        $fields       = \is_string($item->fields ?? null) && $item->fields !== '' ? json_decode($item->fields, true) : [];
        $item->fields = \is_array($fields) ? array_values($fields) : [];

        $params = \is_string($item->params ?? null) && $item->params !== '' ? json_decode($item->params, true) : [];
        $params = \is_array($params) ? $params : [];

        // Получатели каналов хранятся строкой, поля выбора ждут список.
        foreach (['notify_chats', 'notify_max_chats'] as $key) {
            $params[$key] = preg_split('~[\s,;]+~u', (string) ($params[$key] ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }

        $item->params = $params;

        if (empty($item->id)) {
            $item->state = 1;
            $item->params = array_merge(FormConfig::DEFAULTS, $item->params);
            $item->fields = [
                ['type' => 'text', 'label' => Text::_('COM_DHARMAFORM_DEFAULT_FIELD_NAME'), 'name' => 'name', 'required' => 1],
                ['type' => 'tel', 'label' => Text::_('COM_DHARMAFORM_DEFAULT_FIELD_PHONE'), 'name' => 'phone', 'required' => 1],
                ['type' => 'consent', 'label' => '', 'name' => 'agree', 'required' => 1, 'consent_text' => Text::_('COM_DHARMAFORM_DEFAULT_CONSENT')],
            ];
        }

        return $item;
    }

    public function save($data)
    {
        $raw = isset($data['fields']) && \is_array($data['fields']) ? array_values($data['fields']) : [];
        [$fields, $error] = FormNormalizer::fields($raw);

        if ($error !== null) {
            $this->setError($error);

            return false;
        }

        $data['fields'] = json_encode($fields, JSON_UNESCAPED_UNICODE);
        $data['params'] = json_encode(FormNormalizer::params(isset($data['params']) && \is_array($data['params']) ? $data['params'] : []), JSON_UNESCAPED_UNICODE);

        if (!parent::save($data)) {
            return false;
        }

        FormService::clearCache();

        return true;
    }

    protected function prepareTable($table)
    {
        $now = Factory::getDate()->toSql();

        if (empty($table->id)) {
            $table->created = $now;

            if (empty($table->ordering)) {
                $db    = $this->getDatabase();
                $query = $db->createQuery()->select('MAX(' . $db->quoteName('ordering') . ')')->from($db->quoteName('#__dharmaform_forms'));
                $table->ordering = (int) $db->setQuery($query)->loadResult() + 1;
            }
        } else {
            $table->modified = $now;
        }
    }

    protected function canDelete($record)
    {
        return !empty($record->id) && (int) $record->state === -2 && $this->getCurrentUser()->authorise('core.delete', 'com_dharmaform');
    }

    protected function canEditState($record)
    {
        return $this->getCurrentUser()->authorise('core.edit.state', 'com_dharmaform');
    }

    /** Заявки удалённой формы остаются в журнале (с названием формы «—»), но без ссылки на неё. */
    public function delete(&$pks)
    {
        if (!parent::delete($pks)) {
            return false;
        }

        FormService::clearCache();

        return true;
    }

    public function publish(&$pks, $value = 1)
    {
        if (!parent::publish($pks, $value)) {
            return false;
        }

        FormService::clearCache();

        return true;
    }

    public function saveorder($pks = [], $order = null)
    {
        if (!parent::saveorder($pks, $order)) {
            return false;
        }

        FormService::clearCache();

        return true;
    }

    /** Копия формы: выключена, с новым кодом. @param int[] $pks */
    public function duplicate(array &$pks): bool
    {
        $table = $this->getTable();

        foreach ($pks as $pk) {
            if (!$table->load((int) $pk)) {
                continue;
            }

            $table->id       = null;
            $table->code     = substr((string) $table->code, 0, 55) . '_copy';
            $table->title    = Text::sprintf('COM_DHARMAFORM_FORM_COPY_TITLE', $table->title);
            $table->state    = 0;
            $table->ordering = 0;
            $table->created  = Factory::getDate()->toSql();
            $table->modified = null;

            if (!$table->check() || !$table->store()) {
                $this->setError($table->getError());

                return false;
            }
        }

        FormService::clearCache();

        return true;
    }
}
