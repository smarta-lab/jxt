<?php

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

/**
 * Builds the ZIP file of a project from its working copy or from the committed files of a Git tag, for
 * `jxt build` and `jxt install`.
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
    ) {
    }

    /**
     * Builds the ZIP file from the project directory.
     *
     * The manifest setting is read from the `.jxt.json` in that directory, so that it matches its layout.
     *
     * @throws JxtException if the manifest is missing or invalid or the ZIP file cannot be created
     */
    public function build(string $projectDirectory, string $distDirectory): ProjectBuild
    {
        $manifestFile = $this->manifestLocator->locate(
            $projectDirectory,
            $this->configurationLoader->load($projectDirectory)->manifestFile,
        );
        $manifest = $this->manifestParser->parseFile($manifestFile);
        $extensionDirectory = dirname($manifestFile);

        return new ProjectBuild(
            $manifest,
            $this->packageBuilder->build($manifest, $extensionDirectory, $distDirectory),
            $this->unlistedSourceFinder->find($manifest->plan, $extensionDirectory),
        );
    }

    /**
     * Builds the ZIP file from the committed files of the tag; the manifest setting comes from the tag.
     *
     * @throws JxtException if there is no Git repository or tag, or the build fails
     */
    public function buildTag(string $projectDirectory, string $tag, string $distDirectory): ProjectBuild
    {
        $git = GitRepository::open($projectDirectory, $this->processRunner) ?? throw new BuildException(
            sprintf('--tag needs Git, but %s is not in a Git repository.', $projectDirectory),
        );
        $build = $this->tagExporter->export(
            $git,
            $tag,
            fn (string $directory): ProjectBuild => $this->build($directory, $distDirectory),
        );

        return new ProjectBuild($build->manifest, $build->package, $build->unlisted, $tag);
    }
}
