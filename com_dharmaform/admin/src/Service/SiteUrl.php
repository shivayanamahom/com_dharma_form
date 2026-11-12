<?php

/**
 * Dharma Form — универсальные формы для Joomla 5.2+ и 6+.
 *
 * @package    com_dharmaform
 * @copyright  (C) 2026 Dharma. Все права защищены.
 * @license    GNU General Public License версии 2 или новее; см. LICENSE.txt
 */

declare(strict_types=1);

namespace Dharma\Component\DharmaForm\Administrator\Service;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Table\Table;
use Joomla\CMS\Uri\Uri;
use Joomla\Registry\Registry;

\defined('_JEXEC') or die;

/**
 * Адрес сайта для ссылок в уведомлениях («Открыть в админке», ссылка на товар) и для проверки «своего» адреса страницы.
 * Берётся не из запроса: заголовок Host посетитель подделывает сам, и сотрудники получили бы письмо со ссылкой на чужой сайт.
 * Порядок: `live_site` из конфигурации Joomla; адрес из настроек компонента; адрес, с которого сотрудник первый раз открыл
 * админку компонента (запоминается один раз, `remember`).
 */
final class SiteUrl
{
    public static function base(): string
    {
        $live = trim((string) Factory::getApplication()->get('live_site'));

        if ($live !== '') {
            return rtrim($live, '/');
        }

        $saved = self::valid((string) ComponentHelper::getParams('com_dharmaform')->get('site_url', ''));

        return $saved !== '' ? $saved : rtrim(Uri::root(), '/');
    }

    public static function host(): string
    {
        return strtolower((string) preg_replace('/^www\./i', '', (string) (parse_url(self::base(), PHP_URL_HOST) ?: '')));
    }

    /** Запоминает адрес сайта из запроса сотрудника админки, если его ещё нет ни в `live_site`, ни в настройках. */
    public static function remember(): void
    {
        $app = Factory::getApplication();

        if (!$app->isClient('administrator') || trim((string) $app->get('live_site')) !== '' || self::valid((string) ComponentHelper::getParams('com_dharmaform')->get('site_url', '')) !== '') {
            return;
        }

        $url = self::valid(rtrim(Uri::root(), '/'));

        if ($url === '' || !$app->getIdentity()->authorise('core.manage', 'com_dharmaform')) {
            return;
        }

        try {
            $table = Table::getInstance('extension');

            if ($table->load(['element' => 'com_dharmaform', 'type' => 'component'])) {
                $params = new Registry($table->params);
                $params->set('site_url', $url);
                $table->params = $params->toString();
                $table->store();
                ComponentHelper::getParams('com_dharmaform')->set('site_url', $url);
            }
        } catch (\Throwable) {
            // Без запомненного адреса ссылки строятся по запросу, как раньше.
        }
    }

    private static function valid(string $url): string
    {
        $url = rtrim(trim($url), '/');

        return preg_match('~^https?://[A-Za-z0-9.-]+(?::\d{1,5})?(?:/[^\s"<>]*)?$~', $url) ? $url : '';
    }
}
