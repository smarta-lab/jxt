<?php

/**
 * @copyright  (C) 2026 Carsten Volkwein
 * @license    MIT License; see LICENSE
 */

declare(strict_types=1);

namespace SmartA\Plugin\Console\Jxt\Console;

use Joomla\CMS\Installer\Installer;
use Joomla\Console\Command\AbstractCommand;
use Joomla\Database\DatabaseInterface;
use SmartA\Plugin\Console\Jxt\Plan\Plan;
use SmartA\Plugin\Console\Jxt\Plan\PlanApplier;
use SmartA\Plugin\Console\Jxt\Plan\PlanException;
use SmartA\Plugin\Console\Jxt\Plan\PlanReader;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Command `jxt:apply <directory>`: applies the plan that `jxt sync` staged in the directory.
 *
 * Afterwards it refreshes the manifest cache of the extension, so that the extension manager shows the new
 * version as after an installation.
 */
final class ApplyCommand extends AbstractCommand
{
    /**
     * @var string
     */
    protected static $defaultName = 'jxt:apply';

    /**
     * @param string $root Joomla root directory the targets of the plan refer to
     */
    public function __construct(
        private readonly DatabaseInterface $db,
        private readonly string $root,
        private readonly PlanReader $reader = new PlanReader(),
        private readonly PlanApplier $applier = new PlanApplier(),
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this->setDescription('Applies a plan staged by jxt sync (development sites only).');
        $this->addArgument('directory', InputArgument::REQUIRED, 'Staging directory with plan.json');
    }

    #[\Override]
    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $directory = $input->getArgument('directory');

        try {
            $plan = $this->reader->read(is_string($directory) ? rtrim($directory, '/') : '');
            $this->applier->apply($plan, is_string($directory) ? rtrim($directory, '/') : '', $this->root);
        } catch (PlanException $exception) {
            $io->error($exception->getMessage());

            return 1;
        }

        $io->writeln(sprintf('Applied: %d copied, %d deleted.', $plan->copyCount(), count($plan->deletions)));

        if (!$this->refreshManifestCache($plan)) {
            $io->warning('The manifest cache of the extension could not be refreshed.');
        }

        return 0;
    }

    /**
     * Refreshes the manifest cache of the plan's extension; true if there is nothing to refresh.
     */
    private function refreshManifestCache(Plan $plan): bool
    {
        if ($plan->extension === null) {
            return true;
        }

        $id = (new ExtensionFinder($this->db))->find($plan->extension);

        return $id !== null && Installer::getInstance()->refreshManifestCache($id);
    }
}
