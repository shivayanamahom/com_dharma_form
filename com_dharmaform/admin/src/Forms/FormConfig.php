<?php

/**
 * Dharma Form — универсальные формы для Joomla 5.2+ и 6+.
 *
 * @package    com_dharmaform
 * @copyright  (C) 2026 Dharma. Все права защищены.
 * @license    GNU General Public License версии 2 или новее; см. LICENSE.txt
 */

declare(strict_types=1);

namespace Dharma\Component\DharmaForm\Administrator\Forms;

\defined('_JEXEC') or die;

/** Строка таблицы `forms` в удобном виде: поля списком с умолчаниями, настройки с умолчаниями. */
final class FormConfig
{
    /** Допустимые типы полей. */
    public const TYPES = ['text', 'email', 'textarea', 'tel', 'select', 'radio', 'checkboxes', 'date', 'file', 'hidden', 'consent', 'content'];

    /** Значения свойств поля, если в строке конструктора ничего не задано. */
    public const FIELD_DEFAULTS = [
        'type'          => 'text',
        'name'          => '',
        'label'         => '',
        'placeholder'   => '',
        'required'      => 0,
        'width'         => '12',
        'options'       => '',
        'mask'          => '',
        'rows'          => 4,
        'error_text'    => '',
        'hidden_source' => 'custom',
        'hidden_value'  => '',
        'content'       => '',
        'consent_text'  => '',
        'link_text'     => '',
        'link_url'      => '',
        'maps_to'       => '',
        'file_mode'     => 'button',
        'file_multiple' => 0,
        'file_accept'   => 'jpg, jpeg, png, pdf, doc, docx',
        'file_max_mb'   => 5,
        'file_max_count' => 5,
    ];

    /** Значения настроек формы, если в `params` ничего не задано. */
    public const DEFAULTS = [
        'modal_title'         => '',
        'modal_intro'         => '',
        'submit_text'         => '',
        'button_class'        => '',
        'show_labels'         => 1,
        'trigger_class'       => '',
        'auto_enabled'        => 0,
        'auto_triggers'       => ['delay'],
        'auto_delay'          => 10,
        'auto_scroll'         => 50,
        'auto_idle'           => 30,
        'auto_frequency'      => 'session',
        'auto_days'           => 7,
        'auto_devices'        => 'all',
        'auto_pages'          => '',
        'auto_pages_mode'     => 'include',
        'after_action'        => 'message',
        'success_message'     => '',
        'success_redirect'    => '',
        'after_delay'         => 3,
        'close_modal'         => 1,
        'goal_name'           => '',
        'metrika_counter'     => '',
        'consent_url'         => '',
        'protect_honeypot'    => 1,
        'protect_min_seconds' => 3,
        'protect_rate_limit'  => 5,
        'protect_flood_limit' => 0,
        'protect_pow'         => 1,
        'protect_pow_bits'    => 14,
        'protect_behavior'    => 1,
        'protect_replay'      => 1,
        'protect_links'       => 0,
        'protect_scripts'     => 1,
        'protect_gibberish'   => 1,
        'protect_disposable'  => 1,
        'protect_mx'          => 0,
        'protect_score_limit' => 3,
        'protect_stopwords'   => '',
        'retention_days'      => '',
        'notify_channels'     => [],
        'notify_emails'       => '',
        'notify_chats'        => '',
        'notify_max_chats'    => '',
        'notify_subject'      => '',
    ];

    /**
     * @param object $row строка таблицы `forms`
     *
     * @return array{id: int, code: string, title: string, fields: array<int, array<string, mixed>>, params: array<string, mixed>}
     */
    public static function fromRow(object $row): array
    {
        $fields = \is_string($row->fields ?? null) && $row->fields !== '' ? json_decode($row->fields, true) : [];
        $params = \is_string($row->params ?? null) && $row->params !== '' ? json_decode($row->params, true) : [];
        $params = array_merge(self::DEFAULTS, array_filter(\is_array($params) ? $params : [], static fn ($v): bool => $v !== '' && $v !== null));

        $list = [];

        foreach (\is_array($fields) ? $fields : [] as $field) {
            if (!\is_array($field)) {
                continue;
            }

            $field = array_merge(self::FIELD_DEFAULTS, $field);

            if (!\in_array($field['type'], self::TYPES, true)) {
                continue;
            }

            $list[] = $field;
        }

        return [
            'id'     => (int) $row->id,
            'code'   => (string) $row->code,
            'title'  => (string) $row->title,
            'fields' => $list,
            'params' => $params,
        ];
    }
}
