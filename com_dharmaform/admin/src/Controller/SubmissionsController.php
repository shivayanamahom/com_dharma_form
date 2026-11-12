<?php

/**
 * Dharma Form — универсальные формы для Joomla 5.2+ и 6+.
 *
 * @package    com_dharmaform
 * @copyright  (C) 2026 Dharma. Все права защищены.
 * @license    GNU General Public License версии 2 или новее; см. LICENSE.txt
 */

declare(strict_types=1);

namespace Dharma\Component\DharmaForm\Administrator\Controller;

use Dharma\Component\DharmaForm\Administrator\Helper\VisitorMeta;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\AdminController;
use Joomla\CMS\Router\Route;

\defined('_JEXEC') or die;

class SubmissionsController extends AdminController
{
    protected $option = 'com_dharmaform';

    protected $text_prefix = 'COM_DHARMAFORM_SUBMISSIONS';

    public function getModel($name = 'Submission', $prefix = 'Administrator', $config = ['ignore_request' => true])
    {
        return parent::getModel($name, $prefix, $config);
    }

    public function read(): void
    {
        $this->flag('is_read', 1);
    }

    public function unread(): void
    {
        $this->flag('is_read', 0);
    }

    public function spam(): void
    {
        $this->flag('is_spam', 1);
    }

    public function notspam(): void
    {
        $this->flag('is_spam', 0);
    }

    private function flag(string $column, int $value): void
    {
        $this->checkToken();

        $pks = array_filter(array_map('intval', (array) $this->input->get('cid', [], 'array')));

        if (!$pks) {
            $this->setMessage(Text::_('JGLOBAL_NO_ITEM_SELECTED'), 'warning');
        } elseif ($this->app->getIdentity()->authorise('core.edit.state', 'com_dharmaform') && $this->getModel()->setFlag($pks, $column, $value)) {
            $this->setMessage(Text::plural('COM_DHARMAFORM_SUBMISSIONS_N_ITEMS_UPDATED', \count($pks)));
        } else {
            $this->setMessage(Text::_('JLIB_APPLICATION_ERROR_EDITSTATE_NOT_PERMITTED'), 'error');
        }

        $this->setRedirect(Route::_('index.php?option=com_dharmaform&view=submissions', false));
    }

    /** CSV с текущими фильтрами списка: UTF-8 с BOM, разделитель `;`, колонки по подписям полей форм. */
    public function export(): void
    {
        $this->checkToken();

        $user = $this->app->getIdentity();

        if (!$user->authorise('submission.export', 'com_dharmaform')) {
            throw new \RuntimeException(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }

        /** @var \Dharma\Component\DharmaForm\Administrator\Model\SubmissionsModel $model */
        $model = $this->getModel('Submissions', 'Administrator', ['ignore_request' => false]);
        $db    = $model->getExportDatabase();
        $query = $model->getExportQuery();

        $query->clear('select')->select($db->quoteName(['a.id', 'a.created', 'a.name', 'a.phone', 'a.email', 'a.message', 'a.fields', 'a.page_url', 'a.is_spam', 'a.spam_score', 'a.spam_reason', 'a.meta']))
            ->select($db->quoteName('f.title', 'form_title'));

        $decode = static fn (mixed $json): array => \is_string($json) && $json !== '' ? (json_decode($json, true) ?: []) : [];
        $extra  = static function (object $row) use ($decode): array {
            $known = [(string) $row->name, (string) $row->phone, (string) $row->email, (string) $row->message];

            return array_filter($decode($row->fields), static fn ($v, $k): bool => \is_scalar($v) && !\in_array((string) $v, $known, true), ARRAY_FILTER_USE_BOTH);
        };

        // Первый проход: набор колонок по подписям полей.
        $labels = [];

        foreach ($db->setQuery($query)->getIterator() as $row) {
            foreach (array_keys($extra($row)) as $label) {
                $labels[(string) $label] = true;
            }
        }

        $labels = array_keys($labels);

        $this->app->clearHeaders();
        $this->app->setHeader('Content-Type', 'text/csv; charset=utf-8', true);
        $this->app->setHeader('Content-Disposition', 'attachment; filename="submissions-' . date('Y-m-d') . '.csv"', true);
        $this->app->setHeader('Cache-Control', 'private, no-store', true);
        $this->app->sendHeaders();

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        // Ячейка, начинающаяся с «=», «+», «-», «@», в таблице стала бы формулой: перед ней ставится апостроф.
        $cell = static function (mixed $value): string {
            $text = str_replace(["\r\n", "\r"], "\n", (string) $value);

            return $text !== '' && strpbrk($text[0], "=+-@\t") !== false ? "'" . $text : $text;
        };

        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, array_merge(
            [Text::_('COM_DHARMAFORM_CSV_ID'), Text::_('COM_DHARMAFORM_CSV_CREATED'), Text::_('COM_DHARMAFORM_CSV_FORM'), Text::_('COM_DHARMAFORM_CSV_NAME'), Text::_('COM_DHARMAFORM_CSV_PHONE'), Text::_('COM_DHARMAFORM_CSV_EMAIL'), Text::_('COM_DHARMAFORM_CSV_MESSAGE')],
            array_map($cell, $labels),
            [Text::_('COM_DHARMAFORM_CSV_PAGE'), Text::_('COM_DHARMAFORM_CSV_SPAM'), Text::_('COM_DHARMAFORM_CSV_SPAM_SCORE'), Text::_('COM_DHARMAFORM_CSV_SPAM_REASON'), Text::_('COM_DHARMAFORM_CSV_VISITOR')]
        ), ';', '"', '\\');

        foreach ($db->setQuery($query)->getIterator() as $row) {
            $values = $extra($row);
            $line   = [(int) $row->id, $row->created, $cell((string) $row->form_title), $cell($row->name), $cell($row->phone), $cell($row->email), $cell($row->message)];

            foreach ($labels as $label) {
                $line[] = $cell($values[$label] ?? '');
            }

            $line[] = $cell($row->page_url);
            $line[] = $row->is_spam ? Text::_('JYES') : Text::_('JNO');
            $line[] = (int) $row->spam_score;
            $line[] = $cell($row->spam_reason);
            $pairs  = VisitorMeta::pairs($decode($row->meta));
            $line[] = $cell(implode('; ', array_map(static fn (string $k, string $v): string => $k . ': ' . $v, array_keys($pairs), $pairs)));
            fputcsv($out, $line, ';', '"', '\\');
        }

        fclose($out);
        $this->app->close();
    }
}
