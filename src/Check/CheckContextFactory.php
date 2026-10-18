<?php

/**
 * @copyright  (C) 2026 Carsten Volkwein
 * @license    MIT License; see LICENSE
 */

declare(strict_types=1);

namespace SmartA\Jxt\Check;

use SmartA\Jxt\Deployment\DeploymentPlan;
use SmartA\Jxt\Deployment\EntryKind;
use SmartA\Jxt\Deployment\PlanEntry;
use SmartA\Jxt\Deployment\PlanExpander;
use SmartA\Jxt\Git\GitRepository;
use SmartA\Jxt\Install\ProcessRunner;
use SmartA\Jxt\Install\SystemProcessRunner;
use SmartA\Jxt\Manifest\Manifest;

/**
 * Collects the context of a check run: Git repository, changelog, release state and the files of the plan.
 *
 * For a Git tag, the files come from a directory with the exported tag, while the repository stays the one
 * of the project, viewed at the tag.
 */
final readonly class CheckContextFactory
{
    public function __construct(
        private ProcessRunner $processRunner = new SystemProcessRunner(),
        private ReleaseDetector $releaseDetector = new ReleaseDetector(),
        private PlanExpander $planExpander = new PlanExpander(),
    ) {
    }

    /**
     * Returns the Git repository of the directory, or null without Git.
     */
    public function repository(string $directory): ?GitRepository
    {
        return GitRepository::open($directory, $this->processRunner);
    }

    /**
     * Creates the context for the manifest at `$manifestFile` in the working copy.
     *
     * @param bool $release Whether `--release` was given
     */
    public function create(string $projectDirectory, string $manifestFile, Manifest $manifest, bool $release): CheckContext
    {
        $git = $this->repository($projectDirectory);
        $changelog = Changelog::load($projectDirectory);

        return $this->context(
            $projectDirectory,
            $manifestFile,
            $manifest,
            $this->releaseDetector->detect($manifest->version, $git, $changelog),
            $release,
            $git,
            $changelog,
        );
    }

    /**
     * Creates the context for the files of a tag, exported to `$directory`.
     *
     * @param string        $directory    Directory with the exported files of the project directory
     * @param string        $manifestFile Manifest in that directory
     * @param GitRepository $git          Repository of the project directory
     * @param bool          $release      Whether `--release` was given
     */
    public function createForTag(
        string $directory,
        string $manifestFile,
        Manifest $manifest,
        bool $release,
        GitRepository $git,
        string $tag,
    ): CheckContext {
        return $this->context(
            $directory,
            $manifestFile,
            $manifest,
            $this->releaseDetector->detectTag($manifest->version, $git, $tag),
            $release,
            $git->atTag($tag),
            Changelog::load($directory),
            $tag,
        );
    }

    /**
     * Creates the context with the existing files of the plan.
     */
    private function context(
        string $projectDirectory,
        string $manifestFile,
        Manifest $manifest,
        ReleaseState $state,
        bool $release,
        ?GitRepository $git,
        ?Changelog $changelog,
        ?string $tag = null,
    ): CheckContext {
        $extensionDirectory = dirname($manifestFile);

        return new CheckContext(
            $projectDirectory,
            $extensionDirectory,
            $manifest,
            $state,
            $this->existingFiles($manifest->plan, $extensionDirectory),
            $release,
            $git,
            $changelog,
            $tag,
        );
    }

    /**
     * Returns the files of the plan with expanded directories; missing entries are left out.
     *
     * @return list<PlanEntry>
     */
    private function existingFiles(DeploymentPlan $plan, string $extensionDirectory): array
    {
        $existing = array_values(array_filter(
            $plan->entries,
            static fn (PlanEntry $entry): bool => $entry->kind === EntryKind::File
                ? is_file($extensionDirectory . '/' . $entry->source)
                : is_dir($extensionDirectory . '/' . $entry->source),
        ));

        return $this->planExpander->expand(
            new DeploymentPlan($existing, $plan->exclusiveDirectories, $plan->sourceDirectories),
            $extensionDirectory,
        );
    }
}
