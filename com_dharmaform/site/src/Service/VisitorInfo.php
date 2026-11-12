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
use Joomla\CMS\Component\ComponentHelper;

\defined('_JEXEC') or die;

/**
 * Дополнительные данные о посетителе. Собирается только то, что включено в настройках компонента (по умолчанию ничего):
 * адрес, браузер с системой и типом устройства, язык, откуда пришёл, размер экрана и часовой пояс. Это персональные данные:
 * они хранятся вместе с заявкой и удаляются вместе с ней.
 */
final class VisitorInfo
{
    /**
     * @param array<string, string> $server `ua`, `lang` из заголовков запроса
     * @param string                $env    JSON от скрипта формы: `ref`, `w`, `h`, `tz`
     *
     * @return array<string, string> ключ => значение (`ip`, `browser`, `os`, `device`, `lang`, `ref`, `screen`, `tz`)
     */
    public static function collect(string $ip, array $server, string $env): array
    {
        $params = ComponentHelper::getParams('com_dharmaform');
        $meta   = [];

        if ($params->get('collect_ip', 0) && $ip !== '') {
            $meta['ip'] = mb_substr($ip, 0, 45);
        }

        if ($params->get('collect_browser', 0)) {
            $meta += self::parseAgent((string) ($server['ua'] ?? ''));
        }

        if ($params->get('collect_lang', 0)) {
            $lang = trim(explode(',', (string) ($server['lang'] ?? ''))[0]);

            if (preg_match('~^[A-Za-z]{2,3}(?:-[A-Za-z0-9]{2,8})?~', $lang, $m)) {
                $meta['lang'] = $m[0];
            }
        }

        $data = json_decode($env, true);
        $data = \is_array($data) ? $data : [];

        if ($params->get('collect_referrer', 0)) {
            $ref = FormSanitizer::url((string) ($data['ref'] ?? ''));

            if ($ref !== '' && preg_match('~^https?://~i', $ref)) {
                $meta['ref'] = mb_substr($ref, 0, 300);
            }
        }

        if ($params->get('collect_screen', 0)) {
            $w = (int) ($data['w'] ?? 0);
            $h = (int) ($data['h'] ?? 0);

            if ($w > 0 && $w < 20000 && $h > 0 && $h < 20000) {
                $meta['screen'] = $w . '×' . $h;
            }

            if (preg_match('~^[A-Za-z_]+(?:/[A-Za-z0-9_+-]+)*$~', (string) ($data['tz'] ?? '')) && \strlen((string) $data['tz']) <= 64) {
                $meta['tz'] = (string) $data['tz'];
            }
        }

        return $meta;
    }

    /** @return array<string, string> `browser`, `os`, `device` */
    public static function parseAgent(string $ua): array
    {
        $ua = mb_substr($ua, 0, 400);

        if ($ua === '') {
            return [];
        }

        $browser = '';

        foreach ([
            'Yandex' => '~YaBrowser/(\d+)~', 'Edge' => '~Edg(?:e|A|iOS)?/(\d+)~', 'Opera' => '~OPR/(\d+)~', 'Samsung Browser' => '~SamsungBrowser/(\d+)~',
            'Firefox' => '~(?:Firefox|FxiOS)/(\d+)~', 'Chrome' => '~(?:Chrome|CriOS)/(\d+)~', 'Safari' => '~Version/(\d+).*Safari/~',
        ] as $name => $regex) {
            if (preg_match($regex, $ua, $m)) {
                $browser = $name . ' ' . $m[1];
                break;
            }
        }

        $os = match (true) {
            (bool) preg_match('~Windows NT 10~', $ua)  => 'Windows 10/11',
            (bool) preg_match('~Windows~', $ua)        => 'Windows',
            (bool) preg_match('~Android ([\d.]+)~', $ua, $m) => 'Android ' . $m[1],
            (bool) preg_match('~iPhone|iPad|iPod~', $ua) => 'iOS',
            (bool) preg_match('~Mac OS X~', $ua)       => 'macOS',
            (bool) preg_match('~CrOS~', $ua)           => 'ChromeOS',
            (bool) preg_match('~Linux~', $ua)          => 'Linux',
            default                                    => '',
        };

        $device = match (true) {
            (bool) preg_match('~iPad|Tablet|Android(?!.*Mobile)~i', $ua) => 'tablet',
            (bool) preg_match('~Mobile|iPhone|Android~i', $ua)           => 'mobile',
            default                                                       => 'desktop',
        };

        return array_filter(['browser' => $browser, 'os' => $os, 'device' => $device], static fn (string $v): bool => $v !== '');
    }
}
