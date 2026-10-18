<?php

/**
 * @copyright  (C) 2026 Carsten Volkwein
 * @license    MIT License; see LICENSE
 */

declare(strict_types=1);

namespace SmartA\Jxt\Check;

use SmartA\Jxt\Deployment\PlanEntry;
use SmartA\Jxt\Git\GitRepository;
use SmartA\Jxt\Manifest\Manifest;

/**
 * Everything the checkers need to know about the project.
 */
final readonly class CheckContext
{
    /**
     * @param string             $projectDirectory   Directory of `.jxt.json`, usually the current directory
     * @param string             $extensionDirectory Directory of the manifest; the project directory or below it
     * @param list<PlanEntry>    $files              Existing files of the deployment plan, directories expanded
     * @param bool               $release            Whether `--release` was given
     * @param GitRepository|null $git                Repository of the project directory, null without Git
     * @param Changelog|null     $changelog          `CHANGELOG.md` of the project directory, null if missing
     * @param string|null        $tag                Git tag whose files are checked, null for the working copy
     */
    public function __construct(
        public string $projectDirectory,
        public string $extensionDirectory,
        public Manifest $manifest,
        public ReleaseState $state,
        public array $files,
        public bool $release = false,
        public ?GitRepository $git = null,
        public ?Changelog $changelog = null,
        public ?string $tag = null,
    ) {
    }

    /**
     * Returns the severity of findings that only matter for a release: errors with `--release`, otherwise
     * notices, so that a check with `--strict` does not fail before the release is prepared.
     */
    public function releaseSeverity(): Severity
    {
        return $this->release ? Severity::Error : Severity::Notice;
    }

    /**
     * Returns the path of a source file of the plan relative to the project directory.
     */
    public function projectPath(string $source): string
    {
        $prefix = substr($this->extensionDirectory, strlen($this->projectDirectory) + 1);

        return $prefix === '' ? $source : $prefix . '/' . $source;
    }

    /**
     * Returns the absolute path of a source file of the plan.
     */
    public function absolutePath(string $source): string
    {
        return $this->extensionDirectory . '/' . $source;
    }
}
