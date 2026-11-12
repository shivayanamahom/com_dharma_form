<?php

/**
 * Dharma Form — универсальные формы для Joomla 5.2+ и 6+.
 *
 * @package    pkg_dharma_form
 * @copyright  (C) 2026 Dharma. Все права защищены.
 * @license    GNU General Public License версии 2 или новее; см. LICENSE.txt
 */

declare(strict_types=1);

namespace Dharma\Plugin\DharmaShop\DharmaForm\Extension;

use Dharma\Component\DharmaForm\Administrator\Service\SiteUrl;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Uri\Uri;
use Joomla\Database\DatabaseInterface;
use Joomla\Event\EventInterface;
use Joomla\Event\SubscriberInterface;

\defined('_JEXEC') or die;

/**
 * Интеграция Dharma Form с Dharma Shop: когда у формы включён переключатель «Подцеплять товар Dharma Shop», кнопка с классом
 * формы внутри карточки товара (или на странице товара) открывает форму с товаром: название, артикул, цена и ссылка попадают
 * в заявку. Плагин только находит товар по номеру в базе магазина; кнопку вставляет сам вёрстщик.
 */
final class DharmaForm extends CMSPlugin implements SubscriberInterface
{
    protected $allowLegacyListeners = false;

    protected $autoloadLanguage = true;

    public static function getSubscribedEvents(): array
    {
        return ['onDharmaFormContextResolve' => 'onResolve'];
    }

    /** Ищет товар для формы: название, артикул, цена, ссылка и картинка берутся из базы магазина, а не из запроса. */
    public function onResolve(EventInterface $event): void
    {
        if ($event->getArgument('integration') !== 'dharmashop' || $event->getArgument('entity') !== 'product') {
            return;
        }

        $db  = Factory::getContainer()->get(DatabaseInterface::class);
        $id  = (int) $event->getArgument('id');
        $row = $db->setQuery(
            'SELECT id, catid, title, cover, price_min, price_max, variants_count FROM ' . $db->quoteName('#__dharmashop_products') . ' WHERE id = ' . $id . ' AND state = 1'
        )->loadObject();

        if (!$row) {
            return;
        }

        $sku = (int) $row->variants_count === 1
            ? (string) $db->setQuery('SELECT sku FROM ' . $db->quoteName('#__dharmashop_variants') . ' WHERE product_id = ' . $id . ' AND state = 1 ORDER BY is_default DESC, ordering LIMIT 1')->loadResult()
            : '';

        $price = $this->price($row->price_min, $row->price_max);
        $url   = SiteUrl::base() . Route::_('index.php?option=com_dharmashop&view=product&id=' . $id . '&catid=' . (int) $row->catid, false);
        $cover = trim((string) preg_replace('~#.*$~', '', (string) $row->cover));
        $lines = [Text::_('PLG_DHARMASHOP_DHARMAFORM_LINE_PRODUCT') => $row->title . ($sku !== '' ? ' (' . Text::_('PLG_DHARMASHOP_DHARMAFORM_SKU') . ' ' . $sku . ')' : '')];

        if ($price !== '') {
            $lines[Text::_('PLG_DHARMASHOP_DHARMAFORM_LINE_PRICE')] = $price;
        }

        $lines[Text::_('PLG_DHARMASHOP_DHARMAFORM_LINE_URL')] = $url;

        $event->setArgument('context', [
            'label' => Text::_('PLG_DHARMASHOP_DHARMAFORM_LINE_PRODUCT'),
            'title' => (string) $row->title,
            'url'   => $url,
            'image' => $cover !== '' ? (preg_match('~^https?://~i', $cover) ? $cover : SiteUrl::base() . '/' . ltrim($cover, '/')) : '',
            'price' => $price,
            'lines' => $lines,
        ]);
    }

    /** Цена так, как её показывает магазин (валюта и знаки из его настроек); нулевая цена не показывается. */
    private function price(mixed $min, mixed $max): string
    {
        if ((float) $min <= 0.0 && (float) $max <= 0.0) {
            return '';
        }

        $formatter = '\\Dharma\\Component\\DharmaShop\\Administrator\\Pricing\\MoneyFormatter';

        if (class_exists($formatter)) {
            return (string) (new $formatter(ComponentHelper::getParams('com_dharmashop')))->range($min, $max);
        }

        return number_format((float) $min, 0, ',', "\u{00A0}");
    }
}
