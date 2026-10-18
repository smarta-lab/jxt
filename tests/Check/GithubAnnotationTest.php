<?php

/**
 * @copyright  (C) 2026 Carsten Volkwein
 * @license    MIT License; see LICENSE
 */

declare(strict_types=1);

namespace SmartA\Jxt\Tests\Check;

use PHPUnit\Framework\TestCase;
use SmartA\Jxt\Check\CheckName;
use SmartA\Jxt\Check\Finding;
use SmartA\Jxt\Check\GithubAnnotation;
use SmartA\Jxt\Check\Severity;

/**
 * Tests for the GitHub Actions workflow commands of `jxt check --format=github`.
 */
final class GithubAnnotationTest extends TestCase
{
    public function testFindingWithFileAndLine(): void
    {
        $finding = new Finding(
            CheckName::EditorConfigTrailingWhitespace,
            Severity::Error,
            'trailing whitespace in 2 line(s), first: line 3',
            'tmpl/default.php',
            3,
        );

        self::assertSame(
            '::error file=tmpl/default.php,line=3,title=editorconfig/trailing-whitespace::'
                . 'trailing whitespace in 2 line(s), first: line 3',
            GithubAnnotation::forFinding($finding),
        );
    }

    public function testFindingWithoutLocation(): void
    {
        $finding = new Finding(CheckName::ChangelogUnreleased, Severity::Notice, '[Unreleased] has 0 entries');

        self::assertSame(
            '::notice title=changelog/unreleased::[Unreleased] has 0 entries',
            GithubAnnotation::forFinding($finding),
        );
    }

    public function testPathPrefixLeadsToTheRepositoryRoot(): void
    {
        $finding = new Finding(CheckName::ManifestStrayFiles, Severity::Warning, 'would be part of the extension', 'media/.DS_Store');

        self::assertSame(
            '::warning file=extension/media/.DS_Store,title=manifest/stray-files::would be part of the extension',
            GithubAnnotation::forFinding($finding, 'extension/'),
        );
    }

    public function testEscapesMessageAndProperties(): void
    {
        self::assertSame(
            '::error file=a%2Cb%3Ac.txt::100%25 wrong%0D%0Asecond line: a, b',
            GithubAnnotation::command(Severity::Error, "100% wrong\r\nsecond line: a, b", ['file' => 'a,b:c.txt']),
        );
    }
}
