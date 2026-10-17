<?php

declare(strict_types=1);

namespace SmartA\Jxt\Command;

use SmartA\Jxt\Build\ProjectBuild;
use SmartA\Jxt\Build\ProjectBuilder;
use SmartA\Jxt\Configuration\Configuration;
use SmartA\Jxt\Configuration\ConfigurationLoader;
use SmartA\Jxt\Deployment\EntryKind;
use SmartA\Jxt\Deployment\PackagePlanExpander;
use SmartA\Jxt\Install\InstallException;
use SmartA\Jxt\Install\JoomlaInstaller;
use SmartA\Jxt\Install\ProcessResult;
use SmartA\Jxt\JxtException;
use SmartA\Jxt\Manifest\Manifest;
use SmartA\Jxt\Manifest\ManifestException;
use SmartA\Jxt\Manifest\ManifestParser;
use SmartA\Jxt\Manifest\PackageManifestReader;
use SmartA\Jxt\Sync\Deletion;
use SmartA\Jxt\Sync\LeftoverFinder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Command `jxt install`: builds the ZIP file and installs it on the test server with the Joomla installer.
 *
 * With `--tag`, the ZIP file is built from the committed files of a Git tag; with `--zip`, an existing ZIP
 * file (e.g. of an older version) is installed instead of building a new one.
 *
 * Afterwards it reports leftovers: entries in the exclusive directories of the extension that the installed ZIP
 * file does not contain, since the Joomla installer does not remove files from listed folders. The ZIP file is
 * the only reliable record of what was installed, so there is no separate command for this.
 */
#[AsCommand(name: 'install', description: 'Builds the ZIP file and installs it on the local Joomla test site.')]
final class InstallCommand extends Command
{
    private readonly ProjectBuilder $projectBuilder;

    /**
     * @param string $projectDirectory Project directory of the extension, usually the current directory
     * @param string $currentUser      Name of the calling user, for the sudoers rule in the error hint
     */
    public function __construct(
        private readonly string $projectDirectory,
        private readonly ConfigurationLoader $configurationLoader,
        private readonly JoomlaInstaller $installer,
        private readonly string $currentUser,
        ?ProjectBuilder $projectBuilder = null,
        private readonly PackageManifestReader $packageManifestReader = new PackageManifestReader(),
        private readonly ManifestParser $manifestParser = new ManifestParser(),
        private readonly PackagePlanExpander $packagePlanExpander = new PackagePlanExpander(),
        private readonly LeftoverFinder $leftoverFinder = new LeftoverFinder(),
    ) {
        $this->projectBuilder = $projectBuilder ?? new ProjectBuilder($configurationLoader);
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this->addOption(
            'tag',
            null,
            InputOption::VALUE_REQUIRED,
            'Build the ZIP file from a Git tag instead of the working copy, e.g. v1.0.0',
        );
        $this->addOption('zip', null, InputOption::VALUE_REQUIRED, 'Install this ZIP file instead of building a new one.');
    }

    /**
     * Builds (or takes) and installs the ZIP file and shows the output of the Joomla CLI.
     */
    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $tag = $input->getOption('tag');
        $zip = $input->getOption('zip');

        if (is_string($tag) && is_string($zip)) {
            $io->error('--tag and --zip cannot be combined.');

            return Command::INVALID;
        }

        try {
            $configuration = $this->configurationLoader->load($this->projectDirectory);
            $joomlaRoot = $configuration->validJoomlaRoot();
            [$manifest, $packagePath] = is_string($zip)
                ? $this->existingPackage($zip)
                : $this->newPackage($io, $configuration, is_string($tag) ? $tag : null);

            $previous = $this->installedManifest($manifest, $joomlaRoot);

            $io->writeln(sprintf('<info>%s</info> → %s', basename($packagePath), $joomlaRoot));
            $result = $this->installer->install($packagePath, $joomlaRoot, $configuration->webUser);
        } catch (JxtException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $io->writeln(trim($result->output));

        if ($result->exitCode !== 0) {
            $this->explainFailure($io, $result, $manifest, $configuration, $joomlaRoot);

            return Command::FAILURE;
        }

        $this->reportLeftovers($io, $manifest, $previous ?? $manifest, $packagePath, $configuration, $joomlaRoot);
        $io->success(sprintf(
            '%s installed%s.',
            trim($manifest->element . ' ' . ($manifest->version ?? '')),
            is_string($tag) ? ' from tag ' . $tag : '',
        ));

        return Command::SUCCESS;
    }

    /**
     * Builds the ZIP file from the working copy or the tag and returns its manifest and path.
     *
     * @return array{Manifest, string}
     *
     * @throws JxtException if the manifest or the tag is missing or invalid or the ZIP file cannot be created
     */
    private function newPackage(SymfonyStyle $io, Configuration $configuration, ?string $tag): array
    {
        $build = $tag === null
            ? $this->projectBuilder->build($this->projectDirectory, $configuration->distDirectory)
            : $this->projectBuilder->buildTag($this->projectDirectory, $tag, $configuration->distDirectory);
        $this->showBuildWarnings($io, $build);

        return [$build->manifest, $build->package->path];
    }

    /**
     * Returns the manifest and path of an existing ZIP file; relative paths refer to the project directory.
     *
     * @return array{Manifest, string}
     *
     * @throws JxtException if the file is missing, has no `.zip` extension or contains no valid manifest
     */
    private function existingPackage(string $zip): array
    {
        $path = str_starts_with($zip, '/') ? $zip : $this->projectDirectory . '/' . $zip;

        if (!is_file($path)) {
            throw new InstallException(sprintf('ZIP file %s not found.', $zip));
        }

        if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'zip') {
            throw new InstallException(sprintf('%s has no .zip extension, which the Joomla installer requires.', $zip));
        }

        return [$this->packageManifestReader->read($path), $path];
    }

    /**
     * Returns the manifest installed before, which may name other exclusive directories, or null if there is none
     * or it is invalid.
     */
    private function installedManifest(Manifest $manifest, string $joomlaRoot): ?Manifest
    {
        $path = $joomlaRoot . '/' . $manifest->installedManifest();

        try {
            return is_file($path) ? $this->manifestParser->parseFile($path) : null;
        } catch (ManifestException) {
            return null;
        }
    }

    /**
     * Lists the leftovers of the installed ZIP file on the site, each leftover directory once, with a hint on
     * removing them.
     */
    private function reportLeftovers(
        SymfonyStyle $io,
        Manifest $manifest,
        Manifest $previous,
        string $packagePath,
        Configuration $configuration,
        string $joomlaRoot,
    ): void {
        try {
            $files = $this->packagePlanExpander->expand($manifest->plan, $packagePath);
            $leftovers = $this->leftoverFinder->topLevel(
                $this->leftoverFinder->find($manifest, $previous, $files, $joomlaRoot),
            );
        } catch (JxtException $exception) {
            $io->warning('Cannot check for leftovers: ' . $exception->getMessage());

            return;
        }

        if ($leftovers === []) {
            return;
        }

        $io->warning(implode("\n", [
            'Not removed by the Joomla installer:',
            ...array_map(
                static fn (Deletion $leftover): string => $leftover->target
                    . ($leftover->kind === EntryKind::Directory ? '/' : ''),
                $leftovers,
            ),
        ]));
        $io->note(match ($configuration->webUser) {
            null => 'They no longer belong to the extension. Remove them by hand, e.g. with rm -r.',
            default => sprintf(
                'They no longer belong to the extension. Remove them as %1$s, e.g. with sudo -u %1$s rm -r.',
                $configuration->webUser,
            ),
        });
    }

    /**
     * Warns about files that are not listed in the manifest and about a tag that does not name the version.
     */
    private function showBuildWarnings(SymfonyStyle $io, ProjectBuild $build): void
    {
        if ($build->unlisted !== []) {
            $io->warning(implode("\n", ['Not listed in the manifest, therefore not installed:', ...$build->unlisted]));
        }

        TagWarning::show($io, $build);
    }

    /**
     * Reports the failure with the sudoers rule if sudo refused, otherwise with a hint on `method="upgrade"`.
     */
    private function explainFailure(
        SymfonyStyle $io,
        ProcessResult $result,
        Manifest $manifest,
        Configuration $configuration,
        string $joomlaRoot,
    ): void {
        $webUser = $configuration->webUser;

        if ($webUser !== null && preg_match('/^sudo: /m', $result->output) === 1) {
            $io->error(sprintf('sudo did not allow running the Joomla CLI as %s without password.', $webUser));
            $io->writeln('Create the rule with');
            $io->writeln('  sudo visudo -f /etc/sudoers.d/jxt');
            $io->writeln('and add this line:');
            $io->writeln('  ' . $this->installer->sudoersRule($this->currentUser, $joomlaRoot, $webUser), OutputInterface::OUTPUT_RAW);

            return;
        }

        $io->error(sprintf('The Joomla installer failed (exit code %d).', $result->exitCode));

        if (!$manifest->installer->upgrade) {
            $io->note('The manifest has no method="upgrade". Without it, the Joomla installer refuses to update an installed extension.');
        }
    }
}
