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

use Dharma\Component\DharmaForm\Administrator\Forms\FormSanitizer;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\Database\DatabaseInterface;

\defined('_JEXEC') or die;

/**
 * Приём заявки с сайта: проверка присланных полей по описанию формы (клиенту не доверяем), защита (`SubmissionGuard`),
 * запись в журнал заявок и сохранение файлов. Сомнительное записывается со пометкой «спам» (не теряется, файлы не
 * сохраняются), посетитель видит обычное «принято».
 */
final class FormSubmission
{
    private const TEXT_MAX = 500;

    private const TEXTAREA_MAX = 5000;

    private const DUPLICATE_MINUTES = 10;

    private DatabaseInterface $db;

    public function __construct(?DatabaseInterface $db = null)
    {
        $this->db = $db ?? Factory::getContainer()->get(DatabaseInterface::class);
    }

    /**
     * @param array<string, mixed> $form  форма (`FormConfig::fromRow`)
     * @param array<string, mixed> $input `f` (значения), `files` (файлы полей), `stamp`, `website`, `page_url`, `utm`
     *
     * @return array{ok: bool, error: string, errors: array<string, string>, spam: bool, submission_id: int}
     */
    public function submit(array $form, array $input, string $ip): array
    {
        $params  = $form['params'];
        $values  = (array) ($input['f'] ?? []);
        $files   = (array) ($input['files'] ?? []);
        $errors  = [];
        $main    = ['name' => '', 'phone' => '', 'email' => '', 'message' => ''];
        $data    = [];
        $uploads = [];
        $consent = false;
        $pageUrl = FormSanitizer::pageUrl((string) ($input['page_url'] ?? ''));

        foreach ($form['fields'] as $field) {
            $type = (string) $field['type'];
            $name = (string) $field['name'];

            if ($type === 'content' || $name === '') {
                continue;
            }

            if ($type === 'file') {
                $result = FileUploader::validate($field, $files[$name] ?? null);

                if ($result['error'] !== '') {
                    $errors[$name] = $result['error'];
                    continue;
                }

                $uploads = array_merge($uploads, $result['items']);

                if ($result['items']) {
                    $data[$this->labelOf($field)] = implode(', ', array_column($result['items'], 'name'));
                }

                continue;
            }

            $result = $this->checkField($field, $values[$name] ?? '', $pageUrl);

            if ($result['error'] !== '') {
                $errors[$name] = $result['error'];
                continue;
            }

            if ($type === 'consent') {
                $consent = $consent || $result['value'] !== '';
                continue;
            }

            if ($result['value'] !== '') {
                $data[$this->labelOf($field)] = $result['value'];
            }

            $target = (string) $field['maps_to'] !== '' ? (string) $field['maps_to'] : ($type === 'tel' ? 'phone' : ($type === 'email' ? 'email' : ''));

            if ($target !== '' && isset($main[$target]) && $main[$target] === '') {
                $main[$target] = $result['value'];
            }
        }

        if ($errors) {
            return ['ok' => false, 'error' => '', 'errors' => $errors, 'spam' => false, 'submission_id' => 0];
        }

        $ipHash  = SubmissionGuard::ipHash($ip);
        $limit   = (int) $params['protect_rate_limit'];
        $contact = [$main['phone'], $main['email']];

        if ($limit > 0 && $this->recent($ipHash) >= $limit) {
            return $this->fail(Text::_('COM_DHARMAFORM_FORM_ERROR_RATE'));
        }

        if ($this->isDuplicate((int) $form['id'], $contact, $data)) {
            return $this->fail(Text::_('COM_DHARMAFORM_FORM_ERROR_DUPLICATE'));
        }

        $verdict = SubmissionGuard::inspect($input, $params, [
            'text'        => implode(' ', $data),
            'email'       => $main['email'],
            'ip'          => $ip,
            'recent_form' => $this->recentForm((int) $form['id']),
        ]);
        $spam    = $verdict['spam'];
        $stored  = $spam ? [] : FileUploader::store($uploads);

        if ($uploads && !$spam && !$stored) {
            return $this->fail(Text::_('COM_DHARMAFORM_FORM_ERROR_FILE_UPLOAD'));
        }

        $now  = Factory::getDate();
        $days = $params['retention_days'] !== '' ? max(0, (int) $params['retention_days']) : 0;
        $row  = (object) [
            'form_id'     => (int) $form['id'],
            'name'        => mb_substr($main['name'], 0, 255),
            'phone'       => mb_substr($main['phone'], 0, 64),
            'email'       => mb_substr($main['email'], 0, 255),
            'message'     => $main['message'],
            'fields'      => json_encode($data, JSON_UNESCAPED_UNICODE),
            'files'       => json_encode($stored, JSON_UNESCAPED_UNICODE),
            'page_url'    => mb_substr($pageUrl, 0, 500),
            'utm'         => json_encode($this->utm((array) ($input['utm'] ?? [])), JSON_UNESCAPED_UNICODE),
            'ip_hash'     => $ipHash,
            'consent_at'  => $consent ? $now->toSql() : null,
            'is_spam'     => $spam ? 1 : 0,
            'spam_score'  => $verdict['score'],
            'spam_reason' => implode(',', $verdict['reasons']),
            'is_read'     => 0,
            'state'       => 1,
            'created'     => $now->toSql(),
            'purge_after' => $days > 0 ? Factory::getDate('+' . $days . ' days')->format('Y-m-d') : null,
        ];

        try {
            $this->db->insertObject('#__dharmaform_submissions', $row, 'id');
        } catch (\Throwable) {
            FileUploader::deleteFiles($stored);

            return $this->fail(Text::_('COM_DHARMAFORM_FORM_ERROR_SAVE'));
        }

        return ['ok' => true, 'error' => '', 'errors' => [], 'spam' => $spam, 'submission_id' => (int) $row->id];
    }

    private function labelOf(array $field): string
    {
        return trim((string) $field['label']) !== '' ? (string) $field['label'] : (string) $field['name'];
    }

    /**
     * Проверка одного поля.
     *
     * @return array{value: string, error: string}
     */
    private function checkField(array $field, mixed $raw, string $pageUrl): array
    {
        $type     = (string) $field['type'];
        $required = !empty($field['required']);
        $custom   = trim((string) $field['error_text']);
        $err      = static fn (string $key): array => ['value' => '', 'error' => $custom !== '' ? $custom : Text::_($key)];
        $ok       = static fn (string $value): array => ['value' => $value, 'error' => ''];

        if ($type === 'hidden') {
            return $ok(match ($field['hidden_source']) {
                'page_url'   => $pageUrl,
                'page_title' => $this->plain($raw, 255),
                default      => (string) $field['hidden_value'],
            });
        }

        if ($type === 'checkboxes') {
            $allowed = array_column(FormSanitizer::options((string) $field['options']), 'value');
            $chosen  = array_values(array_intersect(\is_array($raw) ? array_map('strval', array_filter($raw, 'is_scalar')) : [], $allowed));

            return !$chosen && $required ? $err('COM_DHARMAFORM_FORM_ERROR_CHOICE_MULTI') : $ok(implode(', ', $chosen));
        }

        if (\is_array($raw)) {
            return $err('COM_DHARMAFORM_FORM_ERROR_REQUIRED');
        }

        $value = $this->plain($raw, 0);

        if ($type === 'consent') {
            $checked = (string) $raw !== '' && (string) $raw !== '0';

            return !$checked && $required ? $err('COM_DHARMAFORM_FORM_ERROR_CONSENT') : $ok($checked ? Text::_('JYES') : '');
        }

        if ($value === '') {
            return $required ? $err('COM_DHARMAFORM_FORM_ERROR_REQUIRED') : $ok('');
        }

        switch ($type) {
            case 'text':
                return mb_strlen($value) > self::TEXT_MAX ? $err('COM_DHARMAFORM_FORM_ERROR_TOO_LONG') : $ok($value);

            case 'textarea':
                $value = $this->plain($raw, 0, true);

                return mb_strlen($value) > self::TEXTAREA_MAX ? $err('COM_DHARMAFORM_FORM_ERROR_TOO_LONG') : $ok($value);

            case 'email':
                return filter_var($value, FILTER_VALIDATE_EMAIL) && mb_strlen($value) <= 255 ? $ok($value) : $err('COM_DHARMAFORM_FORM_ERROR_EMAIL');

            case 'tel':
                return $this->phoneComplete($value, (string) $field['mask']) ? $ok(mb_substr($value, 0, 50)) : $err('COM_DHARMAFORM_FORM_ERROR_PHONE');

            case 'select':
            case 'radio':
                return \in_array($value, array_column(FormSanitizer::options((string) $field['options']), 'value'), true) ? $ok($value) : $err('COM_DHARMAFORM_FORM_ERROR_CHOICE');

            case 'date':
                $date = \DateTime::createFromFormat('Y-m-d', $value);

                return $date && $date->format('Y-m-d') === $value ? $ok($value) : $err('COM_DHARMAFORM_FORM_ERROR_DATE');
        }

        return $err('COM_DHARMAFORM_FORM_ERROR_REQUIRED');
    }

    /** Текст без тегов и управляющих знаков (переводы строк сохраняются, если `$multiline`); не строка — пустая строка. */
    private function plain(mixed $value, int $max = 0, bool $multiline = false): string
    {
        $text = \is_scalar($value) ? (string) $value : '';
        $text = strip_tags($text);
        $text = (string) preg_replace($multiline ? '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u' : '/[\x00-\x1F\x7F]/u', '', $text);
        $text = trim($text);

        return $max > 0 ? mb_substr($text, 0, $max) : $text;
    }

    /**
     * Номер полный: после кода страны из маски (`+7 (`) ровно столько цифр, сколько `#` в маске. Принимаются и номер
     * без кода, и с кодом в любом виде (`+7…`, `8…`, `7…`).
     */
    private function phoneComplete(string $value, string $mask): bool
    {
        $mask = FormSanitizer::mask($mask);

        return self::phoneDigits($value, $mask) === substr_count($mask, '#');
    }

    private static function phoneDigits(string $value, string $mask): int
    {
        $value  = trim($value);
        $lead   = trim((string) strstr($mask . '#', '#', true));
        $prefix = (string) preg_replace('~\D+~', '', $lead);
        $slots  = substr_count($mask, '#');

        if ($lead !== '' && str_starts_with($value, $lead)) {
            return \strlen((string) preg_replace('~\D+~', '', substr($value, \strlen($lead))));
        }

        $digits = (string) preg_replace('~\D+~', '', $value);

        if ($prefix !== '' && \strlen($digits) === $slots + \strlen($prefix) && (str_starts_with($digits, $prefix) || ($prefix === '7' && str_starts_with($digits, '8')))) {
            return $slots;
        }

        return \strlen($digits);
    }

    /** Метки источника: только utm_* и подобные, короткие значения. @return array<string, string> */
    private function utm(array $utm): array
    {
        $out = [];

        foreach ($utm as $key => $value) {
            $key = strtolower((string) $key);

            if (preg_match('~^(utm_[a-z]+|yclid|gclid|ref)$~', $key) && \is_string($value) && $value !== '') {
                $out[$key] = mb_substr(trim(strip_tags($value)), 0, 200);
            }
        }

        return $out;
    }

    /** Заявки за последний час с того же адреса (по хешу). */
    private function recent(string $ipHash): int
    {
        $db = $this->db;

        return (int) $db->setQuery(
            'SELECT COUNT(*) FROM ' . $db->quoteName('#__dharmaform_submissions')
            . ' WHERE ip_hash = ' . $db->quote($ipHash) . ' AND created >= ' . $db->quote(Factory::getDate('-1 hour')->toSql())
        )->loadResult();
    }

    /** Заявки этой формы за последние десять минут (лавина с разных адресов). */
    private function recentForm(int $formId): int
    {
        $db = $this->db;

        return (int) $db->setQuery(
            'SELECT COUNT(*) FROM ' . $db->quoteName('#__dharmaform_submissions')
            . ' WHERE form_id = ' . $formId . ' AND created >= ' . $db->quote(Factory::getDate('-10 minutes')->toSql())
        )->loadResult();
    }

    /** Такая же заявка (те же значения) в той же форме за последние минуты с того же контакта. @param string[] $contacts @param array<string, string> $data */
    private function isDuplicate(int $formId, array $contacts, array $data): bool
    {
        $contacts = array_values(array_filter($contacts));

        if (!$contacts) {
            return false;
        }

        $db    = $this->db;
        $where = [];

        foreach ($contacts as $contact) {
            $where[] = $db->quoteName('phone') . ' = ' . $db->quote($contact);
            $where[] = $db->quoteName('email') . ' = ' . $db->quote($contact);
        }

        $sql = 'SELECT COUNT(*) FROM ' . $db->quoteName('#__dharmaform_submissions') . ' WHERE form_id = ' . $formId
            . ' AND fields = ' . $db->quote(json_encode($data, JSON_UNESCAPED_UNICODE))
            . ' AND created >= ' . $db->quote(Factory::getDate('-' . self::DUPLICATE_MINUTES . ' minutes')->toSql())
            . ' AND (' . implode(' OR ', $where) . ')';

        return (int) $db->setQuery($sql)->loadResult() > 0;
    }

    /** @return array{ok: bool, error: string, errors: array<string, string>, spam: bool, submission_id: int} */
    private function fail(string $message): array
    {
        return ['ok' => false, 'error' => $message, 'errors' => [], 'spam' => false, 'submission_id' => 0];
    }
}
