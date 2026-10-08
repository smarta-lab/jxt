<?php

declare(strict_types=1);

namespace SmartA\Jxt\Command;

use SmartA\Jxt\Build\PackageBuilder;
use SmartA\Jxt\Configuration\Configuration;
use SmartA\Jxt\Configuration\ConfigurationLoader;
use SmartA\Jxt\Deployment\UnlistedSourceFinder;
use SmartA\Jxt\Install\InstallException;
use SmartA\Jxt\Install\JoomlaInstaller;
use SmartA\Jxt\Install\ProcessResult;
use SmartA\Jxt\JxtException;
use SmartA\Jxt\Manifest\Manifest;
use SmartA\Jxt\Manifest\ManifestLocator;
use SmartA\Jxt\Manifest\ManifestParser;
use SmartA\Jxt\Manifest\PackageManifestReader;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Command `jxt install`: builds the ZIP file and installs it on the test server with the Joomla installer.
 *
 * With `--zip`, an existing ZIP file (e.g. of an older version) is installed instead of building a new one.
 */
#[AsCommand(name: 'install', description: 'Builds the ZIP file and installs it on the local Joomla test site.')]
final class InstallCommand extends Command
{
    /**
     * @param string $projectDirectory Project directory of the extension, usually the current directory
     * @param string $currentUser      Name of the calling user, for the sudoers rule in the error hint
     */
    public function __construct(
        private readonly string $projectDirectory,
        private readonly ConfigurationLoader $configurationLoader,
        private readonly JoomlaInstaller $installer,
        private readonly string $currentUser,
        private readonly ManifestLocator $manifestLocator = new ManifestLocator(),
        private readonly ManifestParser $manifestParser = new ManifestParser(),
        private readonly PackageBuilder $packageBuilder = new PackageBuilder(),
        private readonly UnlistedSourceFinder $unlistedSourceFinder = new UnlistedSourceFinder(),
        private readonly PackageManifestReader $packageManifestReader = new PackageManifestReader(),
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this->addOption('zip', null, InputOption::VALUE_REQUIRED, 'Install this ZIP file instead of building a new one.');
    }

    /**
     * Builds (or takes) and installs the ZIP file and shows the output of the Joomla CLI.
     */
    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $configuration = $this->configurationLoader->load($this->projectDirectory);
            $joomlaRoot = $configuration->validJoomlaRoot();
            $zip = $input->getOption('zip');
            [$manifest, $packagePath] = is_string($zip)
                ? $this->existingPackage($zip)
                : $this->newPackage($io, $configuration);

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

        $io->success(trim($manifest->element . ' ' . ($manifest->version ?? '')) . ' installed.');

        return Command::SUCCESS;
    }

    /**
     * Builds the ZIP file from the project and returns its manifest and path.
     *
     * @return array{Manifest, string}
     *
     * @throws JxtException if the manifest is missing or invalid or the ZIP file cannot be created
     */
    private function newPackage(SymfonyStyle $io, Configuration $configuration): array
    {
        $manifestFile = $this->manifestLocator->locate($this->projectDirectory, $configuration->manifestFile);
        $manifest = $this->manifestParser->parseFile($manifestFile);
        $extensionDirectory = dirname($manifestFile);
        $package = $this->packageBuilder->build($manifest, $extensionDirectory, $configuration->distDirectory);
        $this->warnAboutUnlistedSources($io, $manifest, $extensionDirectory);

        return [$manifest, $package->path];
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
     * Warns about files in the source directories that are not listed in the manifest.
     */
    private function warnAboutUnlistedSources(SymfonyStyle $io, Manifest $manifest, string $extensionDirectory): void
    {
        $unlisted = $this->unlistedSourceFinder->find($manifest->plan, $extensionDirectory);

        if ($unlisted !== []) {
            $io->warning(implode("\n", ['Not listed in the manifest, therefore not installed:', ...$unlisted]));
        }
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
