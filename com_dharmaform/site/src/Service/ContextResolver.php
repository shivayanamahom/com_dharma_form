<?php

/**
 * Dharma Form — универсальные формы для Joomla 5.2+ и 6+.
 *
 * @package    com_dharmaform
 * @copyright  (C) 2026 Dharma. Все права защищены.
 * @license    GNU General Public License версии 2 или новее; см. LICENSE.txt
 */

declare(strict_types=1);

namespace Dharma\Component\DharmaForm\Site\Service;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\Event\Event;

\defined('_JEXEC') or die;

/**
 * Контекст формы: к какому объекту другого компонента (товару магазина) относится открытая форма. Ссылка вида
 * `интеграция.сущность:номер` (например `dharmashop.product:205`) приходит от кнопки и при показе формы, и при отправке;
 * оба раза объект ищется заново, поэтому подменить цену или название в запросе нельзя. Объект ищет плагин интеграции
 * (событие `onDharmaFormContextResolve` в группе плагинов своей экосистемы); включается переключателем в настройках формы.
 */
final class ContextResolver
{
    /** Интеграция => группа плагинов, в которой лежит её плагин. */
    public const INTEGRATIONS = ['dharmashop' => 'dharmashop'];

    /**
     * Как скрипт находит объект в вёрстке: ближайший к кнопке контейнер (`container`: карточка, страница объекта), внутри
     * него (или он сам) первый элемент с номером в одном из атрибутов (`attrs`); для автопоказа и кнопок вне карточки —
     * элемент страницы объекта (`page`). Включается переключателем `context_<интеграция>` в форме.
     */
    public const RULES = [
        'dharmashop' => [
            'entity'    => 'product',
            'component' => 'com_dharmashop',
            'container' => '.dharmaCard, .dharmaProduct, [data-product-id], [data-product]',
            'attrs'     => ['data-product-id', 'data-product', 'data-dharmashop-add-to-cart'],
            'page'      => '.dharmaProduct[data-product-id]',
        ],
    ];

    /**
     * Правила привязки для формы: только включённые её переключателями и только для установленных компонентов.
     *
     * @param array<string, mixed> $params настройки формы
     *
     * @return array<int, array{integration: string, entity: string, container: string, attrs: string[], page: string}>
     */
    public static function rulesFor(array $params): array
    {
        $out = [];

        foreach (self::RULES as $integration => $rule) {
            if (!empty($params['context_' . $integration]) && ComponentHelper::isEnabled($rule['component'])) {
                $out[] = ['integration' => $integration, 'entity' => $rule['entity'], 'container' => $rule['container'], 'attrs' => $rule['attrs'], 'page' => $rule['page']];
            }
        }

        return $out;
    }

    /** @var array<string, array<string, mixed>|null> */
    private static array $memo = [];

    /**
     * @param array<string, mixed> $params настройки формы: объект ищется, только если у формы включён переключатель интеграции
     *
     * @return array{label: string, title: string, url: string, image: string, price: string, lines: array<string, string>}|null
     */
    public static function resolve(string $ref, array $params): ?array
    {
        $key = $ref . '|' . (int) !empty($params['context_dharmashop']);

        if (\array_key_exists($key, self::$memo)) {
            return self::$memo[$key];
        }

        self::$memo[$key] = null;

        if (!preg_match('~^([a-z0-9]+)\.([a-z0-9_]+):(\d{1,10})$~', $ref, $m) || !isset(self::INTEGRATIONS[$m[1]])) {
            return null;
        }

        if (empty($params['context_' . $m[1]]) || !ComponentHelper::isEnabled(self::RULES[$m[1]]['component'] ?? '')) {
            return null;
        }

        PluginHelper::importPlugin(self::INTEGRATIONS[$m[1]]);

        $event = new Event('onDharmaFormContextResolve', ['integration' => $m[1], 'entity' => $m[2], 'id' => (int) $m[3], 'context' => null]);
        \Joomla\CMS\Factory::getApplication()->getDispatcher()->dispatch('onDharmaFormContextResolve', $event);

        $raw = $event->getArgument('context');

        if (!\is_array($raw) || trim((string) ($raw['title'] ?? '')) === '') {
            return null;
        }

        $lines = [];

        foreach ((array) ($raw['lines'] ?? []) as $label => $value) {
            if (\is_scalar($value) && trim((string) $value) !== '') {
                $lines[mb_substr(trim((string) $label), 0, 100)] = mb_substr(trim(strip_tags((string) $value)), 0, 500);
            }
        }

        return self::$memo[$key] = [
            'label' => mb_substr(trim(strip_tags((string) ($raw['label'] ?? ''))), 0, 60),
            'title' => mb_substr(trim(strip_tags((string) $raw['title'])), 0, 255),
            'url'   => self::safeUrl((string) ($raw['url'] ?? '')),
            'image' => self::safeUrl((string) ($raw['image'] ?? '')),
            'price' => mb_substr(trim(strip_tags((string) ($raw['price'] ?? ''))), 0, 100),
            'lines' => $lines,
        ];
    }

    private static function safeUrl(string $url): string
    {
        $url = trim($url);

        return preg_match('~^(https?://[^\s"<>]+|/[^\s"<>]*)$~i', $url) ? mb_substr($url, 0, 500) : '';
    }
}
