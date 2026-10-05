<?php

declare(strict_types=1);

namespace SmartA\Jxt\Command;

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
            $joomlaRoot = $configuration->validJoomlaRoot();
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

            $io->writeln(sprintf('<info>%s</info> → %s%s', $manifest->element, $joomlaRoot, $dryRun ? ' (dry run)' : ''));
            $this->listChanges($io, $plan);
            $this->warn($io, $plan);
            $this->warnAboutUnlistedSources($io, $manifest->plan, $extensionDirectory);

            if (!$dryRun) {
                $this->executor->execute($plan, $extensionDirectory, $joomlaRoot, $configuration);
            }
        } catch (JxtException $exception) {
            $io->error($exception->getMessage());

            if ($exception instanceof DeploymentException) {
                $io->note('Missing write permissions on the server? jxt install runs the Joomla installer as web server user.');
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
     */
    private function warn(SymfonyStyle $io, SyncPlan $plan): void
    {
        $comparison = $plan->comparison;
        $installerHint = 'jxt sync does not run install scripts, SQL files or update server entries. '
            . 'Please run jxt install.';

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
                'Version %s → %s: run jxt install, or refresh the display via "Extensions: Manage" → "Refresh Cache".',
                $comparison->oldVersion ?? '–',
                $comparison->newVersion ?? '–',
            ));
        }
    }
}
