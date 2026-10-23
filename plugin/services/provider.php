<?php

/**
 * @copyright  (C) 2026 Carsten Volkwein
 * @license    MIT License; see LICENSE
 */

declare(strict_types=1);

\defined('_JEXEC') or die;

use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\Database\DatabaseInterface;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use SmartA\Plugin\Console\Jxt\Extension\Jxt;

return new class () implements ServiceProviderInterface {
    /**
     * Registers the plugin with the database it passes to its commands.
     */
    #[\Override]
    public function register(Container $container): void
    {
        $container->set(
            PluginInterface::class,
            static function (Container $container): PluginInterface {
                $db = $container->get(DatabaseInterface::class);

                if (!$db instanceof DatabaseInterface) {
                    throw new \UnexpectedValueException('No database in the container.');
                }

                $plugin = new Jxt((array) PluginHelper::getPlugin('console', 'jxt'), JPATH_ROOT);
                $plugin->setDatabase($db);

                return $plugin;
            },
        );
    }
};
