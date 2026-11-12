<?php

/**
 * Dharma Form — универсальные формы для Joomla 5.2+ и 6+.
 *
 * @package    com_dharmaform
 * @copyright  (C) 2026 Dharma. Все права защищены.
 * @license    GNU General Public License версии 2 или новее; см. LICENSE.txt
 */

declare(strict_types=1);

namespace Dharma\Component\DharmaForm\Site\Controller;

use Dharma\Component\DharmaForm\Administrator\Forms\FormSanitizer;
use Dharma\Component\DharmaForm\Administrator\Notification\SubmissionNotificationService;
use Dharma\Component\DharmaForm\Administrator\Service\SubmissionRetentionService;
use Dharma\Component\DharmaForm\Site\Helper\FormMarkup;
use Dharma\Component\DharmaForm\Site\Service\ClientIp;
use Dharma\Component\DharmaForm\Site\Service\FormService;
use Dharma\Component\DharmaForm\Site\Service\FormSubmission;
use Dharma\Component\DharmaForm\Site\Service\SubmissionGuard;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Layout\LayoutHelper;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Response\JsonResponse;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Uri\Uri;

\defined('_JEXEC') or die;

/**
 * `task=form.render` отдаёт разметку формы для окна (JSON): токен и метка времени всегда свежие, страница с кнопкой может
 * лежать в кэше. `task=form.submit` принимает отправку: JSON для скрипта, обычный переход с сообщением — без JS.
 * Ответ уходит посетителю раньше рассылки уведомлений: он не ждёт почту и мессенджеры.
 */
class FormController extends BaseController
{
    protected $option = 'com_dharmaform';

    public function render(): void
    {
        $form = (new FormService())->byCode($this->app->getInput()->get->getString('code', ''));

        if (!$form) {
            $this->json(false, Text::_('COM_DHARMAFORM_FORM_NOT_FOUND'));
        }

        $params = $form['params'];

        $this->json(true, '', [
            'html'  => LayoutHelper::render('dharmaform.form', ['form' => $form, 'ctx' => $this->app->getInput()->get->getString('ctx', '')], JPATH_SITE . '/components/com_dharmaform/layouts'),
            'title' => trim((string) $params['modal_title']) !== '' ? (string) $params['modal_title'] : (string) $form['title'],
            'code'  => $form['code'],
        ]);
    }

    public function submit(): void
    {
        $input = $this->app->getInput();
        $ajax  = $input->getCmd('format', '') === 'json';

        if (!Session::checkToken('post')) {
            $this->respond($ajax, false, Text::_('JINVALID_TOKEN_NOTICE'), [], [], '');
        }

        $form = (new FormService())->byCode($input->post->getString('form', ''));

        if (!$form) {
            $this->respond($ajax, false, Text::_('COM_DHARMAFORM_FORM_NOT_FOUND'), [], [], '');
        }

        $post = [
            'f'        => (array) $input->post->get('f', [], 'array'),
            'files'    => (array) $input->files->get('f', [], 'array'),
            'utm'      => (array) $input->post->get('utm', [], 'array'),
            'stamp'    => $input->post->getString('stamp', ''),
            'website'  => $input->post->getString('website', ''),
            'page_url' => $input->post->getString('page_url', ''),
            'pow'      => $input->post->getString('pow', ''),
            'dfh'      => $input->post->getString('dfh', ''),
            'env'      => $input->post->getString('env', ''),
            'ctx'      => $input->post->getString('ctx', ''),
            'server'   => ['ua' => $input->server->getString('HTTP_USER_AGENT', ''), 'lang' => $input->server->getString('HTTP_ACCEPT_LANGUAGE', '')],
        ];
        $trap = SubmissionGuard::honeypotName($post['stamp']);

        $post[$trap] = $input->post->getString($trap, '');

        $result = (new FormSubmission())->submit($form, $post, ClientIp::get($input->server));
        $params = $form['params'];

        if (!$result['ok']) {
            $this->respond($ajax, false, $result['error'], $result['errors'], [], $post['page_url']);
        }

        $redirect = $params['after_action'] === 'redirect' ? FormSanitizer::url((string) $params['success_redirect']) : '';
        $message  = trim((string) $params['success_message']) !== '' ? FormMarkup::html((string) $params['success_message']) : '<p>' . Text::_('COM_DHARMAFORM_FORM_THANKS') . '</p>';

        $this->respond($ajax, true, '', [], [
            'submission_id' => (int) $result['submission_id'],
            'message'       => $message,
            'redirect'      => $redirect,
            'delay'         => max(0, (int) $params['after_delay']),
            'close'         => (bool) $params['close_modal'],
            'goal'          => (string) $params['goal_name'],
            'counter'       => (string) $params['metrika_counter'],
            'code'          => $form['code'],
        ], $post['page_url']);
    }

    /**
     * Ответ на отправку: JSON для скрипта, переход с сообщением для работы без JS.
     *
     * @param array<string, string> $errors ошибки по полям
     * @param array<string, mixed>  $data
     */
    private function respond(bool $ajax, bool $ok, string $message, array $errors, array $data, string $pageUrl): never
    {
        $id = (int) ($data['submission_id'] ?? 0);
        unset($data['submission_id']);

        if ($ajax) {
            $this->json($ok, $message, $ok ? $data : ['errors' => $errors], $id);
        }

        if ($ok) {
            $this->app->enqueueMessage(strip_tags((string) ($data['message'] ?? '')), 'success');
        } else {
            foreach ($errors ?: [$message] as $text) {
                $this->app->enqueueMessage((string) $text, 'warning');
            }
        }

        if ($id > 0) {
            SubmissionNotificationService::fromContainer()->notify($id);
            SubmissionRetentionService::purgeIfDue();
        }

        $safe = FormSanitizer::pageUrl($pageUrl);
        $url  = $safe !== '' && Uri::isInternal($safe) ? $safe : Route::_('index.php', false);
        $this->setRedirect($url);
        $this->redirect();
        $this->app->close();
    }

    /**
     * Ответ JSON. Уведомления о новой заявке (`$id`) рассылаются уже после ответа.
     *
     * @param array<string, mixed> $data
     */
    private function json(bool $ok, string $message, array $data = [], int $id = 0): never
    {
        $this->app->setHeader('Cache-Control', 'no-store', true);
        echo new JsonResponse($data, $message !== '' ? $message : null, !$ok);

        if ($id > 0) {
            ignore_user_abort(true);

            while (ob_get_level() > 0) {
                ob_end_flush();
            }

            if (\function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            } else {
                flush();
            }

            SubmissionNotificationService::fromContainer()->notify($id);
            SubmissionRetentionService::purgeIfDue();
        }

        $this->app->close();
    }
}
