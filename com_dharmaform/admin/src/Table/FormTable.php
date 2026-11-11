<?php

/**
 * Dharma Form — универсальные формы для Joomla 5.2+ и 6+.
 *
 * @package    com_dharmaform
 * @copyright  (C) 2026 Dharma. Все права защищены.
 * @license    GNU General Public License версии 2 или новее; см. LICENSE.txt
 */

declare(strict_types=1);

namespace Dharma\Component\DharmaForm\Administrator\Table;

use Joomla\CMS\Application\ApplicationHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Table\Table;
use Joomla\Database\DatabaseInterface;
use Joomla\Event\DispatcherInterface;

\defined('_JEXEC') or die;

/** Форма сайта: поля (JSON в `fields`) и настройки (JSON в `params`). */
class FormTable extends Table
{
    protected $_supportNullValue = true;

    public function __construct(DatabaseInterface $db, ?DispatcherInterface $dispatcher = null)
    {
        $this->typeAlias = 'com_dharmaform.form';
        $this->setColumnAlias('published', 'state');

        parent::__construct('#__dharmaform_forms', 'id', $db, $dispatcher);
    }

    public function check()
    {
        $this->title = trim((string) $this->title);

        if ($this->title === '') {
            $this->setError(Text::_('COM_DHARMAFORM_FORM_ERROR_TITLE'));

            return false;
        }

        $code = (string) preg_replace('~[^A-Za-z0-9._-]+~', '', trim((string) $this->code));

        if ($code === '') {
            $code = strtolower(str_replace('-', '_', ApplicationHelper::stringURLSafe($this->title))) ?: 'form';
        }

        $base = substr($code, 0, 58);
        $code = substr($code, 0, 64);

        for ($i = 2; $this->codeTaken($code); $i++) {
            $code = $base . '_' . $i;
        }

        $this->code = $code;

        if (empty($this->access)) {
            $this->access = 1;
        }

        return true;
    }

    private function codeTaken(string $code): bool
    {
        $db    = $this->getDbo();
        $id    = (int) $this->id;
        $query = $db->createQuery()
            ->select('COUNT(*)')
            ->from($db->quoteName('#__dharmaform_forms'))
            ->where($db->quoteName('code') . ' = :code')
            ->where($db->quoteName('id') . ' <> :id')
            ->bind(':code', $code)
            ->bind(':id', $id, \Joomla\Database\ParameterType::INTEGER);

        return (int) $db->setQuery($query)->loadResult() > 0;
    }
}
