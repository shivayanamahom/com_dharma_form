<?php

/**
 * Dharma Form — универсальные формы для Joomla 5.2+ и 6+.
 *
 * @package    com_dharmaform
 * @copyright  (C) 2026 Dharma. Все права защищены.
 * @license    GNU General Public License версии 2 или новее; см. LICENSE.txt
 */

declare(strict_types=1);

namespace Dharma\Component\DharmaForm\Administrator\Helper;

use Joomla\CMS\Language\Text;

\defined('_JEXEC') or die;

/** Причины, по которым защита форм набрала баллы: коды из `spam_reason` в понятные подписи. */
final class SpamReasons
{
    public const CODES = ['honeypot', 'stamp', 'replay', 'too_fast', 'pow', 'behavior', 'links', 'stopwords', 'script', 'gibberish', 'disposable', 'mx', 'flood', 'blacklist', 'rate'];

    /** @return array<string, string> код => подпись (неизвестный код остаётся как есть) */
    public static function labels(string $csv): array
    {
        $out = [];

        foreach (preg_split('~[\s,]+~', $csv, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $code) {
            $out[$code] = \in_array($code, self::CODES, true) ? Text::_('COM_DHARMAFORM_SPAM_REASON_' . strtoupper($code)) : $code;
        }

        return $out;
    }
}
