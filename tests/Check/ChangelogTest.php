<?php

declare(strict_types=1);

namespace SmartA\Jxt\Tests\Check;

use PHPUnit\Framework\TestCase;
use SmartA\Jxt\Check\Changelog;

/**
 * Tests for reading a changelog in the format of "Keep a Changelog".
 */
final class ChangelogTest extends TestCase
{
    private const string CONTENT = <<<'MD'
        # Changelog

        ## [Unreleased]

        ### Added

        - New option
        - Another option

        ### Fixed

        * A bug

        ## [1.10.0] - 2026-10-01

        - Something

        ## [1.9.0] - 2026-09-01

        ## [2.0.0-beta.1]

        [Unreleased]: https://example.org/compare/v1.10.0...HEAD
        - not an entry
        MD;

    public function testRecognizesTheFormat(): void
    {
        self::assertTrue((new Changelog(self::CONTENT))->isKeepAChangelog());
        self::assertTrue((new Changelog("## [1.0.0] - 2026-01-01\n"))->isKeepAChangelog());
        self::assertFalse((new Changelog("# Changes\n\n## Version 1.0\n"))->isKeepAChangelog());
    }

    public function testReadsReleasesWithDates(): void
    {
        $changelog = new Changelog(self::CONTENT);

        self::assertSame(['1.10.0' => '2026-10-01', '1.9.0' => '2026-09-01', '2.0.0-beta.1' => null], $changelog->releases());
        self::assertSame('2.0.0-beta.1', $changelog->lastRelease());
        self::assertTrue($changelog->isReleased('1.10.0'));
        self::assertFalse($changelog->isReleased('2.0.0-beta.1'));
        self::assertFalse($changelog->isReleased('1.11.0'));
    }

    public function testComparesVersionsNumerically(): void
    {
        self::assertSame('1.10.0', (new Changelog("## [1.9.0] - 2026-09-01\n## [1.10.0] - 2026-10-01\n"))->lastRelease());
        self::assertNull((new Changelog("## [Unreleased]\n"))->lastRelease());
    }

    public function testCountsUnreleasedEntries(): void
    {
        self::assertSame(3, (new Changelog(self::CONTENT))->unreleasedEntries());
        self::assertSame(0, (new Changelog("## [Unreleased]\n\n## [1.0.0] - 2026-01-01\n\n- Old\n"))->unreleasedEntries());
        self::assertSame(0, (new Changelog("## [1.0.0] - 2026-01-01\n\n- Old\n"))->unreleasedEntries());
    }
}
