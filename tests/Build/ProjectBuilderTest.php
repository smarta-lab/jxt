<?php

declare(strict_types=1);

namespace SmartA\Jxt\Tests\Build;

use PHPUnit\Framework\TestCase;
use SmartA\Jxt\Build\BuildException;
use SmartA\Jxt\Build\ProjectBuilder;
use SmartA\Jxt\Configuration\ConfigurationLoader;
use SmartA\Jxt\Git\GitException;
use SmartA\Jxt\Install\ProcessResult;
use SmartA\Jxt\Tests\GitTrait;
use SmartA\Jxt\Tests\Install\RecordingProcessRunner;
use SmartA\Jxt\Tests\TemporaryDirectoryTrait;
use ZipArchive;

/**
 * Tests for building the ZIP file from the working copy or from a Git tag.
 */
final class ProjectBuilderTest extends TestCase
{
    use GitTrait;
    use TemporaryDirectoryTrait;

    private string $project;

    private string $dist;

    #[\Override]
    protected function setUp(): void
    {
        $this->createTemporaryDirectory();
        $this->project = $this->temporaryDirectory . '/project';
        $this->dist = $this->temporaryDirectory . '/dist';
        $this->writeFile('project/mod_x.xml', self::manifest('1.0.0'));
        $this->writeFile('project/tmpl/default.php', 'tagged');
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->removeTemporaryDirectory();
    }

    public function testBuildsFromTheWorkingCopy(): void
    {
        $build = $this->builder()->build($this->project, $this->dist);

        self::assertSame('1.0.0', $build->manifest->version);
        self::assertSame($this->dist . '/mod_x-1.0.0.zip', $build->package->path);
        self::assertSame([], $build->unlisted);
        self::assertNull($build->tag);
        self::assertTrue($build->tagMatchesVersion());
    }

    public function testBuildsFromATagInASubdirectoryOfTheRepository(): void
    {
        $this->initGit();
        $this->git('tag', 'v1.0.0');
        $this->writeFile('project/mod_x.xml', self::manifest('1.1.0'));
        $this->writeFile('project/tmpl/default.php', 'changed');
        $this->writeFile('project/tmpl/new.php', 'untracked');

        $build = $this->builder()->buildTag($this->project, 'v1.0.0', $this->dist);

        self::assertSame('v1.0.0', $build->tag);
        self::assertSame('1.0.0', $build->manifest->version);
        self::assertTrue($build->tagMatchesVersion());
        self::assertSame(['mod_x.xml', 'tmpl/default.php'], $this->zipEntries($build->package->path));
        self::assertSame('tagged', $this->zipContent($build->package->path, 'tmpl/default.php'));
    }

    public function testTakesTheManifestSettingFromTheTag(): void
    {
        unlink($this->project . '/mod_x.xml');
        $this->writeFile('project/extension/mod_x.xml', self::manifest('1.0.0'));
        rename($this->project . '/tmpl', $this->project . '/extension/tmpl');
        $this->writeFile('project/.jxt.json', '{"manifest": "extension/mod_x.xml"}');
        $this->initGit();
        $this->git('tag', '1.0.0');
        $this->writeFile('project/.jxt.json', '{"manifest": "moved/mod_x.xml"}');

        $build = $this->builder()->buildTag($this->project, '1.0.0', $this->dist);

        self::assertSame(['mod_x.xml', 'tmpl/default.php'], $this->zipEntries($build->package->path));
    }

    public function testReportsATagThatDoesNotNameTheVersion(): void
    {
        $this->initGit();
        $this->git('tag', 'release');

        self::assertFalse($this->builder()->buildTag($this->project, 'release', $this->dist)->tagMatchesVersion());
    }

    public function testFailsForAnUnknownTag(): void
    {
        $this->initGit();

        $this->expectException(GitException::class);
        $this->expectExceptionMessage('Tag v1.0.0 not found');

        $this->builder()->buildTag($this->project, 'v1.0.0', $this->dist);
    }

    public function testFailsForATagWithoutGitRepository(): void
    {
        $noGit = new RecordingProcessRunner(new ProcessResult(128, 'fatal: not a git repository'));

        $this->expectException(BuildException::class);
        $this->expectExceptionMessage('not in a Git repository');

        (new ProjectBuilder(new ConfigurationLoader(null), processRunner: $noGit))->buildTag($this->project, 'v1.0.0', $this->dist);
    }

    /**
     * Returns a module manifest with the version and a `tmpl` folder.
     */
    private static function manifest(string $version): string
    {
        return <<<XML
            <extension type="module"><name>mod_x</name><version>{$version}</version>
                <files><folder module="mod_x">tmpl</folder></files>
            </extension>
            XML;
    }

    /**
     * Creates the builder without global configuration.
     */
    private function builder(): ProjectBuilder
    {
        return new ProjectBuilder(new ConfigurationLoader(null));
    }

    /**
     * Returns the sorted entry names of a ZIP file.
     *
     * @return list<string>
     */
    private function zipEntries(string $path): array
    {
        $zip = new ZipArchive();
        self::assertTrue($zip->open($path));
        $entries = [];

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $entries[] = (string) $zip->getNameIndex($index);
        }

        $zip->close();
        sort($entries);

        return $entries;
    }

    /**
     * Returns the content of an entry of a ZIP file.
     */
    private function zipContent(string $path, string $entry): string
    {
        $zip = new ZipArchive();
        self::assertTrue($zip->open($path));
        $content = $zip->getFromName($entry);
        $zip->close();
        self::assertIsString($content);

        return $content;
    }
}
