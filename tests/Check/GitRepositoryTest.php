<?php

declare(strict_types=1);

namespace SmartA\Jxt\Tests\Check;

use PHPUnit\Framework\TestCase;
use SmartA\Jxt\Check\GitRepository;
use SmartA\Jxt\Install\SystemProcessRunner;

/**
 * Tests for reading tags, files and file states from a Git repository.
 */
final class GitRepositoryTest extends TestCase
{
    use CheckProjectTrait;

    #[\Override]
    protected function setUp(): void
    {
        $this->createProject();
        $this->writeFile('media/css/site.css', "a {}\n");
        $this->writeFile('.gitignore', "/ignored.txt\n");
        $this->initGit();
        $this->git('tag', 'v1.0.0');
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->removeTemporaryDirectory();
    }

    public function testListsTagsAndFiles(): void
    {
        $this->writeFile('untracked file.txt');
        $this->writeFile('ignored.txt');
        $git = $this->repository();

        self::assertSame(['v1.0.0'], $git->tags());
        self::assertEqualsCanonicalizing(
            ['.gitignore', 'media/css/site.css', 'mod_example.xml', 'untracked file.txt'],
            $git->files(),
        );
    }

    public function testListsFilesRelativeToASubdirectory(): void
    {
        $git = GitRepository::open($this->temporaryDirectory . '/media', new SystemProcessRunner());

        self::assertNotNull($git);
        self::assertSame(['css/site.css'], $git->files());
        self::assertSame("a {}\n", $git->show('v1.0.0', 'css/site.css'));
    }

    public function testComparesWithATag(): void
    {
        $git = $this->repository();

        self::assertFalse($git->hasChanged('v1.0.0', 'media/css/site.css'));
        $this->writeFile('media/css/site.css', "b {}\n");
        self::assertTrue($git->hasChanged('v1.0.0', 'media/css/site.css'));
        self::assertSame("a {}\n", $git->show('v1.0.0', 'media/css/site.css'));
        self::assertNull($git->show('v1.0.0', 'media/css/missing.css'));
    }

    /**
     * Opens the repository of the project directory.
     */
    private function repository(): GitRepository
    {
        $git = GitRepository::open($this->temporaryDirectory, new SystemProcessRunner());
        self::assertNotNull($git);

        return $git;
    }
}
