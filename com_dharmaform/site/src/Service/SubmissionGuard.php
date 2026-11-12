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
use Joomla\CMS\Factory;

\defined('_JEXEC') or die;

/**
 * Защита приёма: несколько независимых слабых сигналов складываются в оценку (балл), заявка с оценкой не ниже порога
 * (`protect_score_limit`) помечается спамом — не отклоняется: посетитель видит обычное «принято», заявка остаётся в журнале.
 * Мгновенные признаки бота (ловушка, чужая метка, повтор метки, адрес из чёрного списка, нерешённая proof-of-work) дают
 * сразу максимум. Всё работает на своём сервере: без внешних капч и сторонних запросов.
 *
 * Сигналы: поле-ловушка (постоянное и случайное по метке), подписанная метка времени с одноразовым номером, слишком быстрая
 * отправка, proof-of-work (невидимая «капча»: браузер подбирает число, от которого хеш метки начинается с нулевых бит),
 * признак присутствия человека (движения, клавиши, касания в форме), ссылки, стоп-слова (форма + общие), чужие алфавиты,
 * «абракадабра», одноразовая почта, отсутствие MX у домена почты, чёрный и белый списки адресов, лавина заявок на форму.
 */
final class SubmissionGuard
{
    public const MAX_AGE = 7200;

    public const INSTANT = 10;

    /** Домены одноразовой почты; свои добавляются в настройках компонента. */
    private const DISPOSABLE = [
        'mailinator.com', 'guerrillamail.com', 'guerrillamail.net', 'guerrillamail.org', 'sharklasers.com', 'grr.la', '10minutemail.com', '10minutemail.net',
        'temp-mail.org', 'temp-mail.ru', 'tempmail.com', 'tempmail.net', 'tempmailo.com', 'tmpmail.org', 'tmpmail.net', 'yopmail.com', 'yopmail.net', 'yopmail.fr',
        'trashmail.com', 'trashmail.net', 'trashmail.de', 'throwawaymail.com', 'getnada.com', 'nada.email', 'maildrop.cc', 'dispostable.com', 'fakeinbox.com',
        'mintemail.com', 'mohmal.com', 'moakt.com', 'emailondeck.com', 'burnermail.io', 'mailnesia.com', 'mailcatch.com', 'spamgourmet.com', 'spambox.us',
        'discard.email', 'discardmail.com', 'dropmail.me', 'fakemail.net', 'fakemailgenerator.com', 'getairmail.com', 'harakirimail.com', 'inboxbear.com',
        'jetable.org', 'mytemp.email', 'mailforspam.com', 'mailtemp.info', 'mail-temp.com', 'nospam.ze.tc', 'owlymail.com', 'spam4.me', 'tempinbox.com',
        'tempr.email', 'trbvm.com', 'tmail.ws', 'tmails.net', 'emailfake.com', 'email-fake.com', 'crazymailing.com', 'luxusmail.org', 'mail.tm', 'internxt.com',
        'byom.de', 'cs.email', 'linshiyou.com', 'minuteinbox.com', 'secmail.pro', 'vusra.com', 'wegwerfmail.de', 'zetmail.com', 'rhyta.com', 'dayrep.com',
    ];

    public static function secret(): string
    {
        return (string) Factory::getApplication()->get('secret');
    }

    /** Метка показа формы: время, одноразовый номер и подпись, чтобы её нельзя было подделать. */
    public static function stamp(?int $time = null): string
    {
        $base = ($time ?? time()) . '.' . bin2hex(random_bytes(6));

        return $base . '.' . hash_hmac('sha256', $base, self::secret());
    }

    /** Имя случайной ловушки: зависит от метки, поэтому у бота нет постоянного имени, под которое можно подстроиться. */
    public static function honeypotName(string $stamp): string
    {
        return 'hp_' . substr(hash_hmac('sha256', 'hp' . $stamp, self::secret()), 0, 10);
    }

    /** Хеш адреса для ограничения частоты: сам адрес не хранится. */
    public static function ipHash(string $ip): string
    {
        return hash_hmac('sha256', $ip, self::secret());
    }

    /**
     * Осмотр присланного.
     *
     * @param array<string, mixed> $data   `website`, `hp_*` (ловушки), `stamp`, `pow`, `dfh` (присутствие человека)
     * @param array<string, mixed> $params настройки формы (`FormConfig::DEFAULTS`)
     * @param array<string, string> $extra  `text` (все значения полей одной строкой), `email`, `ip`, `form_id`, `recent_form` (заявок на форму за 10 минут)
     *
     * @return array{spam: bool, score: int, reasons: string[]}
     */
    public static function inspect(array $data, array $params, array $extra): array
    {
        $global  = ComponentHelper::getParams('com_dharmaform');
        $ip      = (string) ($extra['ip'] ?? '');
        $text    = (string) ($extra['text'] ?? '');
        $email   = strtolower(trim((string) ($extra['email'] ?? '')));
        $score   = 0;
        $reasons = [];
        $add     = static function (string $reason, int $points) use (&$score, &$reasons): void {
            $score    += $points;
            $reasons[] = $reason;
        };

        if ($ip !== '' && self::ipInList($ip, (string) $global->get('spam_ip_whitelist', ''))) {
            return ['spam' => false, 'score' => 0, 'reasons' => []];
        }

        if ($ip !== '' && self::ipInList($ip, (string) $global->get('spam_ip_blacklist', ''))) {
            $add('blacklist', self::INSTANT);
        }

        if (!empty($params['protect_honeypot'])) {
            $trap = trim((string) ($data['website'] ?? ''));
            $name = self::honeypotName((string) ($data['stamp'] ?? ''));

            if ($trap !== '' || trim((string) ($data[$name] ?? '')) !== '') {
                $add('honeypot', self::INSTANT);
            }
        }

        $parts = array_pad(explode('.', (string) ($data['stamp'] ?? ''), 3), 3, '');
        [$time, $nonce, $sign] = $parts;
        $age = time() - (int) $time;

        if (!ctype_digit($time) || $nonce === '' || !hash_equals(hash_hmac('sha256', $time . '.' . $nonce, self::secret()), $sign) || $age > self::MAX_AGE) {
            $add('stamp', self::INSTANT);
        } else {
            if (!empty($params['protect_replay']) && !self::useNonce($nonce)) {
                $add('replay', self::INSTANT);
            }

            if ($age < (int) ($params['protect_min_seconds'] ?? 0)) {
                $add('too_fast', 3);
            }

            if (!empty($params['protect_pow']) && !self::powValid((string) ($data['stamp'] ?? ''), (string) ($data['pow'] ?? ''), (int) ($params['protect_pow_bits'] ?? 14))) {
                $add('pow', self::INSTANT);
            }
        }

        if (!empty($params['protect_behavior']) && !self::humanSeen((string) ($data['dfh'] ?? ''))) {
            $add('behavior', 3);
        }

        if (!empty($params['protect_links']) && preg_match_all('~https?://|www\.|[a-z0-9-]+\.(?:ru|com|net|org|info|biz|xyz|top|site|online)\b~iu', $text) >= 1) {
            $add('links', 2);
        }

        $stop = (string) ($params['protect_stopwords'] ?? '') . "\n" . (string) $global->get('spam_stopwords', '');

        foreach (preg_split('~\R~u', $stop) ?: [] as $word) {
            $word = trim($word);

            if ($word !== '' && mb_stripos($text, $word) !== false) {
                $add('stopwords', 4);
                break;
            }
        }

        if (!empty($params['protect_scripts']) && preg_match('~[\p{Han}\p{Hiragana}\p{Katakana}\p{Hangul}\p{Arabic}\p{Hebrew}\p{Thai}\p{Devanagari}]~u', $text)) {
            $add('script', 3);
        }

        if (!empty($params['protect_gibberish']) && self::gibberish($text)) {
            $add('gibberish', 2);
        }

        if ($email !== '' && str_contains($email, '@')) {
            $domain = substr($email, (int) strrpos($email, '@') + 1);

            if (!empty($params['protect_disposable']) && self::disposable($domain, (string) $global->get('spam_disposable_extra', ''))) {
                $add('disposable', 3);
            }

            if (!empty($params['protect_mx']) && !self::hasMx($domain)) {
                $add('mx', 2);
            }
        }

        if (!empty($extra['rate_hit'])) {
            $add('rate', self::INSTANT);
        }

        $flood = (int) ($params['protect_flood_limit'] ?? 0);

        if ($flood > 0 && (int) ($extra['recent_form'] ?? 0) >= $flood) {
            $add('flood', 3);
        }

        $limit = max(1, (int) ($params['protect_score_limit'] ?? 3));

        return ['spam' => $score >= $limit, 'score' => min(255, $score), 'reasons' => $reasons];
    }

    /** Хеш `sha256(метка:число)` начинается с нужного числа нулевых бит. */
    public static function powValid(string $stamp, string $solution, int $bits): bool
    {
        $bits = min(24, max(8, $bits));

        if ($stamp === '' || !preg_match('~^\d{1,12}$~', $solution)) {
            return false;
        }

        $hash = hash('sha256', $stamp . ':' . $solution, true);
        $full = intdiv($bits, 8);

        if (substr($hash, 0, $full) !== str_repeat("\0", $full)) {
            return false;
        }

        $rest = $bits % 8;

        return $rest === 0 || (\ord($hash[$full]) >> (8 - $rest)) === 0;
    }

    /** `dfh` = «число событий.миллисекунды от первого касания»: человек оставляет хотя бы одно событие и тратит время. */
    private static function humanSeen(string $value): bool
    {
        if (!preg_match('~^(\d{1,5})\.(\d{1,9})$~', $value, $m)) {
            return false;
        }

        return (int) $m[1] >= 1 && (int) $m[2] >= 400;
    }

    /**
     * Одноразовый номер метки: повторная отправка с той же меткой (повтор запроса ботом, параллельные запросы) отклоняется.
     * Занять номер — одна вставка в таблицу с уникальным ключом: двое одновременно его не займут.
     */
    private static function useNonce(string $nonce): bool
    {
        if (!preg_match('~^[a-f0-9]{12}$~', $nonce)) {
            return false;
        }

        try {
            $db  = Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
            $now = Factory::getDate();

            $db->setQuery('INSERT IGNORE INTO ' . $db->quoteName('#__dharmaform_nonces') . ' (nonce, created) VALUES (' . $db->quote($nonce) . ', ' . $db->quote($now->toSql()) . ')')->execute();
            $first = $db->getAffectedRows() === 1;

            // Старые номера (метка живёт два часа) убираются изредка, чтобы таблица не росла.
            if (random_int(1, 50) === 1) {
                $db->setQuery('DELETE FROM ' . $db->quoteName('#__dharmaform_nonces') . ' WHERE created < ' . $db->quote(Factory::getDate('-3 hours')->toSql()))->execute();
            }

            return $first;
        } catch (\Throwable) {
            return true;
        }
    }

    /** Очевидная «абракадабра»: длинные цепочки согласных, повторы одного знака, случайный регистр, сплошные заглавные. */
    private static function gibberish(string $text): bool
    {
        if (preg_match('~(.)\1{5,}~u', $text)) {
            return true;
        }

        if (preg_match('~[bcdfghjklmnpqrstvwxz]{7,}|[бвгджзйклмнпрстфхцчшщ]{7,}~iu', $text)) {
            return true;
        }

        foreach (preg_split('~[\s,.;:!?()\-]+~u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            if (mb_strlen($word) >= 7 && preg_match_all('~(?<=\p{Ll})\p{Lu}~u', $word) >= 3) {
                return true;
            }
        }

        $letters = (string) preg_replace('~[^\p{L}]+~u', '', $text);

        return mb_strlen($letters) >= 14 && mb_strtoupper($letters) === $letters && mb_strtolower($letters) !== $letters;
    }

    private static function disposable(string $domain, string $extra): bool
    {
        $list = self::DISPOSABLE;

        foreach (preg_split('~[\s,;]+~', strtolower($extra), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $item) {
            $list[] = $item;
        }

        return \in_array($domain, $list, true);
    }

    private static function hasMx(string $domain): bool
    {
        if (!\function_exists('checkdnsrr') || !preg_match('~^[a-z0-9.-]+$~', $domain)) {
            return true;
        }

        return @checkdnsrr($domain . '.', 'MX') || @checkdnsrr($domain . '.', 'A');
    }

    /** Адрес входит в список: по одному в строке, точный или сеть (`10.0.0.0/8`, `2001:db8::/32`). */
    public static function ipInList(string $ip, string $list): bool
    {
        $packed = @inet_pton($ip);

        if ($packed === false) {
            return false;
        }

        foreach (preg_split('~[\s,;]+~', trim($list), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $entry) {
            [$net, $bits] = array_pad(explode('/', $entry, 2), 2, null);
            $base         = @inet_pton((string) $net);

            if ($base === false || \strlen($base) !== \strlen($packed)) {
                continue;
            }

            $max  = \strlen($base) * 8;
            $bits = $bits === null || !ctype_digit((string) $bits) ? $max : min($max, (int) $bits);
            $full = intdiv($bits, 8);

            if (substr($packed, 0, $full) !== substr($base, 0, $full)) {
                continue;
            }

            $rest = $bits % 8;

            if ($rest === 0 || ((\ord($packed[$full]) ^ \ord($base[$full])) >> (8 - $rest)) === 0) {
                return true;
            }
        }

        return false;
    }
}
