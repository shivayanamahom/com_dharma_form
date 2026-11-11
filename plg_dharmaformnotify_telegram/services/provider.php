<?php

/**
 * Dharma Form — универсальные формы для Joomla 5.2+ и 6+.
 *
 * @package    pkg_dharma_form
 * @copyright  (C) 2026 Dharma. Все права защищены.
 * @license    GNU General Public License версии 2 или новее; см. LICENSE.txt
 */

declare(strict_types=1);

\defined('_JEXEC') or die;

use Dharma\Plugin\DharmaFormNotify\Telegram\Extension\Telegram;
use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use Joomla\Event\DispatcherInterface;

return new class () implements ServiceProviderInterface {
    public function register(Container $container): void
    {
        $container->set(PluginInterface::class, static function (Container $container): Telegram {
            $config = (array) PluginHelper::getPlugin('dharmaformnotify', 'telegram');

            // Joomla 5.x: конструктор принимает диспетчер первым аргументом; в Joomla 6 — только настройки.
            $first  = (new \ReflectionMethod(CMSPlugin::class, '__construct'))->getParameters()[0] ?? null;
            $plugin = $first !== null && $first->getName() === 'config'
                ? new Telegram($config)
                : new Telegram($container->get(DispatcherInterface::class), $config);

            $plugin->setApplication(Factory::getApplication());

            return $plugin;
        });
    }
};
