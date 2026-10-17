<?php

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
     * Creates the context for the manifest at `$manifestFile`.
     *
     * @param bool $release Whether `--release` was given
     */
    public function create(string $projectDirectory, string $manifestFile, Manifest $manifest, bool $release): CheckContext
    {
        $extensionDirectory = dirname($manifestFile);
        $git = GitRepository::open($projectDirectory, $this->processRunner);
        $changelog = Changelog::load($projectDirectory);

        return new CheckContext(
            $projectDirectory,
            $extensionDirectory,
            $manifest,
            $this->releaseDetector->detect($manifest->version, $git, $changelog),
            $this->existingFiles($manifest->plan, $extensionDirectory),
            $release,
            $git,
            $changelog,
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
