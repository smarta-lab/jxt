<?php

/**
 * @copyright  (C) 2026 Carsten Volkwein
 * @license    MIT License; see LICENSE
 */

declare(strict_types=1);

namespace SmartA\Jxt\Command;

use SmartA\Jxt\Bridge\Bridge;
use SmartA\Jxt\Build\Preparation;
use SmartA\Jxt\Configuration\Configuration;
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
 *
 * The prepare steps run first, with their replacements for `jxt sync`, also for `--dry-run`, which only promises
 * not to change the site.
 *
 * If the jxt bridge is installed on the site, the plan is applied by the bridge as web server user via the Joomla
 * CLI, after updating an older bridge; otherwise the files are copied with the developer's own permissions.
 */
#[AsCommand(name: 'sync', description: 'Deploys the extension to the local Joomla test site.')]
final class SyncCommand extends Command
{
    /**
     * @param string $projectDirectory Project directory of the extension, usually the current directory
     * @param string $currentUser      Name of the calling user, for the sudoers rules in the error hint
     */
    public function __construct(
        private readonly string $projectDirectory,
        private readonly ConfigurationLoader $configurationLoader,
        private readonly SyncExecutor $executor,
        private readonly Bridge $bridge,
        private readonly string $currentUser,
        private readonly ManifestLocator $manifestLocator = new ManifestLocator(),
        private readonly ManifestParser $manifestParser = new ManifestParser(),
        private readonly SyncPlanner $planner = new SyncPlanner(),
        private readonly UnlistedSourceFinder $unlistedSourceFinder = new UnlistedSourceFinder(),
        private readonly Preparation $preparation = new Preparation(),
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
            $joomlaRoot = $configuration->validJoomlaRoot();
            $this->preparation->run($configuration->prepare, $this->projectDirectory, $io, true);
            $manifestFile = $this->manifestLocator->locate($this->projectDirectory, $configuration->manifestFile);
            $manifest = $this->manifestParser->parseFile($manifestFile);
            $extensionDirectory = dirname($manifestFile);
            $installedManifest = $joomlaRoot . '/' . $manifest->installedManifest();

            if (!is_file($installedManifest)) {
                throw new DeploymentException(sprintf(
                    '%s not found. Please install the extension once with jxt install.',
                    $installedManifest,
                ));
            }

            $plan = $this->planner->plan(
                $manifest,
                $this->manifestParser->parseFile($installedManifest),
                $extensionDirectory,
                $joomlaRoot,
            );
            $bridgeVersion = $this->bridge->installedVersion($joomlaRoot);

            $io->writeln(sprintf(
                '<info>%s</info> → %s%s%s',
                $manifest->name(),
                $joomlaRoot,
                $bridgeVersion === null ? '' : ' via jxt bridge',
                $dryRun ? ' (dry run)' : '',
            ));
            $this->listChanges($io, $plan);
            $this->warn($io, $plan, $bridgeVersion !== null);
            $this->warnAboutUnlistedSources($io, $manifest->plan, $extensionDirectory);

            if (!$dryRun && $bridgeVersion === null) {
                if (!$this->applyDirectly($io, $plan, $extensionDirectory, $joomlaRoot, $configuration)) {
                    return Command::FAILURE;
                }
            } elseif (!$dryRun && !$this->bridgeRun()->apply($io, $plan, $manifest, $extensionDirectory, $configuration, $bridgeVersion)) {
                return Command::FAILURE;
            }
        } catch (JxtException $exception) {
            $io->error($exception->getMessage());

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
     * Applies the plan with the permissions of the current user; a failed file operation points to the bridge.
     */
    private function applyDirectly(
        SymfonyStyle $io,
        SyncPlan $plan,
        string $extensionDirectory,
        string $joomlaRoot,
        Configuration $configuration,
    ): bool {
        try {
            $this->executor->execute($plan, $extensionDirectory, $joomlaRoot, $configuration);
        } catch (DeploymentException $exception) {
            $io->error($exception->getMessage());
            $io->note('Missing write permissions on the server? Run jxt bridge add once; jxt sync then writes '
                . 'the files as web server user via the Joomla CLI.');

            return false;
        }

        return true;
    }

    /**
     * Returns the helper that applies the plan via the bridge.
     */
    private function bridgeRun(): BridgeRun
    {
        return new BridgeRun($this->bridge, $this->currentUser);
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
    private function warnAboutUnlistedSources(SymfonyStyle $io, DeploymentPlan $plan, string $extensionDirectory): void
    {
        $unlisted = $this->unlistedSourceFinder->find($plan, $extensionDirectory);

        if ($unlisted !== []) {
            $io->warning(implode("\n", ['Not listed in the manifest, therefore not deployed:', ...$unlisted]));
        }
    }

    /**
     * Warns about changes that only take effect when installing via the Joomla installer.
     *
     * @param bool $viaBridge Whether the bridge applies the plan; it refreshes the manifest cache itself
     */
    private function warn(SymfonyStyle $io, SyncPlan $plan, bool $viaBridge): void
    {
        $comparison = $plan->comparison;
        $installerHint = 'jxt sync does not run install scripts or SQL files and does not create update server entries '
            . 'or administrator menu items. Please run jxt install.';

        if ($comparison->changedInstallerSections !== []) {
            $io->warning(sprintf(
                'Changed in the manifest: %s. %s',
                implode(', ', $comparison->changedInstallerSections),
                $installerHint,
            ));
        }

        if ($plan->skippedFiles !== []) {
            $io->note(implode("\n", ['Skipped, language not installed on the server:', ...$plan->skippedFiles]));
        }

        if ($plan->changedInstallerFiles !== []) {
            $io->warning(sprintf('New or changed installer files: %s. %s', implode(', ', $plan->changedInstallerFiles), $installerHint));
        }

        if ($comparison->namespaceChanged) {
            $io->note(sprintf('Namespace changed: %s is deleted, Joomla rebuilds the file.', SyncPlanner::AUTOLOAD_CACHE));
        }

        if ($comparison->versionChanged() && !$viaBridge) {
            $io->note(sprintf(
                'Version %s → %s: run jxt install, or refresh the display via "Extensions: Manage" → "Refresh Cache".',
                $comparison->oldVersion ?? '–',
                $comparison->newVersion ?? '–',
            ));
        }
    }
}
