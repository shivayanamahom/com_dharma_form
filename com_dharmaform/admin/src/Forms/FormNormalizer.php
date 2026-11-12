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

use Joomla\CMS\Language\Text;

\defined('_JEXEC') or die;

/** Приведение конструктора полей и настроек формы к виду для хранения (при сохранении формы). */
final class FormNormalizer
{
    private const MAPS = ['', 'name', 'phone', 'email', 'message'];

    private const MAPPABLE = ['text', 'tel', 'email', 'textarea', 'select', 'radio'];

    private const HIDDEN_SOURCES = ['page_url', 'page_title', 'custom'];

    /** Расширения, которые нельзя разрешать к загрузке никогда: исполняемые и способные выполнить скрипт в браузере. */
    /**
     * @param array<int, mixed> $raw строки конструктора
     *
     * @return array{0: array<int, array<string, mixed>>, 1: string|null} поля и текст ошибки
     */
    public static function fields(array $raw): array
    {
        $rows = [];

        foreach ($raw as $row) {
            if (\is_array($row) && \in_array((string) ($row['type'] ?? ''), FormConfig::TYPES, true)) {
                $rows[] = $row;
            }
        }

        if (!$rows) {
            return [[], Text::_('COM_DHARMAFORM_FORM_ERROR_NO_FIELDS')];
        }

        if (\count($rows) > FormSanitizer::MAX_FIELDS) {
            return [[], Text::sprintf('COM_DHARMAFORM_FORM_ERROR_TOO_MANY', FormSanitizer::MAX_FIELDS)];
        }

        $used   = [];
        $fields = [];

        // Имена, заданные вручную, занимают место первыми; остальным имя придумывается.
        foreach ($rows as $i => $row) {
            $name = $row['type'] === 'content' ? '' : FormSanitizer::name(trim((string) ($row['name'] ?? '')));

            if ($name !== '' && !isset($used[$name])) {
                $used[$name] = true;
                $rows[$i]['name'] = $name;
            } else {
                $rows[$i]['name'] = '';
            }
        }

        foreach ($rows as $i => $row) {
            $type  = (string) $row['type'];
            $label = mb_substr(trim((string) ($row['label'] ?? '')), 0, 255);
            $name  = (string) $row['name'];

            if ($type !== 'content' && $name === '') {
                $name        = self::autoName($type, $label, (string) ($row['hidden_source'] ?? ''), $used);
                $used[$name] = true;
            }

            $field = array_merge(FormConfig::FIELD_DEFAULTS, [
                'type'        => $type,
                'name'        => $name,
                'label'       => $label,
                'placeholder' => mb_substr(trim((string) ($row['placeholder'] ?? '')), 0, 255),
                'label_mode'  => \in_array($row['label_mode'] ?? '', ['show', 'hide'], true) ? (string) $row['label_mode'] : '',
                'required'    => (int) !empty($row['required']),
                'error_text'  => mb_substr(trim((string) ($row['error_text'] ?? '')), 0, 255),
                'width'       => (string) ($row['width'] ?? '12') === '6' ? '6' : '12',
            ]);

            switch ($type) {
                case 'select':
                case 'radio':
                case 'checkboxes':
                    $field['options'] = trim((string) ($row['options'] ?? ''));

                    if (!FormSanitizer::options($field['options'])) {
                        return [[], Text::sprintf('COM_DHARMAFORM_FORM_ERROR_FIELD_OPTIONS', $name)];
                    }
                    break;

                case 'tel':
                    $field['mask'] = FormSanitizer::mask((string) ($row['mask'] ?? ''));
                    break;

                case 'textarea':
                    $field['rows'] = max(2, min(20, (int) ($row['rows'] ?? 4)));
                    break;

                case 'hidden':
                    $source = (string) ($row['hidden_source'] ?? 'custom');
                    $source = \in_array($source, self::HIDDEN_SOURCES, true) ? $source : 'custom';

                    if ($source === 'custom' && trim((string) ($row['hidden_value'] ?? '')) === '') {
                        return [[], Text::sprintf('COM_DHARMAFORM_FORM_ERROR_FIELD_HIDDEN', $name)];
                    }

                    $field['hidden_source'] = $source;
                    $field['hidden_value']  = $source === 'custom' ? mb_substr(trim((string) $row['hidden_value']), 0, 255) : '';
                    $field['required']      = 0;
                    break;

                case 'consent':
                    $field['consent_text'] = FormSanitizer::html((string) ($row['consent_text'] ?? ''));
                    $field['link_text']    = mb_substr(trim((string) ($row['link_text'] ?? '')), 0, 255);
                    $field['link_url']     = FormSanitizer::url((string) ($row['link_url'] ?? ''));
                    break;

                case 'content':
                    $field['content'] = FormSanitizer::html((string) ($row['content'] ?? ''));

                    if (trim(strip_tags($field['content'])) === '') {
                        return [[], Text::sprintf('COM_DHARMAFORM_FORM_ERROR_FIELD_CONTENT', $i + 1)];
                    }

                    $field['required'] = 0;
                    break;

                case 'file':
                    $field['file_mode']      = (string) ($row['file_mode'] ?? 'button') === 'dropzone' ? 'dropzone' : 'button';
                    $field['file_multiple']  = (int) !empty($row['file_multiple']);
                    $field['file_accept']    = self::accept((string) ($row['file_accept'] ?? ''));
                    $field['file_max_mb']    = max(1, min(50, (int) ($row['file_max_mb'] ?? 5)));
                    $field['file_max_count'] = $field['file_multiple'] ? max(1, min(20, (int) ($row['file_max_count'] ?? 5))) : 1;
                    break;
            }

            // Подпись нужна полям ввода: так значение называется в заявке.
            if ($field['label'] === '' && !\in_array($type, ['hidden', 'consent', 'content'], true)) {
                return [[], Text::sprintf('COM_DHARMAFORM_FORM_ERROR_FIELD_LABEL', $name)];
            }

            $maps = (string) ($row['maps_to'] ?? '');

            if (\in_array($maps, self::MAPS, true) && \in_array($type, self::MAPPABLE, true)) {
                $field['maps_to'] = $maps;
            }

            $fields[] = $field;
        }

        return [self::autoMaps($fields), null];
    }

    /** Куда в заявке попадает поле: заданное вручную сохраняется, очевидное (телефон, почта, первый текст, первый текстовый блок) подставляется, если место свободно. */
    private static function autoMaps(array $fields): array
    {
        $taken = [];

        foreach ($fields as &$field) {
            if ($field['maps_to'] !== '' && isset($taken[$field['maps_to']])) {
                $field['maps_to'] = '';
            } elseif ($field['maps_to'] !== '') {
                $taken[$field['maps_to']] = true;
            }
        }

        unset($field);

        foreach ($fields as &$field) {
            if ($field['maps_to'] !== '') {
                continue;
            }

            $auto = match ($field['type']) {
                'tel'      => 'phone',
                'email'    => 'email',
                'text'     => 'name',
                'textarea' => 'message',
                default    => '',
            };

            if ($auto !== '' && !isset($taken[$auto])) {
                $field['maps_to'] = $auto;
                $taken[$auto]     = true;
            }
        }

        return $fields;
    }

    /** Имя без указания: телефон, почта и согласие получают привычные, остальным — транслитерация подписи; повторы — с суффиксом. */
    private static function autoName(string $type, string $label, string $hiddenSource, array $used): string
    {
        $base = match ($type) {
            'tel'     => 'phone',
            'email'   => 'email',
            'consent' => 'agree',
            'file'    => $label !== '' ? FormSanitizer::nameFromLabel($label) : 'file',
            'hidden'  => $hiddenSource !== '' && $hiddenSource !== 'custom' ? $hiddenSource : ($label !== '' ? FormSanitizer::nameFromLabel($label) : 'hidden'),
            default   => FormSanitizer::nameFromLabel($label),
        };
        $base = FormSanitizer::name($base) ?: 'field';
        $name = $base;

        for ($i = 2; isset($used[$name]); $i++) {
            $name = substr($base, 0, 29) . '_' . $i;
        }

        return $name;
    }

    /** Расширения через запятую: латиница и цифры, без запрещённых; пусто, если ничего не осталось. */
    public static function accept(string $value): string
    {
        $out = [];

        foreach (preg_split('~[\s,;.]+~', strtolower($value), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $ext) {
            $ext = (string) preg_replace('~[^a-z0-9]+~', '', $ext);

            if ($ext !== '' && \strlen($ext) <= 8 && !\in_array($ext, \Dharma\Component\DharmaForm\Site\Service\FileUploader::BLOCKED, true)) {
                $out[$ext] = $ext;
            }
        }

        return implode(', ', array_values($out));
    }

    /**
     * Настройки формы: тексты — белый список HTML, адреса — белый список схем, числа — в допустимых пределах, списки — без лишнего.
     *
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    public static function params(array $params): array
    {
        $params = array_intersect_key($params, FormConfig::DEFAULTS);

        foreach (['modal_intro', 'success_message'] as $key) {
            if (isset($params[$key])) {
                $params[$key] = FormSanitizer::html((string) $params[$key]);
            }
        }

        foreach (['success_redirect', 'consent_url'] as $key) {
            if (isset($params[$key])) {
                $params[$key] = FormSanitizer::url((string) $params[$key]);
            }
        }

        foreach (['modal_title', 'submit_text', 'button_class', 'notify_subject'] as $key) {
            if (isset($params[$key])) {
                $params[$key] = mb_substr(trim(strip_tags((string) $params[$key])), 0, 255);
            }
        }

        foreach (['context_dharmashop'] as $key) {
            if (isset($params[$key])) {
                $params[$key] = (int) !empty($params[$key]);
            }
        }

        if (isset($params['display']) && !\in_array($params['display'], ['popup', 'inline'], true)) {
            $params['display'] = 'popup';
        }

        if (isset($params['trigger_class'])) {
            $classes = preg_split('~[\s,]+~', (string) $params['trigger_class'], -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $classes = array_filter(array_map(static fn (string $c): string => (string) preg_replace('~[^A-Za-z0-9_-]+~', '', $c), $classes));
            $params['trigger_class'] = implode(' ', array_unique($classes));
        }

        if (isset($params['button_class'])) {
            $params['button_class'] = trim((string) preg_replace('~[^A-Za-z0-9_\s-]+~', '', (string) $params['button_class']));
        }

        if (isset($params['goal_name'])) {
            $params['goal_name'] = (string) preg_replace('~[^A-Za-z0-9_.-]+~', '', (string) $params['goal_name']);
        }

        if (isset($params['metrika_counter'])) {
            $params['metrika_counter'] = (string) preg_replace('~\D+~', '', (string) $params['metrika_counter']);
        }

        foreach (['show_labels', 'close_modal', 'auto_enabled', 'protect_honeypot', 'protect_links', 'protect_pow', 'protect_behavior', 'protect_replay', 'protect_scripts', 'protect_gibberish', 'protect_disposable', 'protect_mx'] as $key) {
            if (isset($params[$key])) {
                $params[$key] = (int) !empty($params[$key]);
            }
        }

        $clamp = static function (array &$p, string $key, int $min, int $max) : void {
            if (isset($p[$key]) && $p[$key] !== '') {
                $p[$key] = max($min, min($max, (int) $p[$key]));
            }
        };

        $clamp($params, 'after_delay', 0, 60);
        $clamp($params, 'protect_min_seconds', 0, 60);
        $clamp($params, 'protect_rate_limit', 0, 100);
        $clamp($params, 'retention_days', 0, 3650);
        $clamp($params, 'protect_flood_limit', 0, 1000);
        $clamp($params, 'protect_pow_bits', 8, 20);
        $clamp($params, 'protect_score_limit', 1, 20);

        // Автопоказ.
        $clamp($params, 'auto_delay', 0, 600);
        $clamp($params, 'auto_scroll', 1, 100);
        $clamp($params, 'auto_idle', 5, 3600);
        $clamp($params, 'auto_days', 1, 365);
        $clamp($params, 'auto_sent_days', 0, 365);

        // Ни один флажок не отмечен — браузер не присылает поле вовсе: сохраняется пустой список, а не умолчание.
        $params['auto_triggers'] = array_values(array_intersect(['delay', 'scroll', 'exit', 'idle'], (array) ($params['auto_triggers'] ?? [])));

        // Автопоказ без единого выбранного триггера ничего бы не делал: берётся показ по времени.
        if (!empty($params['auto_enabled']) && empty($params['auto_triggers'])) {
            $params['auto_triggers'] = ['delay'];
        }

        if (isset($params['auto_frequency']) && !\in_array($params['auto_frequency'], ['always', 'session', 'days'], true)) {
            $params['auto_frequency'] = 'session';
        }

        if (isset($params['auto_devices']) && !\in_array($params['auto_devices'], ['all', 'desktop', 'mobile'], true)) {
            $params['auto_devices'] = 'all';
        }

        if (isset($params['auto_pages_mode']) && !\in_array($params['auto_pages_mode'], ['include', 'exclude'], true)) {
            $params['auto_pages_mode'] = 'include';
        }

        if (isset($params['auto_pages'])) {
            $lines = [];

            foreach (preg_split('~\R~u', strip_tags((string) $params['auto_pages'])) ?: [] as $line) {
                $line = mb_substr(trim($line), 0, 200);

                if ($line !== '') {
                    $lines[] = $line;
                }

                if (\count($lines) >= 50) {
                    break;
                }
            }

            $params['auto_pages'] = implode("\n", $lines);
        }

        // Получатели каналов: список из полей выбора склеивается в строку (так их читают плагины-каналы).
        foreach (['notify_chats', 'notify_max_chats'] as $key) {
            if (isset($params[$key])) {
                $list = \is_array($params[$key]) ? $params[$key] : preg_split('~[\s,;]+~u', (string) $params[$key], -1, PREG_SPLIT_NO_EMPTY);
                $params[$key] = implode(' ', array_filter(array_map(static fn ($v): string => preg_replace('~[^A-Za-z0-9@:_-]+~', '', (string) $v) ?? '', (array) $list)));
            }
        }

        if (isset($params['notify_emails'])) {
            $params['notify_emails'] = trim(preg_replace('~[^A-Za-z0-9@._+\-,;\s]+~', '', (string) $params['notify_emails']) ?? '');
        }

        if (isset($params['notify_channels'])) {
            $params['notify_channels'] = array_values(array_filter(array_map(static fn ($v): string => (string) preg_replace('~[^a-z0-9_]+~', '', strtolower((string) $v)), (array) $params['notify_channels'])));
        }

        return $params;
    }
}
