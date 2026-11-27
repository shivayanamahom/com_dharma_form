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
    public const BLOCKED = [
        'php', 'php2', 'php3', 'php4', 'php5', 'php6', 'php7', 'php8', 'phps', 'phtml', 'phar', 'pht', 'pgif', 'inc', 'module',
        'asp', 'aspx', 'ashx', 'asmx', 'asa', 'cer', 'cdx', 'shtm', 'xht', 'svgz', 'cfg', 'phtm', 'jsp', 'jspx', 'cfm', 'pl', 'py', 'rb', 'cgi', 'sh', 'bash', 'zsh', 'exe', 'bat', 'cmd', 'com', 'scr', 'msi', 'dll',
        'js', 'mjs', 'html', 'htm', 'xhtml', 'shtml', 'xml', 'svg', 'swf', 'hta', 'htaccess', 'htpasswd', 'user', 'ini', 'jar', 'war', 'vbs', 'vbe', 'wsf', 'ps1', 'lnk', 'url', 'docm', 'xlsm', 'pptm', 'dotm',
    ];

    /** Разрешённые типы по умолчанию (пока в настройках компонента ничего не сохранено). */
    public const DEFAULT_ALLOWED = 'jpg, jpeg, png, gif, webp, pdf, docx, xlsx, pptx, odt, ods';

    private const MIME_TYPES = [
        'jpg' => ['image/jpeg'], 'jpeg' => ['image/jpeg'], 'png' => ['image/png'], 'gif' => ['image/gif'], 'webp' => ['image/webp'],
        'pdf' => ['application/pdf'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'],
        'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip'],
        'odt' => ['application/vnd.oasis.opendocument.text', 'application/zip'],
        'ods' => ['application/vnd.oasis.opendocument.spreadsheet', 'application/zip'],
        'odp' => ['application/vnd.oasis.opendocument.presentation', 'application/zip'],
        'txt' => ['text/plain'], 'csv' => ['text/plain', 'text/csv', 'application/csv'],
        'doc' => ['application/msword'], 'xls' => ['application/vnd.ms-excel'], 'ppt' => ['application/vnd.ms-powerpoint'],
        'rtf' => ['application/rtf', 'text/rtf'], 'zip' => ['application/zip'],
        'rar' => ['application/vnd.rar', 'application/x-rar'], '7z' => ['application/x-7z-compressed'],
    ];

    private const OFFICE_PARTS = [
        'docx' => ['word/document.xml', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml'],
        'xlsx' => ['xl/workbook.xml', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml'],
        'pptx' => ['ppt/presentation.xml', 'application/vnd.openxmlformats-officedocument.presentationml.presentation.main+xml'],
    ];

    /** Начало содержимого, по которому тип файла подтверждается независимо от расширения (регулярные выражения для первых байт). */
    private const SIGNATURES = [
        'jpg'  => '~^\xFF\xD8\xFF~', 'jpeg' => '~^\xFF\xD8\xFF~', 'png' => '~^\x89PNG\r\n\x1A\n~', 'gif' => '~^GIF8[79]a~', 'webp' => '~^RIFF.{4}WEBP~s',
        'pdf'  => '~^.{0,1024}%PDF-~s',
        'docx' => '~^PK\x03\x04~', 'xlsx' => '~^PK\x03\x04~', 'pptx' => '~^PK\x03\x04~', 'odt' => '~^PK\x03\x04~', 'ods' => '~^PK\x03\x04~', 'odp' => '~^PK\x03\x04~', 'zip' => '~^PK\x03\x04~',
        'doc'  => '~^\xD0\xCF\x11\xE0~', 'xls' => '~^\xD0\xCF\x11\xE0~', 'ppt' => '~^\xD0\xCF\x11\xE0~',
        'rar'  => '~^Rar!\x1A\x07~', '7z' => '~^7z\xBC\xAF\x27\x1C~',
    ];

    /** Расширение, под которым файл лежит на диске: никакого исполняемого или «умного» типа, поэтому сервер его не выполнит, даже если закрытие каталога не сработало. */
    private const DISK_EXT = 'dat';

    private const STORED = '~^\d{4}/\d{2}/[a-f0-9]{32}\.[a-z0-9]{1,10}$~';

    /** Каталоги движка Joomla: в них загрузки хранить нельзя. */
    private const FORBIDDEN_DIRS = ['administrator', 'cache', 'components', 'includes', 'language', 'layouts', 'libraries', 'modules', 'plugins', 'templates', 'tmp', 'api', 'cli', 'installation', 'media'];

    /**
     * Каталог загрузок: из настроек компонента, путь от корня сайта или абсолютный. Корень сайта, каталоги движка Joomla
     * (`administrator`, `libraries`, `plugins`, `media` и т. п.) и пути с `..` не принимаются: туда кладётся файл закрытия
     * доступа, а загруженное не должно оказаться среди кода сайта. Вместо них берётся каталог по умолчанию.
     */
    public static function baseDir(): string
    {
        $default = str_replace('\\', '/', JPATH_ROOT) . '/files/dharmaform';
        $dir     = trim((string) ComponentHelper::getParams('com_dharmaform')->get('upload_dir', ''));

        if ($dir === '') {
            return $default;
        }

        if (!str_starts_with($dir, '/') && !preg_match('~^[A-Za-z]:[\\\\/]~', $dir)) {
            $dir = JPATH_ROOT . '/' . $dir;
        }

        $dir  = rtrim(str_replace('\\', '/', $dir), '/');
        $root = rtrim(str_replace('\\', '/', JPATH_ROOT), '/');

        if ($dir === '' || str_contains($dir, '..') || str_contains($dir, "\0") || $dir === $root || str_starts_with($root . '/', $dir . '/')) {
            return $default;
        }

        if (str_starts_with($dir, $root . '/')) {
            $first = explode('/', substr($dir, \strlen($root) + 1))[0];

            if (\in_array(strtolower($first), self::FORBIDDEN_DIRS, true)) {
                return $default;
            }
        }

        return $dir;
    }

    /** Расширения из списка: строчные буквы и цифры без точки, без всегда запрещённых. @return string[] */
    private static function parse(string $list): array
    {
        $out = [];

        foreach (preg_split('~[\s,;]+~', strtolower($list), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $ext) {
            $ext = ltrim($ext, '.');

            if (isset(self::MIME_TYPES[$ext])) {
                $out[$ext] = $ext;
            }
        }

        return array_values($out);
    }

    /** Типы, разрешённые настройками компонента для всех форм. @return string[] */
    public static function globalAllowed(): array
    {
        $list = trim((string) ComponentHelper::getParams('com_dharmaform')->get('upload_allowed', ''));

        return self::parse($list !== '' ? $list : self::DEFAULT_ALLOWED);
    }

    /** Типы поля (`file_accept`) в пределах разрешённых в настройках; пустой список поля — всё разрешённое в настройках. @return string[] */
    public static function allowed(string $accept): array
    {
        $global = self::globalAllowed();
        $field  = self::parse($accept);

        return trim($accept) !== '' ? array_values(array_intersect($field, $global)) : $global;
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

            $size = (int) @filesize((string) $file['tmp_name']);

            if ($size < 1 || $size > $limit) {
                return $err('COM_DHARMAFORM_FORM_ERROR_FILE_SIZE', max(1, (int) $field['file_max_mb']));
            }

            $mime = self::verifiedMime((string) $file['tmp_name'], $ext);

            if ($mime === null) {
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
            $stored = $sub . '/' . bin2hex(random_bytes(16)) . '.' . self::DISK_EXT;

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
        $name = trim((string) preg_replace('~[\x00-\x1F\x7F<>:"|?*\p{Cf}\p{Zl}\p{Zp}]+~u', '', $name), " .\t");

        if (mb_strlen($name) > 100) {
            $ext  = (string) pathinfo($name, PATHINFO_EXTENSION);
            $name = mb_substr($name, 0, 100 - \strlen($ext) - 1) . '.' . $ext;
        }

        return $name;
    }

    public static function verifiedMime(string $file, string $ext): ?string
    {
        if (!isset(self::MIME_TYPES[$ext]) || !\class_exists(\finfo::class)) {
            return null;
        }

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file);
        if (!\in_array($mime, self::MIME_TYPES[$ext], true) || !self::contentOk($file, $ext)) {
            return null;
        }

        return $mime;
    }

    /**
     * Содержимое подтверждает тип: начало файла (подпись) должно соответствовать расширению, а у текстовых и неизвестных
     * типов в начале не должно быть кода PHP и HTML.
     */
    private static function contentOk(string $file, string $ext): bool
    {
        $head = (string) @file_get_contents($file, false, null, 0, 4096);

        if ($head === '') {
            return false;
        }

        if ($ext === 'rtf') {
            return str_starts_with($head, '{\rtf');
        }

        if (isset(self::SIGNATURES[$ext]) && !preg_match(self::SIGNATURES[$ext], $head)) {
            return false;
        }

        if (isset(self::OFFICE_PARTS[$ext]) || \in_array($ext, ['odt', 'ods', 'odp'], true)) {
            return self::officeArchiveOk($file, $ext);
        }

        if ($ext === 'txt' || $ext === 'csv') {
            $stream = @fopen($file, 'rb');
            if (!$stream) {
                return false;
            }
            $tail = '';
            try {
                while (!feof($stream)) {
                    $chunk = fread($stream, 8192);
                    if ($chunk === false || preg_match('~[\x00-\x08\x0B\x0C\x0E-\x1F]|<\?(?:php|=)|<script\b|<html\b|<!doctype\s+html|<svg\b~i', $tail . $chunk)) {
                        return false;
                    }
                    $tail = substr($chunk, -64);
                }
            } finally {
                fclose($stream);
            }
        }

        return true;
    }

    private static function officeArchiveOk(string $file, string $ext): bool
    {
        if (!\class_exists(\ZipArchive::class)) {
            return false;
        }
        $archive = new \ZipArchive();
        if ($archive->open($file, \ZipArchive::RDONLY) !== true) {
            return false;
        }
        try {
            for ($i = 0; $i < $archive->numFiles; ++$i) {
                $name = strtolower(str_replace('\\', '/', (string) $archive->getNameIndex($i)));
                if (preg_match('~(?:^|/)(?:vbaproject[^/]*|activex|embeddings|basic|scripts)(?:/|$)~', $name)) {
                    return false;
                }
            }

            if (!isset(self::OFFICE_PARTS[$ext])) {
                return $archive->getFromName('mimetype', 128) === self::MIME_TYPES[$ext][0]
                    && $archive->locateName('content.xml') !== false;
            }

            [$part, $type] = self::OFFICE_PARTS[$ext];
            $manifest = $archive->statName('[Content_Types].xml');
            if ($manifest === false || $manifest['size'] > 1048576 || $archive->locateName($part) === false) {
                return false;
            }
            $contents = $archive->getFromName('[Content_Types].xml');
            if ($contents === false || stripos($contents, '<!DOCTYPE') !== false || stripos($contents, 'macroEnabled') !== false) {
                return false;
            }
            $xml = @simplexml_load_string($contents, \SimpleXMLElement::class, LIBXML_NONET);
            if ($xml === false) {
                return false;
            }
            foreach ($xml->children('http://schemas.openxmlformats.org/package/2006/content-types') as $entry) {
                $attributes = $entry->attributes();
                if ($entry->getName() === 'Override' && (string) $attributes['PartName'] === '/' . $part && (string) $attributes['ContentType'] === $type) {
                    return true;
                }
            }
            return false;
        } finally {
            $archive->close();
        }
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
