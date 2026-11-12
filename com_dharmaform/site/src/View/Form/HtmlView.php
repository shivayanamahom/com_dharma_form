<?php

/**
 * Dharma Form — универсальные формы для Joomla 5.2+ и 6+.
 *
 * @package    com_dharmaform
 * @copyright  (C) 2026 Dharma. Все права защищены.
 * @license    GNU General Public License версии 2 или новее; см. LICENSE.txt
 */

declare(strict_types=1);

namespace Dharma\Component\DharmaForm\Site\View\Form;

use Dharma\Component\DharmaForm\Site\Service\FormService;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;

\defined('_JEXEC') or die;

/** Страница формы: пункт меню «Форма» или ссылка `view=form&code=…` (куда ведёт кнопка без JS). */
class HtmlView extends BaseHtmlView
{
    /** @var array<string, mixed> */
    protected array $form = [];

    protected $params;

    protected string $ctx = '';

    public function display($tpl = null): void
    {
        $app  = Factory::getApplication();
        $code = trim((string) $app->getInput()->getString('code', ''));
        $this->params = $app->getParams();

        if ($code === '') {
            $code = (string) $this->params->get('code', '');
        }

        $form = (new FormService())->byCode($code);

        if ($form === null) {
            throw new \Exception(Text::_('COM_DHARMAFORM_FORM_NOT_FOUND'), 404);
        }

        $this->form = $form;
        $this->ctx  = $app->getInput()->getString('ctx', '');

        $document = $this->getDocument();

        if (!$this->params->get('page_title')) {
            $document->setTitle((string) $form['title']);
        }

        parent::display($tpl);
    }
}
