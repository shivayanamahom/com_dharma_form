<?php

/**
 * Dharma Form — универсальные формы для Joomla 5.2+ и 6+.
 *
 * @package    com_dharmaform
 * @copyright  (C) 2026 Dharma. Все права защищены.
 * @license    GNU General Public License версии 2 или новее; см. LICENSE.txt
 */

declare(strict_types=1);

namespace Dharma\Component\DharmaForm\Administrator\Extension;

use Joomla\CMS\Component\Router\RouterServiceInterface;
use Joomla\CMS\Component\Router\RouterServiceTrait;
use Joomla\CMS\Extension\BootableExtensionInterface;
use Joomla\CMS\Extension\MVCComponent;
use Joomla\CMS\Log\Log;
use Psr\Container\ContainerInterface;

\defined('_JEXEC') or die;

/** Точка входа компонента. */
final class DharmaFormComponent extends MVCComponent implements BootableExtensionInterface, RouterServiceInterface
{
    use RouterServiceTrait;

    public function boot(ContainerInterface $container): void
    {
        // Журнал компонента: administrator/logs/com_dharmaform.log.php (категория «com_dharmaform»).
        Log::addLogger(['text_file' => 'com_dharmaform.log.php'], Log::ALL, ['com_dharmaform']);
    }
}
