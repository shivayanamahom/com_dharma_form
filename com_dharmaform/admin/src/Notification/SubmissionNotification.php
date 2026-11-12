<?php

/**
 * Dharma Form — универсальные формы для Joomla 5.2+ и 6+.
 *
 * @package    com_dharmaform
 * @copyright  (C) 2026 Dharma. Все права защищены.
 * @license    GNU General Public License версии 2 или новее; см. LICENSE.txt
 */

declare(strict_types=1);

namespace Dharma\Component\DharmaForm\Administrator\Notification;

use Dharma\Component\DharmaForm\Administrator\Helper\VisitorMeta;

\defined('_JEXEC') or die;

/**
 * Уведомление о заявке для плагинов группы `dharmaformnotify`: всё, что нужно каналу, без обращений к базе.
 * Объект не изменяется; настройки формы — в `form['params']`.
 */
final class SubmissionNotification
{
    /**
     * @param array<string, mixed>  $submission поля заявки (`id`, `name`, `phone`, `email`, `message`, `page_url`, `created`, `is_spam`)
     * @param array<string, string> $fields     присланные значения: подпись => значение
     * @param array<int, array{name: string, path: string, size: int}> $files вложения (путь на диске, имя для показа, размер)
     * @param array<string, mixed>  $form       форма: `title`, `code`, `params` (настройки доставки формы)
     * @param array<string, string> $utm        метки источника
     */
    public function __construct(
        public readonly int $submissionId,
        public readonly array $submission,
        public readonly array $fields,
        public readonly array $files,
        public readonly array $form,
        public readonly array $utm,
        public readonly string $adminUrl,
    ) {
    }

    /** Подставить в шаблон темы метки {id}, {name}, {phone}, {email}, {form}. */
    public function subject(string $template): string
    {
        $map = [
            '{id}'    => (string) $this->submissionId,
            '{name}'  => (string) ($this->submission['name'] ?? ''),
            '{phone}' => (string) ($this->submission['phone'] ?? ''),
            '{email}' => (string) ($this->submission['email'] ?? ''),
            '{form}'  => (string) ($this->form['title'] ?? ''),
        ];

        return trim(preg_replace('~\s+~u', ' ', strtr($template, $map)) ?? '');
    }

    private function heading(): string
    {
        $title = trim((string) ($this->form['title'] ?? ''));

        return '📝 ' . ($title !== '' ? $title : 'Новая заявка');
    }

    /** Значения полей формы, которых нет в имени, телефоне, почте и сообщении (они выводятся отдельно). @return array<string, string> */
    private function extra(): array
    {
        $known = [];

        foreach (['name', 'phone', 'email', 'message'] as $key) {
            $known[] = (string) ($this->submission[$key] ?? '');
        }

        $out = [];

        foreach ($this->fields as $label => $value) {
            if (!\in_array($value, $known, true)) {
                $out[(string) $label] = (string) $value;
            }
        }

        return $out;
    }

    /** Имена файлов отдельной строкой нужны, только если среди значений полей их ещё нет. */
    private function filesLine(): string
    {
        $names = implode(', ', array_column($this->files, 'name'));

        return $names !== '' && !\in_array($names, $this->fields, true) ? $names : '';
    }

    /**
     * Строки сообщения для текстовых каналов (Telegram, MAX): заголовок, кто, остальные поля, сообщение, файлы, страница,
     * ссылка в админку. Без контактов (`$details = false`) остаются только заголовок, номер и ссылка в админку.
     *
     * @return string[]
     */
    public function lines(bool $details = true): array
    {
        $lines = [$this->heading(), 'Заявка № ' . $this->submissionId . ' от ' . (string) ($this->submission['created'] ?? '')];

        if (!empty($this->submission['is_spam'])) {
            $lines[] = '⚠ Защита форм пометила как спам';
        }

        if ($details) {
            $who = [];

            foreach (['name' => 'Имя', 'phone' => 'Телефон', 'email' => 'Почта'] as $key => $label) {
                if (trim((string) ($this->submission[$key] ?? '')) !== '') {
                    $who[] = $label . ': ' . $this->submission[$key];
                }
            }

            if ($who) {
                $lines[] = '';
                $lines   = array_merge($lines, $who);
            }

            $extra = [];

            foreach ($this->extra() as $label => $value) {
                $extra[] = $label . ': ' . $value;
            }

            if ($extra) {
                $lines[] = '';
                $lines   = array_merge($lines, $extra);
            }

            if (trim((string) ($this->submission['message'] ?? '')) !== '') {
                $lines[] = '';
                $lines[] = 'Сообщение: ' . $this->submission['message'];
            }

            if ($this->filesLine() !== '') {
                $lines[] = '';
                $lines[] = 'Файлы: ' . $this->filesLine();
            }
        }

        if (!empty($this->submission['page_url'])) {
            $lines[] = '';
            $lines[] = 'Страница: ' . $this->submission['page_url'];
        }

        if ($details && ($meta = VisitorMeta::pairs((array) ($this->submission['meta'] ?? [])))) {
            $lines[] = '';

            foreach ($meta as $label => $value) {
                $lines[] = $label . ': ' . $value;
            }
        }

        $lines[] = 'Открыть в админке: ' . $this->adminUrl;

        return $lines;
    }

    /**
     * Письмо для почтового канала: вёрстка таблицами со встроенными стилями (почтовые программы не читают внешние
     * стили), без картинок: ничего не блокируется и не нужно подгружать.
     */
    public function html(string $siteName, string $accent = '#0d6efd', string $button = '#0d6efd'): string
    {
        $e     = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
        $muted = '#6c757d';
        $rows  = [];

        $row = static function (string $label, string $valueHtml) use (&$rows, $muted): void {
            $rows[] = '<tr><td style="padding:8px 16px 8px 0;width:130px;vertical-align:top;font-size:13px;color:' . $muted . ';">' . $label . '</td>'
                . '<td style="padding:8px 0;vertical-align:top;font-size:15px;color:#212529;">' . $valueHtml . '</td></tr>';
        };

        $name  = trim((string) ($this->submission['name'] ?? ''));
        $phone = trim((string) ($this->submission['phone'] ?? ''));
        $email = trim((string) ($this->submission['email'] ?? ''));

        if ($name !== '') {
            $row('Имя', $e($name));
        }

        if ($phone !== '') {
            $row('Телефон', '<a href="tel:' . $e((string) preg_replace('/[^0-9+]/', '', $phone)) . '" style="color:' . $accent . ';text-decoration:none;font-weight:bold;">' . $e($phone) . '</a>');
        }

        if ($email !== '') {
            $row('Почта', '<a href="mailto:' . $e($email) . '" style="color:' . $accent . ';">' . $e($email) . '</a>');
        }

        foreach ($this->extra() as $label => $value) {
            $row($e($label), nl2br($e($value)));
        }

        if ($this->filesLine() !== '') {
            $row('Файлы', $e($this->filesLine()));
        }

        $message = trim((string) ($this->submission['message'] ?? ''));
        $parts   = [];

        if (!empty($this->submission['is_spam'])) {
            $parts[] = '<p style="margin:0 0 16px;padding:10px 14px;background:#fff4e5;border-radius:6px;font-size:13px;color:#8a5200;">Защита форм пометила эту заявку как спам.</p>';
        }

        if ($rows) {
            $parts[] = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;border-top:1px solid #dee2e6;">' . implode('', $rows) . '</table>';
        }

        if ($message !== '') {
            $parts[] = '<div style="margin:16px 0 0;padding:14px 16px;background:#f3f4f6;border-left:4px solid ' . $button . ';border-radius:0 6px 6px 0;font-size:15px;line-height:1.55;color:#212529;">' . nl2br($e($message)) . '</div>';
        }

        if (!empty($this->submission['page_url'])) {
            $url     = (string) $this->submission['page_url'];
            $parts[] = '<p style="margin:16px 0 0;font-size:13px;color:' . $muted . ';">Страница: <a href="' . $e($url) . '" style="color:' . $accent . ';">' . $e(mb_strlen($url) > 70 ? mb_substr($url, 0, 67) . '…' : $url) . '</a></p>';
        }

        if ($meta = VisitorMeta::pairs((array) ($this->submission['meta'] ?? []))) {
            $items = [];

            foreach ($meta as $label => $value) {
                $items[] = $e($label) . ': ' . $e($value);
            }

            $parts[] = '<p style="margin:16px 0 0;font-size:12px;line-height:1.6;color:' . $muted . ';">' . implode('<br>', $items) . '</p>';
        }

        $parts[] = '<p style="margin:24px 0 4px;"><a href="' . $e($this->adminUrl) . '" style="display:inline-block;padding:13px 24px;background:' . $button . ';border-radius:6px;font-size:15px;font-weight:bold;color:#ffffff;text-decoration:none;">Открыть в админке</a></p>';

        return '<!DOCTYPE html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>'
            . '<body style="margin:0;padding:0;background:#f3f4f6;">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f3f4f6;"><tr><td align="center" style="padding:24px 12px;">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;font-family:Arial,Helvetica,sans-serif;">'
            . '<tr><td style="background:' . $accent . ';border-radius:10px 10px 0 0;padding:22px 28px;">'
            . '<div style="font-size:12px;letter-spacing:2px;text-transform:uppercase;color:#cfe2ff;">' . $e($siteName) . '</div>'
            . '<div style="margin-top:8px;font-size:22px;line-height:1.25;font-weight:bold;color:#ffffff;">' . $e($this->heading()) . '</div>'
            . '</td></tr>'
            . '<tr><td style="background:#ffffff;border-radius:0 0 10px 10px;padding:24px 28px 28px;">'
            . '<p style="margin:0 0 16px;font-size:13px;color:' . $muted . ';">Заявка № ' . $this->submissionId . ' от ' . $e((string) ($this->submission['created'] ?? '')) . '</p>'
            . implode('', $parts)
            . '</td></tr>'
            . '<tr><td style="padding:16px 8px 0;text-align:center;font-size:12px;line-height:1.5;color:' . $muted . ';">Письмо отправлено автоматически сайтом «' . $e($siteName) . '».'
            . ($email !== '' ? '<br>Ответ на это письмо уйдёт заявителю.' : '') . '</td></tr>'
            . '</table></td></tr></table></body></html>';
    }
}
