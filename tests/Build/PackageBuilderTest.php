<?php

/**
 * @copyright  (C) 2026 Carsten Volkwein
 * @license    MIT License; see LICENSE
 */

declare(strict_types=1);

namespace SmartA\Jxt\Tests\Build;

use PHPUnit\Framework\TestCase;
use SmartA\Jxt\Build\PackageBuilder;
use SmartA\Jxt\Manifest\Manifest;
use SmartA\Jxt\Manifest\ManifestParser;
use SmartA\Jxt\Tests\TemporaryDirectoryTrait;
use ZipArchive;

/**
 * Tests for creating the installable ZIP file.
 */
final class PackageBuilderTest extends TestCase
{
    use TemporaryDirectoryTrait;

    private string $project;

    #[\Override]
    protected function setUp(): void
    {
        $this->project = $this->createTemporaryDirectory();
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->removeTemporaryDirectory();
    }

    public function testBuildsZipWithManifestFilesOnly(): void
    {
        $this->writeFile('mod_x.xml', $this->moduleXml('<version>1.2.0</version>'));
        $this->writeFile('tmpl/default.php', '<?php');
        $this->writeFile('language/de-DE/mod_x.ini', 'A="B"');
        $this->writeFile('media/css/site.css', 'body {}');
        $this->writeFile('composer.json', '{}');
        $this->writeFile('tests/SomeTest.php', '<?php');

        $package = (new PackageBuilder())->build($this->parse('mod_x.xml'), $this->project, $this->project . '/dist');

        self::assertSame($this->project . '/dist/mod_x-1.2.0.zip', $package->path);
        self::assertSame(4, $package->fileCount);
        self::assertSame(
            ['language/de-DE/mod_x.ini', 'media/css/site.css', 'mod_x.xml', 'tmpl/default.php'],
            $this->zipEntries($package->path),
        );
    }

    public function testOmitsVersionWhenManifestHasNone(): void
    {
        $this->writeFile('mod_x.xml', $this->moduleXml(''));
        $this->writeFile('tmpl/default.php', '<?php');
        $this->writeFile('language/de-DE/mod_x.ini');
        $this->writeFile('media/css/site.css');

        $package = (new PackageBuilder())->build($this->parse('mod_x.xml'), $this->project, $this->project . '/dist');

        self::assertSame($this->project . '/dist/mod_x.zip', $package->path);
    }

    public function testPrefixesTemplatePackages(): void
    {
        $this->writeFile('templateDetails.xml', <<<'XML'
            <extension type="template" client="site">
                <name>Cassiopeia</name>
                <version>2.0.0</version>
                <files><filename>index.php</filename></files>
            </extension>
            XML);
        $this->writeFile('index.php', '<?php');

        $package = (new PackageBuilder())->build(
            $this->parse('templateDetails.xml'),
            $this->project,
            $this->project . '/dist',
        );

        self::assertSame($this->project . '/dist/tpl_cassiopeia-2.0.0.zip', $package->path);
    }

    public function testReplacesExistingZip(): void
    {
        $this->writeFile('mod_x.xml', $this->moduleXml('<version>1.2.0</version>'));
        $this->writeFile('tmpl/default.php', '<?php');
        $this->writeFile('tmpl/old.php', '<?php');
        $this->writeFile('language/de-DE/mod_x.ini');
        $this->writeFile('media/css/site.css');
        $builder = new PackageBuilder();
        $builder->build($this->parse('mod_x.xml'), $this->project, $this->project . '/dist');
        unlink($this->project . '/tmpl/old.php');

        $package = $builder->build($this->parse('mod_x.xml'), $this->project, $this->project . '/dist');

        self::assertNotContains('tmpl/old.php', $this->zipEntries($package->path));
    }

    /**
     * Returns a module manifest with `tmpl`, a language file and media files.
     */
    private function moduleXml(string $version): string
    {
        return <<<XML
            <extension type="module" client="site">
                <name>mod_x</name>
                {$version}
                <files><folder module="mod_x">tmpl</folder></files>
                <languages><language tag="de-DE">language/de-DE/mod_x.ini</language></languages>
                <media destination="mod_x" folder="media"><folder>css</folder></media>
            </extension>
            XML;
    }

    /**
     * Parses a manifest from the project directory.
     */
    private function parse(string $fileName): Manifest
    {
        return (new ManifestParser())->parseFile($this->project . '/' . $fileName);
    }

    /**
     * Returns the sorted file names in the ZIP.
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
}
