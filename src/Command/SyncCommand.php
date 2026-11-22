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
use SmartA\Jxt\Deployment\PlanEntry;
use SmartA\Jxt\Deployment\PlanExpander;
use SmartA\Jxt\Deployment\UnlistedSourceFinder;
use SmartA\Jxt\JxtException;
use SmartA\Jxt\Manifest\ExtensionType;
use SmartA\Jxt\Manifest\Manifest;
use SmartA\Jxt\Manifest\ManifestLocator;
use SmartA\Jxt\Manifest\ManifestParser;
use SmartA\Jxt\Manifest\PackageResolver;
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
 * For a package, each of its extensions is synced after its own prepare steps; changed files of the package itself
 * are only pointed out, since only `jxt install` installs them.
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
        private readonly PackageResolver $packageResolver = new PackageResolver(),
        private readonly PlanExpander $planExpander = new PlanExpander(),
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only show what would be copied and deleted.');
        SiteOption::add($this);
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
            $configuration = $this->configurationLoader->load($this->projectDirectory, SiteOption::value($input));
            $joomlaRoot = $configuration->validJoomlaRoot();
            $this->preparation->run($configuration->prepare, $this->projectDirectory, $io, true);
            $manifestFile = $this->manifestLocator->locate($this->projectDirectory, $configuration->manifestFile);
            $manifest = $this->manifestParser->parseFile($manifestFile);
            $counts = $manifest->type === ExtensionType::Package
                ? $this->syncPackage($io, $manifest, dirname($manifestFile), $configuration, $dryRun)
                : $this->syncExtension($io, $manifest, dirname($manifestFile), $configuration, $dryRun);
        } catch (JxtException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        if ($counts === null) {
            return Command::FAILURE;
        }

        $summary = vsprintf('%d copied, %d deleted, %d unchanged.', $counts);
        $io->success($dryRun ? 'Dry run, nothing changed: ' . $summary : $summary);

        return Command::SUCCESS;
    }

    /**
     * Syncs each extension of the package that an entry refers to, after its own prepare steps, and points out
     * changed files of the package itself, which only `jxt install` installs.
     *
     * @return array{int, int, int}|null Copied, deleted and unchanged files, or null if applying a plan failed
     *
     * @throws JxtException if an entry cannot be resolved, the package is not installed or syncing an extension fails
     */
    private function syncPackage(
        SymfonyStyle $io,
        Manifest $package,
        string $packageDirectory,
        Configuration $configuration,
        bool $dryRun,
    ): ?array {
        $joomlaRoot = $configuration->validJoomlaRoot();
        $this->installedManifest($package, $joomlaRoot);
        $packageFiles = $this->planExpander->expand($package->plan, $packageDirectory);
        $resolution = $this->packageResolver->resolve($package, $packageDirectory, [$configuration->distDirectory]);
        $errors = $resolution->errors();

        if ($errors !== []) {
            throw new DeploymentException($errors[0]);
        }

        $counts = [0, 0, 0];

        foreach ($resolution->entries as $resolved) {
            if ($resolved->child === null) {
                $io->note(sprintf('%s has no manifest in the subdirectories and is not synced.', $resolved->entry->source));

                continue;
            }

            $directory = $resolved->child->directory();
            $this->preparation->run($this->configurationLoader->load($directory)->prepare, $directory, $io, true);
            $childCounts = $this->syncExtension($io, $this->manifestParser->parseFile($resolved->child->file), $directory, $configuration, $dryRun);

            if ($childCounts === null) {
                return null;
            }

            $counts = array_map(static fn (int $sum, int $count): int => $sum + $count, $counts, $childCounts);
        }

        $this->warnAboutUnlistedSources($io, $package->plan, $packageDirectory);
        $this->warnAboutPackageFiles($io, $packageFiles, $packageDirectory, $joomlaRoot);

        return [$counts[0], $counts[1], $counts[2]];
    }

    /**
     * Compares the extension with the site, shows the changes and applies them.
     *
     * @return array{int, int, int}|null Copied, deleted and unchanged files, or null if applying the plan failed
     *
     * @throws JxtException if the extension is not installed or the plan cannot be created
     */
    private function syncExtension(
        SymfonyStyle $io,
        Manifest $manifest,
        string $extensionDirectory,
        Configuration $configuration,
        bool $dryRun,
    ): ?array {
        $joomlaRoot = $configuration->validJoomlaRoot();
        $plan = $this->planner->plan(
            $manifest,
            $this->installedManifest($manifest, $joomlaRoot),
            $extensionDirectory,
            $joomlaRoot,
        );
        $bridgeVersion = $this->bridge->installedVersion($joomlaRoot);

        $io->writeln(sprintf(
            '<info>%s</info> → %s%s%s%s',
            $manifest->name(),
            $joomlaRoot,
            SiteOption::describe($configuration),
            $bridgeVersion === null ? '' : ' via jxt bridge',
            $dryRun ? ' (dry run)' : '',
        ));
        $this->listChanges($io, $plan);
        $this->warn($io, $plan, $bridgeVersion !== null);
        $this->warnAboutUnlistedSources($io, $manifest->plan, $extensionDirectory);

        if (!$dryRun && $bridgeVersion === null) {
            if (!$this->applyDirectly($io, $plan, $extensionDirectory, $joomlaRoot)) {
                return null;
            }
        } elseif (
            !$dryRun
            && !$this->bridgeRun()->apply($io, $plan, $manifest, $extensionDirectory, $configuration->testSite(), $bridgeVersion)
        ) {
            return null;
        }

        return [count($plan->changedFiles()), count($plan->deletions), $plan->unchangedCount()];
    }

    /**
     * Returns the manifest installed on the site.
     *
     * @throws JxtException if the extension is not installed or its installed manifest is invalid
     */
    private function installedManifest(Manifest $manifest, string $joomlaRoot): Manifest
    {
        $installedManifest = $joomlaRoot . '/' . $manifest->installedManifest();

        if (!is_file($installedManifest)) {
            throw new DeploymentException(sprintf(
                '%s not found. Please install the extension once with jxt install.',
                $installedManifest,
            ));
        }

        return $this->manifestParser->parseFile($installedManifest);
    }

    /**
     * Warns about files of the package itself (manifest, install script, language files) that differ from the site;
     * `jxt sync` does not copy them, since Joomla only uses them when installing and uninstalling the package.
     *
     * @param list<PlanEntry> $files Expanded files of the package's plan
     */
    private function warnAboutPackageFiles(SymfonyStyle $io, array $files, string $packageDirectory, string $joomlaRoot): void
    {
        $changed = [];

        foreach ($files as $file) {
            $target = $joomlaRoot . '/' . $file->target;

            if (!is_file($target) || hash_file('xxh128', $target) !== hash_file('xxh128', $packageDirectory . '/' . $file->source)) {
                $changed[] = $file->source;
            }
        }

        if ($changed !== []) {
            $io->warning(sprintf(
                'Changed files of the package: %s. jxt sync does not copy them; please run jxt install.',
                implode(', ', $changed),
            ));
        }
    }

    /**
     * Applies the plan with the permissions of the current user; a failed file operation points to the bridge.
     */
    private function applyDirectly(
        SymfonyStyle $io,
        SyncPlan $plan,
        string $extensionDirectory,
        string $joomlaRoot,
    ): bool {
        try {
            $this->executor->execute($plan, $extensionDirectory, $joomlaRoot);
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
