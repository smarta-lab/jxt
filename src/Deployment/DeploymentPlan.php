<?php

declare(strict_types=1);

namespace SmartA\Jxt\Deployment;

/**
 * Describes which files and directories of an extension go where.
 *
 * Shared by `jxt sync` (copying to the server) and `jxt build` (ZIP file).
 */
final readonly class DeploymentPlan
{
    /**
     * @param list<PlanEntry> $entries              Files and directories to transfer
     * @param list<string>    $exclusiveDirectories Target directories owned exclusively by the extension
     *                                              (relative to the Joomla root directory)
     * @param list<string>    $sourceDirectories    Source directories of the manifest sections (relative to the project directory)
     *                                              whose content should be fully listed in the manifest
     */
    public function __construct(
        public array $entries,
        public array $exclusiveDirectories,
        public array $sourceDirectories = [],
    ) {
    }
}
