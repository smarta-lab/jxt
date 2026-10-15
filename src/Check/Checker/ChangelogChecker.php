<?php

declare(strict_types=1);

namespace SmartA\Jxt\Check\Checker;

use SmartA\Jxt\Check\Changelog;
use SmartA\Jxt\Check\CheckContext;
use SmartA\Jxt\Check\CheckMode;
use SmartA\Jxt\Check\CheckName;
use SmartA\Jxt\Check\CheckReport;
use SmartA\Jxt\Check\Severity;

/**
 * Checks `CHANGELOG.md` in the format of "Keep a Changelog" against the manifest version.
 *
 * Pre-check: the manifest version is the last release, changes are collected under `[Unreleased]`.
 * Final check: the manifest version has a dated heading and `[Unreleased]` is empty.
 */
final readonly class ChangelogChecker implements Checker
{
    #[\Override]
    public function group(): string
    {
        return 'changelog';
    }

    #[\Override]
    public function check(CheckContext $context, CheckReport $report): void
    {
        $changelog = $context->changelog;

        if ($changelog === null || !$changelog->isKeepAChangelog()) {
            $report->add(CheckName::ChangelogFormat, Severity::Notice, sprintf(
                '%s %s, changelog checks skipped (see https://keepachangelog.com)',
                Changelog::FILE,
                $changelog === null ? 'is missing' : 'does not use the format of "Keep a Changelog"',
            ));

            return;
        }

        $version = $context->manifest->version;

        if ($version === null) {
            return;
        }

        if ($context->state->mode === CheckMode::PreCheck) {
            $this->preCheck($changelog, $version, $report);
        } else {
            $this->finalCheck($changelog, $version, $context, $report);
        }
    }

    /**
     * Checks that the manifest version is the last release and counts the unreleased changes.
     */
    private function preCheck(Changelog $changelog, string $version, CheckReport $report): void
    {
        $lastRelease = $changelog->lastRelease();

        if ($lastRelease !== $version) {
            $report->add(CheckName::ChangelogLastRelease, Severity::Warning, sprintf(
                'manifest <version> is %s, but the last release in %s is %s',
                $version,
                Changelog::FILE,
                $lastRelease ?? 'missing',
            ));
        }

        $entries = $changelog->unreleasedEntries();
        $report->add(CheckName::ChangelogUnreleased, Severity::Notice, sprintf(
            '[Unreleased] has %d %s',
            $entries,
            $entries === 1 ? 'entry' : 'entries',
        ));
    }

    /**
     * Checks that the version has a dated heading and nothing is left under `[Unreleased]`.
     */
    private function finalCheck(Changelog $changelog, string $version, CheckContext $context, CheckReport $report): void
    {
        if (!$changelog->isReleased($version)) {
            $report->add(CheckName::ChangelogReleaseHeading, $context->releaseSeverity(), sprintf(
                'no heading "## [%s] - YYYY-MM-DD" in %s',
                $version,
                Changelog::FILE,
            ));
        }

        $entries = $changelog->unreleasedEntries();

        if ($entries > 0) {
            $report->add(CheckName::ChangelogUnreleased, $context->releaseSeverity(), sprintf(
                '[Unreleased] still has %d %s',
                $entries,
                $entries === 1 ? 'entry' : 'entries',
            ));
        }
    }
}
