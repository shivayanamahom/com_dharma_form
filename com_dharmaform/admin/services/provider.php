<?php

/**
 * Dharma Form — универсальные формы для Joomla 5.2+ и 6+.
 *
 * @package    com_dharmaform
 * @copyright  (C) 2026 Dharma. Все права защищены.
 * @license    GNU General Public License версии 2 или новее; см. LICENSE.txt
 */

declare(strict_types=1);

\defined('_JEXEC') or die;

use Dharma\Component\DharmaForm\Administrator\Extension\DharmaFormComponent;
use Joomla\CMS\Component\Router\RouterFactoryInterface;
use Joomla\CMS\Dispatcher\ComponentDispatcherFactoryInterface;
use Joomla\CMS\Extension\ComponentInterface;
use Joomla\CMS\Extension\Service\Provider\ComponentDispatcherFactory;
use Joomla\CMS\Extension\Service\Provider\MVCFactory;
use Joomla\CMS\Extension\Service\Provider\RouterFactory;
use Joomla\CMS\MVC\Factory\MVCFactoryInterface;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;

return new class () implements ServiceProviderInterface {
    public function register(Container $container): void
    {
        $container->registerServiceProvider(new MVCFactory('\\Dharma\\Component\\DharmaForm'));
        $container->registerServiceProvider(new ComponentDispatcherFactory('\\Dharma\\Component\\DharmaForm'));
        $container->registerServiceProvider(new RouterFactory('\\Dharma\\Component\\DharmaForm'));

        $container->set(
            ComponentInterface::class,
            static function (Container $c): DharmaFormComponent {
                $component = new DharmaFormComponent($c->get(ComponentDispatcherFactoryInterface::class));
                $component->setMVCFactory($c->get(MVCFactoryInterface::class));
                $component->setRouterFactory($c->get(RouterFactoryInterface::class));

                return $component;
            }
        );
    }
};
