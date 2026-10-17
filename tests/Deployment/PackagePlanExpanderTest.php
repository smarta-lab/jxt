<?php

declare(strict_types=1);

namespace SmartA\Jxt\Tests\Deployment;

use PHPUnit\Framework\TestCase;
use SmartA\Jxt\Deployment\DeploymentException;
use SmartA\Jxt\Deployment\EntryKind;
use SmartA\Jxt\Deployment\PackagePlanExpander;
use SmartA\Jxt\Deployment\PlanEntry;
use SmartA\Jxt\Manifest\ManifestParser;
use SmartA\Jxt\Tests\TemporaryDirectoryTrait;
use ZipArchive;

/**
 * Tests for expanding a deployment plan into the files of a ZIP file.
 */
final class PackagePlanExpanderTest extends TestCase
{
    use TemporaryDirectoryTrait;

    #[\Override]
    protected function setUp(): void
    {
        $this->createTemporaryDirectory();
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->removeTemporaryDirectory();
    }

    public function testExpandsDirectoriesIntoTheFilesOfTheZip(): void
    {
        $path = $this->writeZip(['mod_x.xml', 'tmpl/', 'tmpl/z.php', 'tmpl/a/b.php', 'media/css/x.css', 'other.txt']);

        self::assertEquals([
            new PlanEntry('mod_x.xml', 'modules/mod_x/mod_x.xml', EntryKind::File),
            new PlanEntry('tmpl/a/b.php', 'modules/mod_x/tmpl/a/b.php', EntryKind::File),
            new PlanEntry('tmpl/z.php', 'modules/mod_x/tmpl/z.php', EntryKind::File),
            new PlanEntry('media/css/x.css', 'media/mod_x/css/x.css', EntryKind::File),
        ], $this->expand($path));
    }

    public function testSkipsEntriesMissingFromTheZip(): void
    {
        $path = $this->writeZip(['tmpl/default.php']);

        self::assertEquals(
            [new PlanEntry('tmpl/default.php', 'modules/mod_x/tmpl/default.php', EntryKind::File)],
            $this->expand($path),
        );
    }

    public function testFailsForAnUnreadableZip(): void
    {
        $this->writeFile('broken.zip', 'no zip');

        $this->expectException(DeploymentException::class);
        $this->expectExceptionMessage('Cannot open ZIP file');

        $this->expand($this->temporaryDirectory . '/broken.zip');
    }

    /**
     * Expands the plan of `mod_x` with a `tmpl` folder and a `<media>` section.
     *
     * @return list<PlanEntry>
     */
    private function expand(string $path): array
    {
        $manifest = (new ManifestParser())->parse(
            '<extension type="module"><name>mod_x</name><files><folder module="mod_x">tmpl</folder></files>'
                . '<media destination="mod_x" folder="media"><folder>css</folder></media></extension>',
            'mod_x.xml',
        );

        return (new PackagePlanExpander())->expand($manifest->plan, $path);
    }

    /**
     * Writes a ZIP file with the given entries; names ending in `/` become directory entries.
     *
     * @param list<string> $names
     */
    private function writeZip(array $names): string
    {
        $path = $this->temporaryDirectory . '/package.zip';
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE);

        foreach ($names as $name) {
            str_ends_with($name, '/') ? $zip->addEmptyDir(rtrim($name, '/')) : $zip->addFromString($name, '');
        }

        $zip->close();

        return $path;
    }
}
