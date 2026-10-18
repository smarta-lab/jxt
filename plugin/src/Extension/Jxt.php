<?php

/**
 * @copyright  (C) 2026 Carsten Volkwein
 * @license    MIT License; see LICENSE
 */

declare(strict_types=1);

namespace SmartA\Plugin\Console\Jxt\Extension;

use Joomla\Application\ApplicationEvents;
use Joomla\Application\Event\ApplicationEvent;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Console\Application;
use Joomla\Database\DatabaseAwareInterface;
use Joomla\Database\DatabaseAwareTrait;
use Joomla\Event\SubscriberInterface;
use SmartA\Plugin\Console\Jxt\Console\ApplyCommand;
use SmartA\Plugin\Console\Jxt\Console\StatusCommand;

/**
 * Adds the commands of the jxt bridge to the Joomla CLI.
 *
 * Joomla loads console plugins only in `cli/joomla.php`, so the commands have no web endpoint.
 */
final class Jxt extends CMSPlugin implements SubscriberInterface, DatabaseAwareInterface
{
    use DatabaseAwareTrait;

    /**
     * @param array<mixed> $config Plugin configuration from `PluginHelper::getPlugin()`
     * @param string       $root   Joomla root directory the plans refer to
     */
    public function __construct(array $config, private readonly string $root)
    {
        parent::__construct($config);
    }

    /**
     * @return array<string, string>
     */
    #[\Override]
    public static function getSubscribedEvents(): array
    {
        return [ApplicationEvents::BEFORE_EXECUTE => 'registerCommands'];
    }

    /**
     * Registers `jxt:apply` and `jxt:status` before the console application runs the requested command.
     */
    public function registerCommands(ApplicationEvent $event): void
    {
        $application = $event->getApplication();

        if (!$application instanceof Application) {
            return;
        }

        $application->addCommand(new ApplyCommand($this->getDatabase(), $this->root));
        $application->addCommand(new StatusCommand($this->getDatabase()));
    }
}
