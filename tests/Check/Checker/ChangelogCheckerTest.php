<?php

declare(strict_types=1);

namespace SmartA\Jxt\Tests\Check\Checker;

use PHPUnit\Framework\TestCase;
use SmartA\Jxt\Check\Checker\ChangelogChecker;
use SmartA\Jxt\Tests\Check\CheckProjectTrait;

/**
 * Tests for the checks of `CHANGELOG.md`; the mode comes from the changelog, as there is no Git repository.
 */
final class ChangelogCheckerTest extends TestCase
{
    use CheckProjectTrait;

    #[\Override]
    protected function setUp(): void
    {
        $this->createProject();
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->removeTemporaryDirectory();
    }

    public function testMissingOrOtherFormatIsANotice(): void
    {
        self::assertSame(
            ['INFO changelog/format: CHANGELOG.md is missing, changelog checks skipped (see https://keepachangelog.com)'],
            $this->findings(new ChangelogChecker()),
        );

        $this->writeFile('CHANGELOG.md', "# Changes\n\nVersion 1.1.0: lots\n");

        self::assertSame(
            [
                'INFO changelog/format: CHANGELOG.md does not use the format of "Keep a Changelog", '
                    . 'changelog checks skipped (see https://keepachangelog.com)',
            ],
            $this->findings(new ChangelogChecker()),
        );
    }

    public function testPreCheckCountsUnreleasedEntries(): void
    {
        $this->writeFile('CHANGELOG.md', "## [Unreleased]\n\n- One\n\n## [1.1.0] - 2026-10-01\n\n- Old\n");

        self::assertSame(['INFO changelog/unreleased: [Unreleased] has 1 entry'], $this->findings(new ChangelogChecker()));
    }

    public function testPreCheckReportsWhenManifestIsNotTheLastRelease(): void
    {
        $this->writeFile('CHANGELOG.md', "## [Unreleased]\n\n## [1.2.0] - 2026-10-01\n\n## [1.1.0] - 2026-09-01\n");

        self::assertSame([
            'WARN changelog/last-release: manifest <version> is 1.1.0, but the last release in CHANGELOG.md is 1.2.0',
            'INFO changelog/unreleased: [Unreleased] has 0 entries',
        ], $this->findings(new ChangelogChecker()));
    }

    public function testFinalCheckReportsMissingHeadingAndUnreleasedEntries(): void
    {
        $this->writeFile('CHANGELOG.md', "## [Unreleased]\n\n- One\n- Two\n\n## [1.0.0] - 2026-09-01\n");

        self::assertSame([
            'INFO changelog/release-heading: no heading "## [1.1.0] - YYYY-MM-DD" in CHANGELOG.md',
            'INFO changelog/unreleased: [Unreleased] still has 2 entries',
        ], $this->findings(new ChangelogChecker()));
        self::assertSame([
            'ERROR changelog/release-heading: no heading "## [1.1.0] - YYYY-MM-DD" in CHANGELOG.md',
            'ERROR changelog/unreleased: [Unreleased] still has 2 entries',
        ], $this->findings(new ChangelogChecker(), $this->context(true)));
    }

    public function testFinalCheckWithGitPassesAfterReleasePreparation(): void
    {
        $this->writeFile('CHANGELOG.md', "## [Unreleased]\n\n## [1.1.0] - 2026-10-16\n\n- New\n\n## [1.0.0] - 2026-09-01\n");
        $this->initGit();
        $this->git('tag', 'v1.0.0');

        self::assertSame([], $this->findings(new ChangelogChecker(), $this->context(true)));
    }
}
