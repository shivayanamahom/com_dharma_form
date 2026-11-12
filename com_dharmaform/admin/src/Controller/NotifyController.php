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

use Dharma\Component\DharmaForm\Administrator\Notification\SubmissionNotificationService;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Response\JsonResponse;
use Joomla\CMS\Session\Session;

\defined('_JEXEC') or die;

/** Пробная отправка уведомления по каналу (кнопка «Отправить тест» в настройках компонента). */
class NotifyController extends BaseController
{
    public function test(): void
    {
        $this->app->setHeader('Cache-Control', 'no-store', true);

        if (!Session::checkToken('get') || !$this->app->getIdentity()->authorise('core.admin', 'com_dharmaform')) {
            echo new JsonResponse(null, Text::_('JINVALID_TOKEN_NOTICE'), true);
            $this->app->close();
        }

        $channel = $this->input->getCmd('channel', '');

        if (!\in_array($channel, ['email', 'telegram', 'max'], true)) {
            echo new JsonResponse(null, Text::_('JERROR_AN_ERROR_HAS_OCCURRED'), true);
            $this->app->close();
        }

        $results = SubmissionNotificationService::fromContainer()->test($channel);
        $items   = [];

        foreach ($results as $to => $code) {
            $items[] = ['to' => str_contains((string) $to, ':') ? substr((string) $to, (int) strpos((string) $to, ':') + 1) : '', 'ok' => $code === 'ok', 'text' => $this->explain($channel, (string) $code)];
        }

        if (!$items) {
            $items[] = ['to' => '', 'ok' => false, 'text' => Text::_('COM_DHARMAFORM_TEST_NO_PLUGIN')];
        }

        echo new JsonResponse(['items' => $items]);
        $this->app->close();
    }

    /** Код ошибки канала — понятная причина и подсказка. */
    private function explain(string $channel, string $code): string
    {
        if ($code === 'ok') {
            return Text::_('COM_DHARMAFORM_TEST_OK');
        }

        $key = match (true) {
            str_ends_with($code, '_no_token')         => 'COM_DHARMAFORM_TEST_NO_TOKEN',
            str_ends_with($code, '_no_chats')         => 'COM_DHARMAFORM_TEST_NO_CHATS',
            $code === 'email_no_recipients'           => 'COM_DHARMAFORM_TEST_NO_RECIPIENTS',
            (bool) preg_match('~_api_(401|404)$~', $code) => 'COM_DHARMAFORM_TEST_BAD_TOKEN',
            (bool) preg_match('~_api_400$~', $code)   => 'COM_DHARMAFORM_TEST_BAD_CHAT',
            (bool) preg_match('~_api_403$~', $code)   => 'COM_DHARMAFORM_TEST_FORBIDDEN',
            (bool) preg_match('~_api_\d+$|_invalid_response$~', $code) => 'COM_DHARMAFORM_TEST_API_ERROR',
            $channel === 'email'                      => 'COM_DHARMAFORM_TEST_MAIL_ERROR',
            default                                   => 'COM_DHARMAFORM_TEST_NETWORK',
        };

        return Text::sprintf($key, $code);
    }
}
