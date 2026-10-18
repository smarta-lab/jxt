<?php

/**
 * @copyright  (C) 2026 Carsten Volkwein
 * @license    MIT License; see LICENSE
 */

declare(strict_types=1);

namespace SmartA\Jxt\Tests\Check\Checker;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SmartA\Jxt\Check\Checker\ComposerChecker;
use SmartA\Jxt\Check\Checker\ManifestChecker;
use SmartA\Jxt\Tests\Check\CheckProjectTrait;

/**
 * Tests for the checks of the manifest and of `composer.json`.
 */
final class ManifestCheckerTest extends TestCase
{
    use CheckProjectTrait;

    #[\Override]
    protected function setUp(): void
    {
        $this->createProject();
        $this->writeFile('tmpl/default.php');
        $this->writeFile('language/en-GB/mod_example.ini');
        $this->writeFile('language/de-DE/mod_example.ini');
        $this->writeFile('media/joomla.asset.json', '{}');
        $this->writeFile('media/css/site.css');
        $this->writeFile('media/js/site.js');
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->removeTemporaryDirectory();
    }

    public function testCompleteProjectHasNoFindings(): void
    {
        self::assertSame([], $this->findings(new ManifestChecker()));
    }

    public function testReportsMissingAndUnlistedFiles(): void
    {
        unlink($this->temporaryDirectory . '/language/de-DE/mod_example.ini');
        $this->removeDirectory('media/js');
        $this->writeFile('media/svg/icon.svg');

        self::assertSame([
            'ERROR manifest/listed-files: mod_example.xml: lists file language/de-DE/mod_example.ini, which is missing',
            'ERROR manifest/listed-files: mod_example.xml: lists directory media/js, which is missing',
            'WARN manifest/unlisted-files: media/svg/: not listed in the manifest and therefore not part of the extension',
        ], $this->findings(new ManifestChecker()));
    }

    public function testReportsStrayFilesInListedDirectories(): void
    {
        $this->writeFile('media/css/.DS_Store');
        $this->writeFile('media/css/site.css~');
        $this->writeFile('media/css/site.css.orig');
        $this->writeFile('media/css/.idea/workspace.xml');
        $this->writeFile('media/css/.htaccess');
        $this->writeFile('media/css/.gitkeep');

        $stray = static fn (string $path): string => sprintf(
            'WARN manifest/stray-files: %s: would be part of the extension; delete it or move it out of the listed directory',
            $path,
        );

        self::assertSame(
            array_map($stray, ['media/css/.DS_Store', 'media/css/.idea/workspace.xml', 'media/css/site.css.orig', 'media/css/site.css~']),
            $this->findings(new ManifestChecker()),
        );
    }

    #[DataProvider('versions')]
    public function testChecksSemanticVersion(string $version, bool $valid): void
    {
        $this->writeFile('mod_example.xml', str_replace('<version>1.1.0</version>', '<version>' . $version . '</version>', self::MANIFEST));

        $expected = $valid ? [] : [sprintf('ERROR manifest/version-semver: mod_example.xml: <version> %s is not a semantic version', $version)];
        self::assertSame($expected, $this->findings(new ManifestChecker()));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function versions(): iterable
    {
        yield 'release' => ['1.2.3', true];
        yield 'pre-release' => ['2.0.0-beta.1', true];
        yield 'build metadata' => ['1.0.0+20261016', true];
        yield 'two parts' => ['1.2', false];
        yield 'leading zero' => ['01.2.3', false];
        yield 'prefix' => ['v1.2.3', false];
        yield 'date' => ['2026-10-16', false];
    }

    public function testReportsMissingVersion(): void
    {
        $this->writeFile('mod_example.xml', str_replace('<version>1.1.0</version>', '', self::MANIFEST));

        self::assertSame(['ERROR manifest/version-semver: mod_example.xml: has no <version>'], $this->findings(new ManifestChecker()));
    }

    public function testReportsVersionFieldInComposerJson(): void
    {
        self::assertSame([], $this->findings(new ComposerChecker()));

        $this->writeFile('composer.json', '{"name": "a/b", "version": "1.0.0"}');

        self::assertSame(
            ['WARN composer/version-field: composer.json: has a "version" field; Composer recommends omitting it and using Git tags'],
            $this->findings(new ComposerChecker()),
        );
    }

    /**
     * Removes a directory of the project including its content.
     */
    private function removeDirectory(string $relativePath): void
    {
        foreach (glob($this->temporaryDirectory . '/' . $relativePath . '/*') ?: [] as $file) {
            unlink($file);
        }

        rmdir($this->temporaryDirectory . '/' . $relativePath);
    }
}
