<?php

declare(strict_types=1);

namespace SmartA\Jxt\Command;

use SmartA\Jxt\Configuration\Configuration;
use SmartA\Jxt\Configuration\ConfigurationException;
use SmartA\Jxt\Configuration\ConfigurationLoader;
use SmartA\Jxt\Deployment\DeploymentException;
use SmartA\Jxt\Deployment\DeploymentPlan;
use SmartA\Jxt\Deployment\EntryKind;
use SmartA\Jxt\Deployment\UnlistedSourceFinder;
use SmartA\Jxt\JxtException;
use SmartA\Jxt\Manifest\ManifestLocator;
use SmartA\Jxt\Manifest\ManifestParser;
use SmartA\Jxt\Sync\FileStatus;
use SmartA\Jxt\Sync\SyncExecutor;
use SmartA\Jxt\Sync\SyncPlan;
use SmartA\Jxt\Sync\SyncPlanner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Command `jxt sync`: transfers the extension to the local Joomla test server.
 */
#[AsCommand(name: 'sync', description: 'Deploys the extension to the local Joomla test site.')]
final class SyncCommand extends Command
{
    /**
     * @param string $projectDirectory Project directory of the extension, usually the current directory
     */
    public function __construct(
        private readonly string $projectDirectory,
        private readonly ConfigurationLoader $configurationLoader,
        private readonly SyncExecutor $executor,
        private readonly ManifestLocator $manifestLocator = new ManifestLocator(),
        private readonly ManifestParser $manifestParser = new ManifestParser(),
        private readonly SyncPlanner $planner = new SyncPlanner(),
        private readonly UnlistedSourceFinder $unlistedSourceFinder = new UnlistedSourceFinder(),
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only show what would be copied and deleted.');
    }

    /**
     * Compares project and server, shows the changes and applies them.
     */
    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        try {
            $configuration = $this->configurationLoader->load($this->projectDirectory);
            $joomlaRoot = $this->joomlaRoot($configuration);
            $manifest = $this->manifestParser->parseFile($this->manifestLocator->locate($this->projectDirectory));
            $installedManifest = $joomlaRoot . '/' . $manifest->installedManifest();

            if (!is_file($installedManifest)) {
                throw new DeploymentException(sprintf(
                    '%s not found. Please install the extension once via the backend using the ZIP file (jxt build).',
                    $installedManifest,
                ));
            }

            $plan = $this->planner->plan(
                $manifest,
                $this->manifestParser->parseFile($installedManifest),
                $this->projectDirectory,
                $joomlaRoot,
            );

            $io->writeln(sprintf('<info>%s</info> → %s%s', $manifest->element, $joomlaRoot, $dryRun ? ' (dry run)' : ''));
            $this->listChanges($io, $plan);
            $this->warn($io, $plan, $configuration, $dryRun);
            $this->warnAboutUnlistedSources($io, $manifest->plan);

            if (!$dryRun) {
                $this->executor->execute($plan, $this->projectDirectory, $joomlaRoot, $configuration);
            }
        } catch (JxtException $exception) {
            $io->error($exception->getMessage());

            if ($exception instanceof DeploymentException && !$this->executor->runsAsRoot) {
                $io->note('Missing write permissions on the server? Then run jxt sync with sudo.');
            }

            return Command::FAILURE;
        }

        $summary = sprintf(
            '%d copied, %d deleted, %d unchanged.',
            count($plan->changedFiles()),
            count($plan->deletions),
            $plan->unchangedCount(),
        );
        $io->success($dryRun ? 'Dry run, nothing changed: ' . $summary : $summary);

        return Command::SUCCESS;
    }

    /**
     * Returns the validated Joomla root directory from the configuration.
     */
    private function joomlaRoot(Configuration $configuration): string
    {
        $joomlaRoot = $configuration->joomlaRoot ?? throw new ConfigurationException(sprintf(
            'joomla_root is not configured. Please set it in ~/.config/jxt/config.json or %s.',
            ConfigurationLoader::PROJECT_FILE,
        ));

        if (!is_file($joomlaRoot . '/configuration.php')) {
            throw new ConfigurationException(
                sprintf('%s is not a Joomla installation (configuration.php is missing).', $joomlaRoot),
            );
        }

        return $joomlaRoot;
    }

    /**
     * Lists deletions (`-`), new (`+`) and changed (`~`) files.
     */
    private function listChanges(SymfonyStyle $io, SyncPlan $plan): void
    {
        foreach ($plan->deletions as $deletion) {
            $suffix = $deletion->kind === EntryKind::Directory ? '/' : '';
            $io->writeln(sprintf('  <fg=red>- %s%s</>', $deletion->target, $suffix));
        }

        foreach ($plan->changedFiles() as $file) {
            $io->writeln(match ($file->status) {
                FileStatus::New => sprintf('  <fg=green>+ %s</>', $file->entry->target),
                default => sprintf('  <fg=yellow>~ %s</>', $file->entry->target),
            });
        }
    }

    /**
     * Warns about files in the source directories that are not listed in the manifest and would therefore be missing.
     */
    private function warnAboutUnlistedSources(SymfonyStyle $io, DeploymentPlan $plan): void
    {
        $unlisted = $this->unlistedSourceFinder->find($plan, $this->projectDirectory);

        if ($unlisted !== []) {
            $io->warning(implode("\n", ['Not listed in the manifest, therefore not deployed:', ...$unlisted]));
        }
    }

    /**
     * Warns about changes that only take effect when installing via the Joomla installer,
     * and, outside a dry run, about missing root privileges for owner/group.
     */
    private function warn(SymfonyStyle $io, SyncPlan $plan, Configuration $configuration, bool $dryRun): void
    {
        $comparison = $plan->comparison;
        $installerHint = 'jxt sync does not run install scripts, SQL files or update server entries. '
            . 'Please install the ZIP file (jxt build) via the backend.';

        if ($comparison->changedInstallerSections !== []) {
            $io->warning(sprintf(
                'Changed in the manifest: %s. %s',
                implode(', ', $comparison->changedInstallerSections),
                $installerHint,
            ));
        }

        if ($plan->changedInstallerFiles !== []) {
            $io->warning(sprintf('New or changed installer files: %s. %s', implode(', ', $plan->changedInstallerFiles), $installerHint));
        }

        if ($comparison->namespaceChanged) {
            $io->note(sprintf('Namespace changed: %s is deleted, Joomla rebuilds the file.', SyncPlanner::AUTOLOAD_CACHE));
        }

        if ($comparison->versionChanged()) {
            $io->note(sprintf(
                'Version %s → %s: refresh the display in the extension manager via "Extensions: Manage" → "Refresh Cache".',
                $comparison->oldVersion ?? '–',
                $comparison->newVersion ?? '–',
            ));
        }

        if (!$dryRun && !$this->executor->runsAsRoot && ($configuration->owner !== null || $configuration->group !== null)) {
            $io->warning('owner/group are configured, but jxt sync is not running with sudo: owner and group are not set.');
        }
    }
}
