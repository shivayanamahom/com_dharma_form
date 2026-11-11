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

use Dharma\Component\DharmaForm\Site\Service\FileUploader;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Router\Route;

\defined('_JEXEC') or die;

/** Одна заявка: открыть, пометить, скачать приложенный файл. */
class SubmissionController extends BaseController
{
    protected $option = 'com_dharmaform';

    /** Ссылка из уведомления (`task=submission.edit&id=`) открывает просмотр заявки. */
    public function edit($key = null, $urlVar = null)
    {
        $this->setRedirect(Route::_('index.php?option=com_dharmaform&view=submission&id=' . $this->input->getInt('id'), false));

        return true;
    }

    public function cancel($key = null)
    {
        $this->setRedirect(Route::_('index.php?option=com_dharmaform&view=submissions', false));

        return true;
    }

    public function spam(): void
    {
        $this->flag('is_spam', 1);
    }

    public function notspam(): void
    {
        $this->flag('is_spam', 0);
    }

    public function unread(): void
    {
        $this->flag('is_read', 0);
    }

    private function flag(string $column, int $value): void
    {
        $this->checkToken();

        $id = $this->input->getInt('id');

        if ($this->app->getIdentity()->authorise('core.edit.state', 'com_dharmaform') && $this->getModel()->setFlag([$id], $column, $value)) {
            $this->setMessage(Text::_('COM_DHARMAFORM_SUBMISSION_UPDATED'));
        } else {
            $this->setMessage(Text::_('JLIB_APPLICATION_ERROR_EDITSTATE_NOT_PERMITTED'), 'error');
        }

        $this->setRedirect(Route::_('index.php?option=com_dharmaform&view=' . ($column === 'is_read' ? 'submissions' : 'submission&id=' . $id), false));
    }

    /** Скачивание файла заявки: только сотрудникам с правом просмотра; файл отдаётся вложением, без исполнения в браузере. */
    public function download(): void
    {
        $user = $this->app->getIdentity();

        if (!$user->authorise('core.manage', 'com_dharmaform') || !$user->authorise('submission.view', 'com_dharmaform')) {
            throw new \RuntimeException(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }

        $item = $this->getModel()->getItem($this->input->getInt('id'));
        $file = $item ? ($item->files[$this->input->getInt('n')] ?? null) : null;
        $path = \is_array($file) ? FileUploader::resolve((string) ($file['path'] ?? '')) : null;

        if ($path === null || !is_file($path)) {
            throw new \RuntimeException(Text::_('COM_DHARMAFORM_SUBMISSION_FILE_MISSING'), 404);
        }

        $name = (string) preg_replace('~[^\p{L}\p{N}._ -]+~u', '_', basename((string) ($file['name'] ?? 'file')));
        $name = $name !== '' ? $name : 'file';

        $this->app->clearHeaders();
        $this->app->setHeader('Content-Type', 'application/octet-stream', true);
        $this->app->setHeader('Content-Disposition', 'attachment; filename="' . addcslashes($name, '"\\') . '"; filename*=UTF-8\'\'' . rawurlencode($name), true);
        $this->app->setHeader('Content-Length', (string) filesize($path), true);
        $this->app->setHeader('X-Content-Type-Options', 'nosniff', true);
        $this->app->setHeader('Cache-Control', 'private, no-store', true);
        $this->app->sendHeaders();

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        readfile($path);
        $this->app->close();
    }
}
