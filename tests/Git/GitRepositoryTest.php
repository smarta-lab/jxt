<?php

declare(strict_types=1);

namespace SmartA\Jxt\Tests\Git;

use PHPUnit\Framework\TestCase;
use SmartA\Jxt\Git\GitException;
use SmartA\Jxt\Git\GitRepository;
use SmartA\Jxt\Install\SystemProcessRunner;
use SmartA\Jxt\Tests\Check\CheckProjectTrait;
use ZipArchive;

/**
 * Tests for reading tags, files, file states and archives from a Git repository.
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

    public function testReturnsThePathOfTheDirectoryInTheRepository(): void
    {
        $git = GitRepository::open($this->temporaryDirectory . '/media/css', new SystemProcessRunner());

        self::assertNotNull($git);
        self::assertSame('media/css/', $git->prefix());
        self::assertSame('', $this->repository()->prefix());
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

    public function testListsFilesAndComparesAtATag(): void
    {
        $this->writeFile('media/css/site.css', "b {}\n");
        $this->commit('Change style');
        $this->git('tag', 'v1.1.0');
        $this->writeFile('media/css/site.css', "c {}\n");
        $this->writeFile('untracked.txt');
        $this->git('rm', '--quiet', '.gitignore');
        $git = $this->repository();

        self::assertEqualsCanonicalizing(['.gitignore', 'media/css/site.css', 'mod_example.xml'], $git->atTag('v1.0.0')->files());
        self::assertTrue($git->atTag('v1.1.0')->hasChanged('v1.0.0', 'media/css/site.css'));
        self::assertFalse($git->atTag('v1.0.0')->hasChanged('v1.0.0', 'media/css/site.css'));
        self::assertSame(['v1.0.0', 'v1.1.0'], $git->atTag('v1.1.0')->tags());
    }

    public function testArchivesTheFilesOfATagBelowTheDirectory(): void
    {
        $this->writeFile('media/.gitattributes', "/build.js export-ignore\n");
        $this->writeFile('media/build.js');
        $this->commit('Add build script');
        $this->git('tag', 'v1.1.0');
        $this->writeFile('media/css/site.css', "b {}\n");
        $git = GitRepository::open($this->temporaryDirectory . '/media', new SystemProcessRunner());
        self::assertNotNull($git);

        $git->archive('v1.1.0', $this->temporaryDirectory . '/tag.zip');

        $zip = new ZipArchive();
        self::assertTrue($zip->open($this->temporaryDirectory . '/tag.zip'));
        self::assertSame("a {}\n", $zip->getFromName('css/site.css'));
        self::assertFalse($zip->getFromName('build.js'));
        $zip->close();
    }

    public function testArchiveFailsForUnknownTag(): void
    {
        $this->expectException(GitException::class);
        $this->expectExceptionMessage('Tag v9.9.9 not found');

        $this->repository()->archive('v9.9.9', $this->temporaryDirectory . '/tag.zip');
    }

    public function testArchiveDoesNotTakeBranchesOrOptionsForTags(): void
    {
        $this->expectException(GitException::class);

        $this->repository()->archive('--remote=elsewhere', $this->temporaryDirectory . '/tag.zip');
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
