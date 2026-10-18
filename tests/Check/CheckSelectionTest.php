<?php

/**
 * @copyright  (C) 2026 Carsten Volkwein
 * @license    MIT License; see LICENSE
 */

declare(strict_types=1);

namespace SmartA\Jxt\Tests\Check;

use PHPUnit\Framework\TestCase;
use SmartA\Jxt\Check\CheckException;
use SmartA\Jxt\Check\CheckName;
use SmartA\Jxt\Check\CheckReport;
use SmartA\Jxt\Check\CheckSelection;
use SmartA\Jxt\Check\Severity;

/**
 * Tests for selecting checks with exclude and include lists, and for the report that applies the selection.
 */
final class CheckSelectionTest extends TestCase
{
    public function testEverythingRunsByDefault(): void
    {
        $selection = CheckSelection::create();

        foreach (CheckName::cases() as $check) {
            self::assertTrue($selection->isEnabled($check), $check->value);
        }
    }

    public function testExcludesGroupsAndSingleChecks(): void
    {
        $selection = CheckSelection::create(['manifest', 'assets/uri']);

        self::assertFalse($selection->isEnabled(CheckName::ManifestVersionSemver));
        self::assertFalse($selection->isEnabled(CheckName::AssetsUri));
        self::assertTrue($selection->isEnabled(CheckName::AssetsJson));
        self::assertFalse($selection->isGroupEnabled('manifest'));
        self::assertTrue($selection->isGroupEnabled('assets'));
    }

    public function testMoreSpecificNameWins(): void
    {
        $selection = CheckSelection::create(['manifest'], ['manifest/version-semver']);

        self::assertTrue($selection->isEnabled(CheckName::ManifestVersionSemver));
        self::assertFalse($selection->isEnabled(CheckName::ManifestListedFiles));
        self::assertTrue($selection->isGroupEnabled('manifest'));

        $selection = CheckSelection::create(['manifest/version-semver'], ['manifest']);

        self::assertFalse($selection->isEnabled(CheckName::ManifestVersionSemver));
        self::assertTrue($selection->isEnabled(CheckName::ManifestListedFiles));
    }

    public function testCommandLineWinsOverProjectForTheSameName(): void
    {
        self::assertTrue(CheckSelection::create(['assets'], [], [], ['assets'])->isEnabled(CheckName::AssetsUri));
        self::assertFalse(CheckSelection::create([], ['assets'], ['assets'])->isEnabled(CheckName::AssetsUri));
    }

    public function testCommandLineOverridesMoreSpecificProjectNames(): void
    {
        self::assertTrue(CheckSelection::create(['assets/uri'], [], [], ['assets'])->isEnabled(CheckName::AssetsUri));
        self::assertFalse(CheckSelection::create([], ['assets/uri'], ['assets'])->isEnabled(CheckName::AssetsUri));
        self::assertTrue(CheckSelection::create(['assets/uri', 'editorconfig'], [], [], ['all'])->isEnabled(CheckName::AssetsUri));
    }

    public function testAllIsLessSpecificThanGroupsAndChecks(): void
    {
        $selection = CheckSelection::create([], [], ['all'], ['editorconfig/charset', 'assets']);

        self::assertTrue($selection->isEnabled(CheckName::EditorConfigCharset));
        self::assertFalse($selection->isEnabled(CheckName::EditorConfigEndOfLine));
        self::assertTrue($selection->isEnabled(CheckName::AssetsUri));
        self::assertFalse($selection->isGroupEnabled('manifest'));

        $selection = CheckSelection::create(['all'], ['manifest/version-semver']);

        self::assertTrue($selection->isEnabled(CheckName::ManifestVersionSemver));
        self::assertFalse($selection->isEnabled(CheckName::ManifestListedFiles));
    }

    public function testExcludingAllRunsNothing(): void
    {
        $selection = CheckSelection::create([], [], ['all']);

        foreach (CheckName::groups() as $group) {
            self::assertFalse($selection->isGroupEnabled($group), $group);
        }
    }

    public function testIncludeWinsWithinTheSameSource(): void
    {
        self::assertTrue(CheckSelection::create([], [], ['assets'], ['assets'])->isEnabled(CheckName::AssetsUri));
    }

    public function testRejectsUnknownNames(): void
    {
        $this->expectException(CheckException::class);
        $this->expectExceptionMessageMatches('/Unknown check "manifest\/foo". Allowed are all, the groups manifest, /');

        CheckSelection::create([], [], ['manifest/foo']);
    }

    public function testReportDropsExcludedChecksAndCountsSeverities(): void
    {
        $report = new CheckReport(CheckSelection::create(['assets/uri']));
        $report->add(CheckName::AssetsUri, Severity::Error, 'dropped');
        $report->add(CheckName::AssetsJson, Severity::Warning, 'kept');
        $report->add(CheckName::ManifestStrayFiles, Severity::Notice, 'kept');

        self::assertCount(1, $report->findings('assets'));
        self::assertSame(0, $report->count(Severity::Error));
        self::assertFalse($report->failed(false));
        self::assertTrue($report->failed(true));
    }
}
