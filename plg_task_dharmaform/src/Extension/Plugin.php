<?php

/**
 * Dharma Form — универсальные формы для Joomla 5.2+ и 6+.
 *
 * @package    pkg_dharma_form
 * @copyright  (C) 2026 Dharma. Все права защищены.
 * @license    GNU General Public License версии 2 или новее; см. LICENSE.txt
 */

declare(strict_types=1);

namespace Dharma\Plugin\Task\DharmaForm\Extension;

use Dharma\Component\DharmaForm\Administrator\Notification\SubmissionNotificationService;
use Dharma\Component\DharmaForm\Administrator\Service\SubmissionRetentionService;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Component\Scheduler\Administrator\Event\ExecuteTaskEvent;
use Joomla\Component\Scheduler\Administrator\Task\Status;
use Joomla\Component\Scheduler\Administrator\Traits\TaskPluginTrait;
use Joomla\Event\SubscriberInterface;

\defined('_JEXEC') or die;

/**
 * Задачи планировщика Joomla: повтор неудавшихся уведомлений о заявках и очистка заявок (с файлами) по сроку хранения.
 */
final class Plugin extends CMSPlugin implements SubscriberInterface
{
    use TaskPluginTrait;

    protected $autoloadLanguage = true;

    protected const TASKS_MAP = [
        'dharmaform.notify.retry' => [
            'langConstPrefix' => 'PLG_TASK_DHARMAFORM_NOTIFY_RETRY',
            'method'          => 'retryNotifications',
        ],
        'dharmaform.submissions.purge' => [
            'langConstPrefix' => 'PLG_TASK_DHARMAFORM_SUBMISSIONS_PURGE',
            'method'          => 'purgeSubmissions',
        ],
    ];

    public static function getSubscribedEvents(): array
    {
        return [
            'onTaskOptionsList'    => 'advertiseRoutines',
            'onExecuteTask'        => 'standardRoutineHandler',
            'onContentPrepareForm' => 'enhanceTaskItemForm',
        ];
    }

    protected function retryNotifications(ExecuteTaskEvent $event): int
    {
        try {
            $count = SubmissionNotificationService::fromContainer()->retryDue();
            $this->logTask('Dharma Form: retried notifications, submissions processed: ' . $count);

            return Status::OK;
        } catch (\Throwable $e) {
            $this->logTask('Dharma Form: notification retry failed: ' . $e::class, 'error');

            return Status::KNOCKOUT;
        }
    }

    protected function purgeSubmissions(ExecuteTaskEvent $event): int
    {
        try {
            $count = SubmissionRetentionService::fromContainer()->purgeExpired();
            $this->logTask('Dharma Form: expired submissions purged: ' . $count);

            return Status::OK;
        } catch (\Throwable $e) {
            $this->logTask('Dharma Form: purge failed: ' . $e::class, 'error');

            return Status::KNOCKOUT;
        }
    }
}
