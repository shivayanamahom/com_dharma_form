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
use Joomla\CMS\Language\Text;

\defined('_JEXEC') or die;

/**
 * Файлы из полей формы. Проверка (`validate`) и сохранение (`store`) разделены: сначала проверяется всё, и только если
 * заявка принята (не спам, нет других ошибок), файлы ложатся на диск. Имена на диске случайные, расширение — из белого
 * списка поля; исполняемые типы запрещены всегда. Каталог закрыт от прямого доступа; скачивание — только из админки
 * (`submission.download`) через `resolve`.
 */
final class FileUploader
{
    /** Расширения, которые не принимаются никогда, даже если их указали в поле. */
    private const BLOCKED = ['php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phar', 'pht', 'pl', 'py', 'cgi', 'sh', 'bash', 'exe', 'bat', 'cmd', 'com', 'msi', 'dll', 'js', 'mjs', 'html', 'htm', 'xhtml', 'shtml', 'svg', 'htaccess', 'htpasswd', 'ini', 'jar', 'vbs', 'ps1'];

    private const IMAGES = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp'];

    private const STORED = '~^\d{4}/\d{2}/[a-f0-9]{32}\.[a-z0-9]{1,10}$~';

    /** Каталог загрузок: из настроек компонента, путь от корня сайта или абсолютный. */
    public static function baseDir(): string
    {
        $dir = trim((string) ComponentHelper::getParams('com_dharmaform')->get('upload_dir', ''));
        $dir = $dir !== '' ? $dir : 'media/com_dharmaform/uploads';

        if (!str_starts_with($dir, '/') && !preg_match('~^[A-Za-z]:[\\\\/]~', $dir)) {
            $dir = JPATH_ROOT . '/' . $dir;
        }

        return rtrim(str_replace('\\', '/', $dir), '/');
    }

    /** Расширения, разрешённые полем (`file_accept`): строчные буквы и цифры без точки, без запрещённых. @return string[] */
    public static function allowed(string $accept): array
    {
        $out = [];

        foreach (preg_split('~[\s,;]+~', strtolower($accept), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $ext) {
            $ext = ltrim($ext, '.');

            if (preg_match('~^[a-z0-9]{1,10}$~', $ext) && !\in_array($ext, self::BLOCKED, true)) {
                $out[$ext] = $ext;
            }
        }

        return array_values($out);
    }

    /**
     * Проверить присланное в одном поле. `$raw` — то, что дал `$input->files`: один файл или список файлов.
     *
     * @param array<string, mixed> $field поле формы
     * @param mixed                $raw
     *
     * @return array{items: array<int, array<string, mixed>>, error: string}
     */
    public static function validate(array $field, mixed $raw): array
    {
        $uploads = self::normalize($raw);
        $custom  = trim((string) $field['error_text']);
        $err     = static fn (string $key, mixed ...$args): array => ['items' => [], 'error' => $custom !== '' ? $custom : ($args ? Text::sprintf($key, ...$args) : Text::_($key))];

        if (!$uploads) {
            return !empty($field['required']) ? $err('COM_DHARMAFORM_FORM_ERROR_REQUIRED') : ['items' => [], 'error' => ''];
        }

        $max     = !empty($field['file_multiple']) ? max(1, (int) $field['file_max_count']) : 1;
        $limit   = max(1, (int) $field['file_max_mb']) * 1048576;
        $allowed = self::allowed((string) $field['file_accept']);
        $items   = [];

        if (\count($uploads) > $max) {
            return $err('COM_DHARMAFORM_FORM_ERROR_FILE_COUNT', $max);
        }

        foreach ($uploads as $file) {
            if ((int) $file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file((string) $file['tmp_name'])) {
                return $err((int) $file['error'] === UPLOAD_ERR_INI_SIZE || (int) $file['error'] === UPLOAD_ERR_FORM_SIZE ? 'COM_DHARMAFORM_FORM_ERROR_FILE_SIZE' : 'COM_DHARMAFORM_FORM_ERROR_FILE_UPLOAD', max(1, (int) $field['file_max_mb']));
            }

            $name = self::cleanName((string) $file['name']);
            $ext  = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));

            if ($ext === '' || !\in_array($ext, $allowed, true)) {
                return $err('COM_DHARMAFORM_FORM_ERROR_FILE_TYPE', implode(', ', $allowed));
            }

            $size = (int) $file['size'];

            if ($size < 1 || $size > $limit) {
                return $err('COM_DHARMAFORM_FORM_ERROR_FILE_SIZE', max(1, (int) $field['file_max_mb']));
            }

            $mime = self::mime((string) $file['tmp_name']);

            if (!self::contentOk((string) $file['tmp_name'], $ext, $mime)) {
                return $err('COM_DHARMAFORM_FORM_ERROR_FILE_TYPE', implode(', ', $allowed));
            }

            $items[] = ['field' => (string) $field['name'], 'tmp' => (string) $file['tmp_name'], 'name' => $name, 'ext' => $ext, 'size' => $size, 'mime' => $mime];
        }

        return ['items' => $items, 'error' => ''];
    }

    /**
     * Сохранить проверенные файлы.
     *
     * @param array<int, array<string, mixed>> $items результат `validate`
     *
     * @return array<int, array{field: string, name: string, path: string, size: int, mime: string}> то, что записывается в заявку
     */
    public static function store(array $items): array
    {
        if (!$items) {
            return [];
        }

        $base = self::baseDir();
        $sub  = gmdate('Y') . '/' . gmdate('m');
        $dir  = $base . '/' . $sub;

        self::protect($base);

        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            return [];
        }

        $out = [];

        foreach ($items as $item) {
            $stored = $sub . '/' . bin2hex(random_bytes(16)) . '.' . $item['ext'];

            if (!@move_uploaded_file((string) $item['tmp'], $base . '/' . $stored)) {
                continue;
            }

            @chmod($base . '/' . $stored, 0640);
            $out[] = ['field' => (string) $item['field'], 'name' => (string) $item['name'], 'path' => $stored, 'size' => (int) $item['size'], 'mime' => (string) $item['mime']];
        }

        return $out;
    }

    /** Полный путь сохранённого файла или null, если путь не наш или файла нет. */
    public static function resolve(string $stored): ?string
    {
        if (!preg_match(self::STORED, $stored)) {
            return null;
        }

        $path = self::baseDir() . '/' . $stored;

        return is_file($path) ? $path : null;
    }

    /**
     * Удалить файлы заявок.
     *
     * @param array<int, mixed> $files список из поля `files` заявки (после `json_decode`)
     *
     * @return int сколько файлов удалено
     */
    public static function deleteFiles(array $files): int
    {
        $count = 0;

        foreach ($files as $file) {
            $path = \is_array($file) && isset($file['path']) ? self::resolve((string) $file['path']) : null;

            if ($path !== null && @unlink($path)) {
                $count++;
            }
        }

        return $count;
    }

    /** Один файл или список файлов из `$input->files` — единым списком записей `$_FILES`. @return array<int, array<string, mixed>> */
    private static function normalize(mixed $raw): array
    {
        if (!\is_array($raw)) {
            return [];
        }

        $list = isset($raw['tmp_name']) ? [$raw] : array_values(array_filter($raw, 'is_array'));
        $out  = [];

        foreach ($list as $file) {
            if (isset($file['tmp_name'], $file['name'], $file['error'], $file['size']) && \is_string($file['tmp_name']) && (int) $file['error'] !== UPLOAD_ERR_NO_FILE) {
                $out[] = $file;
            }
        }

        return $out;
    }

    /** Имя для показа: без пути, тегов и управляющих знаков, до 100 знаков. */
    private static function cleanName(string $name): string
    {
        $name = basename(str_replace('\\', '/', strip_tags($name)));
        $name = trim((string) preg_replace('~[\x00-\x1F\x7F<>:"|?*]+~u', '', $name));

        if (mb_strlen($name) > 100) {
            $ext  = (string) pathinfo($name, PATHINFO_EXTENSION);
            $name = mb_substr($name, 0, 100 - \strlen($ext) - 1) . '.' . $ext;
        }

        return $name;
    }

    private static function mime(string $file): string
    {
        if (!\function_exists('finfo_open')) {
            return '';
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime  = $finfo ? (string) finfo_file($finfo, $file) : '';

        return $mime;
    }

    /** Содержимое соответствует расширению: картинки и PDF — по типу, остальное — без кода PHP и HTML в начале файла. */
    private static function contentOk(string $file, string $ext, string $mime): bool
    {
        if (isset(self::IMAGES[$ext])) {
            return $mime === '' || str_starts_with($mime, 'image/');
        }

        if ($ext === 'pdf') {
            return $mime === '' || $mime === 'application/pdf';
        }

        $head = (string) @file_get_contents($file, false, null, 0, 4096);

        return !preg_match('~<\?(?:php|=)|<script\b|<html\b|<!doctype html~i', $head);
    }

    /** Закрыть каталог загрузок от прямого доступа (Apache, IIS) и от показа списка файлов. */
    private static function protect(string $base): void
    {
        if (!is_dir($base) && !@mkdir($base, 0750, true) && !is_dir($base)) {
            return;
        }

        $files = [
            '.htaccess'  => "Require all denied\n<IfModule !mod_authz_core.c>\nOrder deny,allow\nDeny from all\n</IfModule>\n",
            'web.config' => "<?xml version=\"1.0\" encoding=\"utf-8\"?>\n<configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>\n",
            'index.html' => "<!DOCTYPE html><title></title>\n",
        ];

        foreach ($files as $name => $content) {
            if (!is_file($base . '/' . $name)) {
                @file_put_contents($base . '/' . $name, $content);
            }
        }
    }
}
