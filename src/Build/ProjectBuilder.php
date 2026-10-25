<?php

/**
 * @copyright  (C) 2026 Carsten Volkwein
 * @license    MIT License; see LICENSE
 */

declare(strict_types=1);

namespace SmartA\Jxt\Build;

use SmartA\Jxt\Configuration\ConfigurationLoader;
use SmartA\Jxt\Deployment\UnlistedSourceFinder;
use SmartA\Jxt\Git\GitRepository;
use SmartA\Jxt\Install\ProcessRunner;
use SmartA\Jxt\Install\SystemProcessRunner;
use SmartA\Jxt\JxtException;
use SmartA\Jxt\Manifest\ManifestLocator;
use SmartA\Jxt\Manifest\ManifestParser;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Builds the ZIP file of a project from its working copy or from the committed files of a Git tag, for
 * `jxt build` and `jxt install`.
 *
 * The prepare steps run first, before the manifest is located, since they may generate it.
 */
final readonly class ProjectBuilder
{
    public function __construct(
        private ConfigurationLoader $configurationLoader,
        private ManifestLocator $manifestLocator = new ManifestLocator(),
        private ManifestParser $manifestParser = new ManifestParser(),
        private PackageBuilder $packageBuilder = new PackageBuilder(),
        private UnlistedSourceFinder $unlistedSourceFinder = new UnlistedSourceFinder(),
        private ProcessRunner $processRunner = new SystemProcessRunner(),
        private TagExporter $tagExporter = new TagExporter(),
        private Preparation $preparation = new Preparation(),
    ) {
    }

    /**
     * Runs the prepare steps in the project directory and builds the ZIP file from it.
     *
     * The settings `manifest` and `prepare` are read from the `.jxt.json` in that directory, so that they match its
     * layout.
     *
     * @param OutputInterface $output Output for the prepare steps
     *
     * @throws JxtException if a prepare step fails, the manifest is missing or invalid or the ZIP file cannot be
     *                      created
     */
    public function build(string $projectDirectory, string $distDirectory, OutputInterface $output): ProjectBuild
    {
        $configuration = $this->configurationLoader->load($projectDirectory);
        $this->preparation->run($configuration->prepare, $projectDirectory, $output);
        $manifestFile = $this->manifestLocator->locate($projectDirectory, $configuration->manifestFile);
        $manifest = $this->manifestParser->parseFile($manifestFile);
        $extensionDirectory = dirname($manifestFile);

        return new ProjectBuild(
            $manifest,
            $this->packageBuilder->build($manifest, $extensionDirectory, $distDirectory),
            $this->unlistedSourceFinder->find($manifest->plan, $extensionDirectory),
        );
    }

    /**
     * Builds the ZIP file from the committed files of the tag; the settings `manifest` and `prepare` come from the
     * tag, and the prepare steps run in the exported files.
     *
     * @param OutputInterface $output Output for the prepare steps
     *
     * @throws JxtException if there is no Git repository or tag, or the build fails
     */
    public function buildTag(string $projectDirectory, string $tag, string $distDirectory, OutputInterface $output): ProjectBuild
    {
        $git = GitRepository::open($projectDirectory, $this->processRunner) ?? throw new BuildException(
            sprintf('--tag needs Git, but %s is not in a Git repository.', $projectDirectory),
        );
        $build = $this->tagExporter->export(
            $git,
            $tag,
            fn (string $directory): ProjectBuild => $this->build($directory, $distDirectory, $output),
        );

        return new ProjectBuild($build->manifest, $build->package, $build->unlisted, $tag);
    }
}
