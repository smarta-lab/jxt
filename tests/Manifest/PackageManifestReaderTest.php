<?php

/**
 * @copyright  (C) 2026 Carsten Volkwein
 * @license    MIT License; see LICENSE
 */

declare(strict_types=1);

namespace SmartA\Jxt\Tests\Manifest;

use PHPUnit\Framework\TestCase;
use SmartA\Jxt\Manifest\ManifestException;
use SmartA\Jxt\Manifest\PackageManifestReader;
use SmartA\Jxt\Tests\TemporaryDirectoryTrait;
use ZipArchive;

/**
 * Tests for reading the manifest from a ZIP file.
 */
final class PackageManifestReaderTest extends TestCase
{
    use TemporaryDirectoryTrait;

    private const string MANIFEST = '<extension type="module" method="upgrade"><name>mod_x</name>'
        . '<version>0.9.0</version><files><folder module="mod_x">tmpl</folder></files></extension>';

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

    public function testReadsTheManifestInTheRootDirectory(): void
    {
        $zip = $this->zip([
            'mod_x.xml' => self::MANIFEST,
            'config.xml' => '<config/>',
            'tmpl/default.php' => '<?php',
            'tmpl/other.xml' => self::MANIFEST,
        ]);

        $manifest = (new PackageManifestReader())->read($zip);

        self::assertSame('mod_x', $manifest->element);
        self::assertSame('0.9.0', $manifest->version);
        self::assertTrue($manifest->installer->upgrade);
    }

    public function testFailsWithoutManifest(): void
    {
        $zip = $this->zip(['tmpl/mod_x.xml' => self::MANIFEST, 'broken.xml' => '<extension']);

        $this->expectException(ManifestException::class);
        $this->expectExceptionMessage('No Joomla manifest found in the root directory of ' . $zip);

        (new PackageManifestReader())->read($zip);
    }

    public function testFailsWithSeveralManifests(): void
    {
        $zip = $this->zip(['mod_x.xml' => self::MANIFEST, 'mod_y.xml' => self::MANIFEST]);

        $this->expectException(ManifestException::class);
        $this->expectExceptionMessage('Several Joomla manifests found in ' . $zip . ': mod_x.xml, mod_y.xml.');

        (new PackageManifestReader())->read($zip);
    }

    public function testReportsTheZipFileForAnInvalidManifest(): void
    {
        $zip = $this->zip(['mod_x.xml' => '<extension type="module"><files/></extension>']);

        $this->expectException(ManifestException::class);
        $this->expectExceptionMessage($zip . ': The extension name <name> is missing in the manifest.');

        (new PackageManifestReader())->read($zip);
    }

    public function testFailsForAFileThatIsNoZip(): void
    {
        $this->writeFile('mod_x.zip', 'no zip');
        $path = $this->temporaryDirectory . '/mod_x.zip';

        $this->expectException(ManifestException::class);
        $this->expectExceptionMessage('Cannot open ZIP file ' . $path . '.');

        (new PackageManifestReader())->read($path);
    }

    /**
     * Creates a ZIP file with the given entries and returns its path.
     *
     * @param array<string, string> $entries Content by path inside the ZIP
     */
    private function zip(array $entries): string
    {
        $path = $this->temporaryDirectory . '/mod_x.zip';
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        foreach ($entries as $name => $content) {
            $zip->addFromString($name, $content);
        }

        $zip->close();

        return $path;
    }
}
