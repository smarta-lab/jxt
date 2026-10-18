<?php

/**
 * @copyright  (C) 2026 Carsten Volkwein
 * @license    MIT License; see LICENSE
 */

declare(strict_types=1);

namespace SmartA\Jxt\Check\Checker;

use SmartA\Jxt\Check\CheckContext;
use SmartA\Jxt\Check\CheckName;
use SmartA\Jxt\Check\CheckReport;
use SmartA\Jxt\Check\Severity;
use SmartA\Jxt\Deployment\EntryKind;
use SmartA\Jxt\Deployment\PlanEntry;
use SmartA\Jxt\Deployment\UnlistedSourceFinder;
use SmartA\Jxt\Git\VersionTag;

/**
 * Checks the manifest: version (and whether a checked tag names it), listed and unlisted files, and junk files
 * that would end up in the ZIP.
 */
final readonly class ManifestChecker implements Checker
{
    /**
     * Semantic version 2.0.0 (https://semver.org).
     */
    private const string SEMVER_PATTERN = '/^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)'
        . '(-(0|[1-9]\d*|\d*[a-zA-Z-][0-9a-zA-Z-]*)(\.(0|[1-9]\d*|\d*[a-zA-Z-][0-9a-zA-Z-]*))*)?'
        . '(\+[0-9a-zA-Z-]+(\.[0-9a-zA-Z-]+)*)?$/';

    /**
     * File names left behind by operating systems, editors and merge tools.
     */
    private const string JUNK_PATTERN = '/^(\.DS_Store|Thumbs\.db|desktop\.ini|.*~|.*\.(orig|rej|bak|swp|swo))$/i';

    /**
     * Hidden files that are deliberately part of an extension.
     */
    private const array ALLOWED_HIDDEN_FILES = ['.htaccess'];

    public function __construct(private UnlistedSourceFinder $unlistedSourceFinder = new UnlistedSourceFinder())
    {
    }

    #[\Override]
    public function group(): string
    {
        return 'manifest';
    }

    #[\Override]
    public function check(CheckContext $context, CheckReport $report): void
    {
        $this->checkVersion($context, $report);
        $this->checkListedFiles($context, $report);

        foreach ($this->unlistedSourceFinder->find($context->manifest->plan, $context->extensionDirectory) as $path) {
            $report->add(
                CheckName::ManifestUnlistedFiles,
                Severity::Warning,
                'not listed in the manifest and therefore not part of the extension',
                $context->projectPath($path),
            );
        }

        $this->checkStrayFiles($context, $report);
    }

    /**
     * Checks that `<version>` is a semantic version and that a checked tag names it.
     */
    private function checkVersion(CheckContext $context, CheckReport $report): void
    {
        $version = $context->manifest->version;
        $manifestFile = $context->projectPath($context->manifest->fileName);

        if ($context->tag !== null && !VersionTag::names($context->tag, $version)) {
            $report->add(CheckName::ManifestTagVersion, Severity::Error, sprintf(
                'tag %s does not name the manifest version %s',
                $context->tag,
                $version ?? '(none)',
            ), $manifestFile);
        }

        if ($version === null) {
            $report->add(CheckName::ManifestVersionSemver, Severity::Error, 'has no <version>', $manifestFile);
        } elseif (preg_match(self::SEMVER_PATTERN, $version) !== 1) {
            $report->add(CheckName::ManifestVersionSemver, Severity::Error, sprintf(
                '<version> %s is not a semantic version',
                $version,
            ), $manifestFile);
        }
    }

    /**
     * Checks that all files and directories listed in the manifest exist.
     */
    private function checkListedFiles(CheckContext $context, CheckReport $report): void
    {
        foreach ($context->manifest->plan->entries as $entry) {
            $path = $context->absolutePath($entry->source);
            $exists = $entry->kind === EntryKind::File ? is_file($path) : is_dir($path);

            if (!$exists) {
                $report->add(CheckName::ManifestListedFiles, Severity::Error, sprintf(
                    'lists %s %s, which is missing',
                    $entry->kind === EntryKind::File ? 'file' : 'directory',
                    $context->projectPath($entry->source),
                ), $context->projectPath($context->manifest->fileName));
            }
        }
    }

    /**
     * Reports junk and hidden files that a listed directory would add to the extension.
     */
    private function checkStrayFiles(CheckContext $context, CheckReport $report): void
    {
        $listedFiles = array_map(
            static fn (PlanEntry $entry): string => $entry->source,
            array_filter($context->manifest->plan->entries, static fn (PlanEntry $entry): bool => $entry->kind === EntryKind::File),
        );

        foreach ($context->files as $file) {
            if (!in_array($file->source, $listedFiles, true) && $this->isStray($file->source)) {
                $report->add(
                    CheckName::ManifestStrayFiles,
                    Severity::Warning,
                    'would be part of the extension; delete it or move it out of the listed directory',
                    $context->projectPath($file->source),
                );
            }
        }
    }

    /**
     * Checks whether a path is a junk file or lies in a hidden file or directory.
     */
    private function isStray(string $path): bool
    {
        foreach (explode('/', $path) as $segment) {
            if (
                preg_match(self::JUNK_PATTERN, $segment) === 1
                || (str_starts_with($segment, '.') && !in_array($segment, self::ALLOWED_HIDDEN_FILES, true))
            ) {
                return true;
            }
        }

        return false;
    }
}
