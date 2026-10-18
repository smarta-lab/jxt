<?php

/**
 * @copyright  (C) 2026 Carsten Volkwein
 * @license    MIT License; see LICENSE
 */

declare(strict_types=1);

namespace SmartA\Plugin\Console\Jxt\Console;

use Joomla\Console\Command\AbstractCommand;
use Joomla\Database\DatabaseInterface;
use SmartA\Plugin\Console\Jxt\Plan\ExtensionIdentity;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Command `jxt:status`: prints the extension ID of the bridge as JSON, which `jxt bridge remove` passes to
 * `extension:remove`.
 */
final class StatusCommand extends AbstractCommand
{
    /**
     * @var string
     */
    protected static $defaultName = 'jxt:status';

    public function __construct(private readonly DatabaseInterface $db)
    {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this->setDescription('Prints the extension ID of the jxt bridge as JSON.');
    }

    #[\Override]
    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $id = (new ExtensionFinder($this->db))->find(new ExtensionIdentity('plugin', 'jxt', 'console', 0));
        $output->writeln((string) json_encode(['extension_id' => $id], JSON_THROW_ON_ERROR));

        return 0;
    }
}
