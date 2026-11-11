<?php

/**
 * Dharma Form — универсальные формы для Joomla 5.2+ и 6+.
 *
 * @package    pkg_dharma_form
 * @copyright  (C) 2026 Dharma. Все права защищены.
 * @license    GNU General Public License версии 2 или новее; см. LICENSE.txt
 */

declare(strict_types=1);

namespace Dharma\Plugin\System\DharmaForm\Extension;

use Dharma\Component\DharmaForm\Site\Helper\FormAssets;
use Dharma\Component\DharmaForm\Site\Service\FormService;
use Joomla\CMS\Document\HtmlDocument;
use Joomla\CMS\Layout\LayoutHelper;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Event\Event;
use Joomla\Event\SubscriberInterface;

\defined('_JEXEC') or die;

/**
 * Формы на любой странице без модулей: подключает скрипт, если у опубликованных форм есть класс-открыватель или
 * автопоявление для этой страницы, и заменяет шорткод `{dharmaform код}` в материалах на форму.
 */
final class DharmaForm extends CMSPlugin implements SubscriberInterface
{
    protected $allowLegacyListeners = false;

    public static function getSubscribedEvents(): array
    {
        return ['onBeforeCompileHead' => 'onBeforeCompileHead', 'onContentPrepare' => 'onContentPrepare'];
    }

    public function onBeforeCompileHead(Event $event): void
    {
        $app = $this->getApplication();

        if (!$app->isClient('site') || !$app->getDocument() instanceof HtmlDocument || $app->getInput()->getCmd('format', 'html') !== 'html') {
            return;
        }

        $service = new FormService();

        if ($service->triggers() || $service->autoPopups(FormAssets::currentPath())) {
            FormAssets::enable();
        }
    }

    public function onContentPrepare(Event $event): void
    {
        if (!$this->getApplication()->isClient('site')) {
            return;
        }

        $item = method_exists($event, 'getItem') ? $event->getItem() : $event->getArgument('1');

        if (!\is_object($item) || !isset($item->text) || !\is_string($item->text) || !str_contains($item->text, '{dharmaform')) {
            return;
        }

        $item->text = (string) preg_replace_callback('~\{dharmaform\s+([A-Za-z0-9._-]+)\s*\}~', static function (array $m): string {
            $form = (new FormService())->byCode($m[1]);

            if ($form === null) {
                return '';
            }

            FormAssets::enable();

            return LayoutHelper::render('dharmaform.form', ['form' => $form, 'inline' => true, 'title' => (string) $form['params']['modal_title']], JPATH_SITE . '/components/com_dharmaform/layouts');
        }, $item->text);
    }
}
