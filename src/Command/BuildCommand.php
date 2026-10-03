<?php

declare(strict_types=1);

namespace SmartA\Jxt\Command;

use SmartA\Jxt\Build\PackageBuilder;
use SmartA\Jxt\Configuration\ConfigurationLoader;
use SmartA\Jxt\Deployment\UnlistedSourceFinder;
use SmartA\Jxt\JxtException;
use SmartA\Jxt\Manifest\ManifestLocator;
use SmartA\Jxt\Manifest\ManifestParser;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Command `jxt build`: creates an installable ZIP file from the project directory.
 */
#[AsCommand(name: 'build', description: 'Builds an installable ZIP file of the extension.')]
final class BuildCommand extends Command
{
    /**
     * @param string $projectDirectory Project directory of the extension, usually the current directory
     */
    public function __construct(
        private readonly string $projectDirectory,
        private readonly ConfigurationLoader $configurationLoader,
        private readonly ManifestLocator $manifestLocator = new ManifestLocator(),
        private readonly ManifestParser $manifestParser = new ManifestParser(),
        private readonly PackageBuilder $packageBuilder = new PackageBuilder(),
        private readonly UnlistedSourceFinder $unlistedSourceFinder = new UnlistedSourceFinder(),
    ) {
        parent::__construct();
    }

    /**
     * Creates the ZIP file and reports domain errors without a stack trace.
     */
    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $configuration = $this->configurationLoader->load($this->projectDirectory);
            $manifestFile = $this->manifestLocator->locate($this->projectDirectory, $configuration->manifestFile);
            $manifest = $this->manifestParser->parseFile($manifestFile);
            $extensionDirectory = dirname($manifestFile);
            $package = $this->packageBuilder->build($manifest, $extensionDirectory, $configuration->distDirectory);
        } catch (JxtException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $unlisted = $this->unlistedSourceFinder->find($manifest->plan, $extensionDirectory);

        if ($unlisted !== []) {
            $io->warning(implode("\n", ['Not listed in the manifest, therefore not in the ZIP:', ...$unlisted]));
        }

        $io->success(sprintf('Created %s (%d files).', $this->displayPath($package->path), $package->fileCount));

        return Command::SUCCESS;
    }

    /**
     * Shortens paths inside the project directory to the relative path.
     */
    private function displayPath(string $path): string
    {
        $prefix = $this->projectDirectory . '/';

        return str_starts_with($path, $prefix) ? substr($path, strlen($prefix)) : $path;
    }
}
