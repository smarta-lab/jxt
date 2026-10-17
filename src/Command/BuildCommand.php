<?php

declare(strict_types=1);

namespace SmartA\Jxt\Command;

use SmartA\Jxt\Build\BuildException;
use SmartA\Jxt\Build\Package;
use SmartA\Jxt\Build\PackageBuilder;
use SmartA\Jxt\Build\TagExporter;
use SmartA\Jxt\Configuration\ConfigurationLoader;
use SmartA\Jxt\Deployment\UnlistedSourceFinder;
use SmartA\Jxt\Git\GitRepository;
use SmartA\Jxt\Install\ProcessRunner;
use SmartA\Jxt\Install\SystemProcessRunner;
use SmartA\Jxt\JxtException;
use SmartA\Jxt\Manifest\Manifest;
use SmartA\Jxt\Manifest\ManifestLocator;
use SmartA\Jxt\Manifest\ManifestParser;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Command `jxt build`: creates an installable ZIP file from the project directory or from a Git tag.
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
        private readonly ProcessRunner $processRunner = new SystemProcessRunner(),
        private readonly TagExporter $tagExporter = new TagExporter(),
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this->addOption(
            'tag',
            null,
            InputOption::VALUE_REQUIRED,
            'Build from a Git tag instead of the working copy, e.g. v1.0.0',
        );
    }

    /**
     * Creates the ZIP file and reports domain errors without a stack trace.
     */
    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $tag = $input->getOption('tag');
        $tag = is_string($tag) ? $tag : null;

        try {
            $distDirectory = $this->configurationLoader->load($this->projectDirectory)->distDirectory;
            [$manifest, $package, $unlisted] = $tag === null
                ? $this->build($this->projectDirectory, $distDirectory)
                : $this->buildTag($tag, $distDirectory);
        } catch (JxtException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        if ($unlisted !== []) {
            $io->warning(implode("\n", ['Not listed in the manifest, therefore not in the ZIP:', ...$unlisted]));
        }

        if ($tag !== null && !$this->matchesVersion($tag, $manifest->version)) {
            $io->warning(sprintf(
                'Tag %s does not match the manifest version %s in the tag.',
                $tag,
                $manifest->version ?? '(none)',
            ));
        }

        $io->success(sprintf(
            'Created %s%s (%d files).',
            $this->displayPath($package->path),
            $tag === null ? '' : ' from tag ' . $tag,
            $package->fileCount,
        ));

        return Command::SUCCESS;
    }

    /**
     * Builds the ZIP file from the project directory or the exported project directory of a tag.
     *
     * The manifest setting is read from the `.jxt.json` in that directory, so that it matches its layout.
     *
     * @return array{Manifest, Package, list<string>} Manifest, ZIP file and files missing from the manifest
     *
     * @throws JxtException
     */
    private function build(string $projectDirectory, string $distDirectory): array
    {
        $manifestFile = $this->manifestLocator->locate(
            $projectDirectory,
            $this->configurationLoader->load($projectDirectory)->manifestFile,
        );
        $manifest = $this->manifestParser->parseFile($manifestFile);
        $extensionDirectory = dirname($manifestFile);

        return [
            $manifest,
            $this->packageBuilder->build($manifest, $extensionDirectory, $distDirectory),
            $this->unlistedSourceFinder->find($manifest->plan, $extensionDirectory),
        ];
    }

    /**
     * Builds the ZIP file from the committed files of the tag.
     *
     * @return array{Manifest, Package, list<string>}
     *
     * @throws JxtException
     */
    private function buildTag(string $tag, string $distDirectory): array
    {
        $git = GitRepository::open($this->projectDirectory, $this->processRunner) ?? throw new BuildException(
            sprintf('--tag needs Git, but %s is not in a Git repository.', $this->projectDirectory),
        );

        return $this->tagExporter->export(
            $git,
            $tag,
            fn (string $directory): array => $this->build($directory, $distDirectory),
        );
    }

    /**
     * Checks whether the tag names the version, with or without a `v` prefix.
     */
    private function matchesVersion(string $tag, ?string $version): bool
    {
        return $version !== null && ($tag === $version || $tag === 'v' . $version);
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
