<?php

declare(strict_types=1);

namespace SmartA\Jxt\Tests\Build;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use SmartA\Jxt\Build\TagExporter;
use SmartA\Jxt\Git\GitRepository;
use SmartA\Jxt\Install\SystemProcessRunner;
use SmartA\Jxt\Tests\GitTrait;
use SmartA\Jxt\Tests\TemporaryDirectoryTrait;

/**
 * Tests for exporting the files of a tag into a temporary directory.
 */
final class TagExporterTest extends TestCase
{
    use GitTrait;
    use TemporaryDirectoryTrait;

    private GitRepository $git;

    #[\Override]
    protected function setUp(): void
    {
        $this->createTemporaryDirectory();
        $this->writeFile('tmpl/default.php', 'tagged');
        $this->initGit();
        $this->git('tag', 'v1.0.0');
        $this->writeFile('tmpl/default.php', 'changed');
        $this->writeFile('untracked.txt');
        $git = GitRepository::open($this->temporaryDirectory, new SystemProcessRunner());
        self::assertNotNull($git);
        $this->git = $git;
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->removeTemporaryDirectory();
    }

    public function testPassesTheFilesOfTheTagAndRemovesThemAfterwards(): void
    {
        $exported = null;

        $result = (new TagExporter())->export($this->git, 'v1.0.0', static function (string $directory) use (&$exported): string {
            $exported = $directory;

            return (string) file_get_contents($directory . '/tmpl/default.php')
                . (is_file($directory . '/untracked.txt') ? ' with untracked file' : '');
        });

        self::assertSame('tagged', $result);
        self::assertIsString($exported);
        self::assertDirectoryDoesNotExist($exported);
    }

    public function testRemovesTheFilesAfterAnException(): void
    {
        $exported = null;

        try {
            (new TagExporter())->export($this->git, 'v1.0.0', static function (string $directory) use (&$exported): never {
                $exported = $directory;

                throw new RuntimeException('failed');
            });
        } catch (RuntimeException) {
        }

        self::assertIsString($exported);
        self::assertDirectoryDoesNotExist($exported);
    }
}
