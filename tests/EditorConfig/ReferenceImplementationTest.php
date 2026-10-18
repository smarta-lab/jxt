<?php

/**
 * @copyright  (C) 2026 Carsten Volkwein
 * @license    MIT License; see LICENSE
 */

declare(strict_types=1);

namespace SmartA\Jxt\Tests\EditorConfig;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SmartA\Jxt\EditorConfig\Glob;
use SmartA\Jxt\Install\SystemProcessRunner;
use SmartA\Jxt\Tests\TemporaryDirectoryTrait;

/**
 * Compares glob matching with the EditorConfig C core (program `editorconfig`, e.g. Debian/Ubuntu
 * package `editorconfig`); skipped if the program is not installed.
 *
 * Uses the cases of GlobTest except for deliberate deviations, see DEVIATIONS.
 */
final class ReferenceImplementationTest extends TestCase
{
    use TemporaryDirectoryTrait;

    /**
     * Cases of GlobTest in which jxt deliberately differs from the C core, with the reason.
     */
    private const array DEVIATIONS = [
        'question mark: multibyte character' => 'the C core matches bytes, `?` stands for one character',
        'brackets: unclosed bracket is literal' => 'the C core matches nothing for an unclosed `[`',
        'brackets: star inside brackets is literal' => 'the C core matches nothing for `*` inside `[...]`',
    ];

    private string $directory;

    #[\Override]
    protected function setUp(): void
    {
        if (self::program() === null) {
            self::markTestSkipped('The EditorConfig C core (program "editorconfig") is not installed.');
        }

        $this->directory = $this->createTemporaryDirectory();
    }

    #[\Override]
    protected function tearDown(): void
    {
        if (isset($this->directory)) {
            $this->removeTemporaryDirectory();
        }
    }

    #[DataProvider('cases')]
    public function testMatchesLikeTheCCore(string $section, string $file): void
    {
        $this->writeFile('.editorconfig', "root = true\n[{$section}]\nkey = yes\n");
        $path = $this->directory . '/' . $file;
        $result = (new SystemProcessRunner())->run([(string) self::program(), $path]);

        self::assertSame(0, $result->exitCode, $result->output);
        self::assertSame(trim($result->output) === 'key=yes', Glob::fromSection($section, $this->directory)->matches($path));
    }

    /**
     * Returns the cases of GlobTest without the deviations.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function cases(): iterable
    {
        $providers = [
            GlobTest::starCases(),
            GlobTest::questionMarkCases(),
            GlobTest::bracketCases(),
            GlobTest::braceCases(),
            GlobTest::numericRangeCases(),
            GlobTest::doubleStarCases(),
            GlobTest::pathCases(),
        ];

        foreach ($providers as $provider) {
            foreach ($provider as $name => [$section, $file]) {
                if (!array_key_exists($name, self::DEVIATIONS)) {
                    yield $name => [$section, $file];
                }
            }
        }
    }

    /**
     * Returns the path of the program `editorconfig` from PATH, or null if it is not installed.
     */
    private static function program(): ?string
    {
        foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $directory) {
            if ($directory !== '' && is_executable($directory . '/editorconfig')) {
                return $directory . '/editorconfig';
            }
        }

        return null;
    }
}
