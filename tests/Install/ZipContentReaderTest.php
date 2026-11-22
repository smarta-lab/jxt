<?php

/**
 * @copyright  (C) 2026 Carsten Volkwein
 * @license    MIT License; see LICENSE
 */

declare(strict_types=1);

namespace SmartA\Jxt\Tests\Install;

use PHPUnit\Framework\TestCase;
use SmartA\Jxt\Deployment\PlanEntry;
use SmartA\Jxt\Install\ZipContent;
use SmartA\Jxt\Install\ZipContentReader;
use SmartA\Jxt\Install\ZipExtension;
use SmartA\Jxt\Manifest\PackageManifestReader;
use SmartA\Jxt\Tests\TemporaryDirectoryTrait;
use ZipArchive;

/**
 * Tests for reading the extensions and their files from a ZIP file that the Joomla installer installs.
 */
final class ZipContentReaderTest extends TestCase
{
    use TemporaryDirectoryTrait;

    private const string MODULE = '<extension type="module" client="site"><name>mod_x</name><element>mod_x</element>'
        . '<files><folder>tmpl</folder></files></extension>';
    private const string PLUGIN = '<extension type="plugin" group="system"><name>x</name>'
        . '<files><folder plugin="x">src</folder></files></extension>';

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

    public function testReturnsTheExtensionOfAPlainZip(): void
    {
        $zip = $this->zip('mod_x.zip', ['mod_x.xml' => self::MODULE, 'tmpl/default.php' => '<?php']);

        $content = $this->read($zip);

        self::assertSame(['mod_x'], $this->names($content->extensions));
        self::assertSame(['modules/mod_x/mod_x.xml', 'modules/mod_x/tmpl/default.php'], $this->targets($content->extensions[0]));
        self::assertSame([], $content->skipped);
    }

    public function testReturnsTheExtensionsOfAPackageFromZipFilesAndDirectories(): void
    {
        $plugin = $this->zip('plugin.zip', ['x.xml' => self::PLUGIN, 'src/X.php' => '<?php']);
        $package = $this->zip('pkg_x.zip', [
            'pkg_x.xml' => $this->packageXml(
                '<file type="plugin" id="x" group="system">plg_system_x.zip</file>'
                . '<folder type="module" id="mod_x" client="site">mod_x</folder>',
            ),
            'packages/plg_system_x.zip' => (string) file_get_contents($plugin),
            'packages/mod_x/mod_x.xml' => self::MODULE,
            'packages/mod_x/tmpl/default.php' => '<?php',
        ]);

        $content = $this->read($package);

        self::assertSame(['plg_system_x', 'mod_x'], $this->names($content->extensions));
        self::assertSame(['plugins/system/x/x.xml', 'plugins/system/x/src/X.php'], $this->targets($content->extensions[0]));
        self::assertSame(['modules/mod_x/mod_x.xml', 'modules/mod_x/tmpl/default.php'], $this->targets($content->extensions[1]));
        self::assertSame([], $content->skipped);
    }

    public function testSkipsEntriesThatAreMissingOrCannotBeRead(): void
    {
        $nested = $this->zip('nested.zip', ['pkg_y.xml' => '<extension type="package"><name>y</name><packagename>y</packagename></extension>']);
        $package = $this->zip('pkg_x.zip', [
            'pkg_x.xml' => $this->packageXml(
                '<file type="module" id="mod_missing" client="site">missing.zip</file>'
                . '<file type="file" id="files_x">files_x.zip</file>'
                . '<file type="package" id="pkg_y">pkg_y.zip</file>',
            ),
            'packages/files_x.zip' => (string) file_get_contents(
                $this->zip('files.zip', ['x.xml' => '<extension type="file"><name>x</name></extension>']),
            ),
            'packages/pkg_y.zip' => (string) file_get_contents($nested),
        ]);

        $content = $this->read($package);

        self::assertSame([], $content->extensions);
        self::assertSame(['packages/missing.zip', 'packages/files_x.zip', 'packages/pkg_y.zip'], array_keys($content->skipped));
        self::assertSame('missing in the package', $content->skipped['packages/missing.zip']);
        self::assertStringContainsString('"file" is not supported', $content->skipped['packages/files_x.zip']);
        self::assertSame('a package in a package', $content->skipped['packages/pkg_y.zip']);
    }

    private function read(string $zip): ZipContent
    {
        return (new ZipContentReader())->read((new PackageManifestReader())->read($zip), $zip);
    }

    private function packageXml(string $entries): string
    {
        return '<extension type="package"><name>pkg_x</name><packagename>x</packagename>'
            . '<files folder="packages">' . $entries . '</files></extension>';
    }

    /**
     * @param array<string, string> $files Content by path in the ZIP file
     */
    private function zip(string $name, array $files): string
    {
        $path = $this->temporaryDirectory . '/' . $name;
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        foreach ($files as $file => $content) {
            $zip->addFromString($file, $content);
        }

        $zip->close();

        return $path;
    }

    /**
     * @param list<ZipExtension> $extensions
     *
     * @return list<string>
     */
    private function names(array $extensions): array
    {
        return array_map(static fn (ZipExtension $extension): string => $extension->manifest->name(), $extensions);
    }

    /**
     * @return list<string>
     */
    private function targets(ZipExtension $extension): array
    {
        return array_map(static fn (PlanEntry $file): string => $file->target, $extension->files);
    }
}
