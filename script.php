<?php

/**
 * Dharma Form — универсальные формы для Joomla 5.2+ и 6+.
 *
 * @package    pkg_dharma_form
 * @copyright  (C) 2026 Dharma. Все права защищены.
 * @license    GNU General Public License версии 2 или новее; см. LICENSE.txt
 */

declare(strict_types=1);

use Joomla\CMS\Factory;
use Joomla\CMS\Installer\InstallerAdapter;
use Joomla\CMS\Installer\InstallerScriptInterface;
use Joomla\Database\DatabaseInterface;

\defined('_JEXEC') or die;

return new class () implements InstallerScriptInterface {
    private const MIN_PHP = '8.2.0';

    private const MIN_JOOMLA = '5.2.0';

    public function install(InstallerAdapter $adapter): bool
    {
        return true;
    }

    public function update(InstallerAdapter $adapter): bool
    {
        return true;
    }

    public function uninstall(InstallerAdapter $adapter): bool
    {
        // Таблицы заявок удаляются вместе с компонентом, а загруженные файлы остаются на диске: предупреждаем, где они лежат.
        $params = new \Joomla\Registry\Registry((string) Factory::getContainer()->get(DatabaseInterface::class)->setQuery(
            "SELECT params FROM #__extensions WHERE element = 'com_dharmaform' AND type = 'component'"
        )->loadResult());
        $dir    = trim((string) $params->get('upload_dir', '')) ?: 'files/dharmaform';

        Factory::getApplication()->enqueueMessage('Dharma Form: загруженные посетителями файлы остались в папке «' . htmlspecialchars($dir, ENT_QUOTES, 'UTF-8') . '». В них могут быть персональные данные: удалите папку вручную, если файлы больше не нужны.', 'warning');

        return true;
    }

    public function preflight(string $type, InstallerAdapter $adapter): bool
    {
        if (version_compare(PHP_VERSION, self::MIN_PHP, '<') || version_compare(JVERSION, self::MIN_JOOMLA, '<')) {
            Factory::getApplication()->enqueueMessage('Dharma Form требует PHP ' . self::MIN_PHP . '+ и Joomla ' . self::MIN_JOOMLA . '+.', 'error');

            return false;
        }

        return true;
    }

    /** Включает системный плагин, плагин планировщика и три канала уведомлений: без них компонент работает вполсилы. */
    public function postflight(string $type, InstallerAdapter $adapter): bool
    {
        if ($type === 'uninstall') {
            return true;
        }

        $db = Factory::getContainer()->get(DatabaseInterface::class);

        $db->setQuery(
            'UPDATE ' . $db->quoteName('#__extensions') . ' SET enabled = 1 WHERE type = ' . $db->quote('plugin')
            . ' AND ((folder = ' . $db->quote('dharmaformnotify') . ') OR (folder = ' . $db->quote('system') . ' AND element = ' . $db->quote('dharmaform') . ')'
            . ' OR (folder = ' . $db->quote('task') . ' AND element = ' . $db->quote('dharmaform') . ') OR (folder = ' . $db->quote('dharmashop') . ' AND element = ' . $db->quote('dharmaform') . '))'
        )->execute();

        return true;
    }
};
