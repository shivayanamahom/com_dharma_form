<?php

declare(strict_types=1);

namespace Dharma\Component\DharmaForm\Site\Service;

use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;

\defined('_JEXEC') or die;

/** Лимиты запросов в фиксированных временных окнах, общие для всех PHP-воркеров. */
final class RequestThrottle
{
    public static function allow(string $scope, int $limit, int $seconds): bool
    {
        $now = time();
        $window = intdiv($now, $seconds) * $seconds;
        $db = Factory::getContainer()->get(DatabaseInterface::class);
        $table = $db->quoteName('#__dharmaform_request_limits');
        $key = $db->quote(SubmissionGuard::ipHash($scope));

        if (random_int(1, 100) === 1) {
            $db->setQuery("DELETE FROM $table WHERE window_start < " . ($now - 7200) . ' LIMIT 1000')->execute();
        }

        $db->setQuery("INSERT IGNORE INTO $table (scope_hash, window_start, hits) VALUES ($key, $window, 0)")->execute();
        // Обновление под блокировкой строки: параллельные запросы не превышают лимит.
        $db->setQuery("UPDATE $table SET hits = hits + 1 WHERE scope_hash = $key AND window_start = $window AND hits < $limit")->execute();
        return $db->getAffectedRows() === 1;
    }
}
