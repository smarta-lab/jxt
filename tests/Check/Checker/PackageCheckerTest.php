<?php

/**
 * @copyright  (C) 2026 Carsten Volkwein
 * @license    MIT License; see LICENSE
 */

declare(strict_types=1);

namespace SmartA\Jxt\Tests\Check\Checker;

use PHPUnit\Framework\TestCase;
use SmartA\Jxt\Check\Checker\PackageChecker;
use SmartA\Jxt\Check\CheckContext;
use SmartA\Jxt\Check\CheckContextFactory;
use SmartA\Jxt\Manifest\ManifestParser;
use SmartA\Jxt\Manifest\PackageResolver;
use SmartA\Jxt\Tests\Check\CheckProjectTrait;

/**
 * Tests for the checks of a package manifest against the extensions in its subdirectories.
 */
final class PackageCheckerTest extends TestCase
{
    use CheckProjectTrait;

    private const string PLUGIN = '<extension type="plugin" group="system"><name>plg_system_x</name><version>1.0.0</version>'
        . '<files><folder plugin="x">src</folder></files></extension>';

    #[\Override]
    protected function setUp(): void
    {
        $this->createTemporaryDirectory();
        $this->writeFile('plugin/x.xml', self::PLUGIN);
        $this->writeFile('plugin/src/X.php', '<?php');
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->removeTemporaryDirectory();
    }

    public function testAcceptsAPackageWhoseEntriesMatchItsExtensions(): void
    {
        $this->writePackage('<file type="plugin" id="x" group="system">plg_system_x-1.0.0.zip</file>');

        self::assertSame([], $this->findings(new PackageChecker(), $this->packageContext()));
    }

    public function testReportsEntriesWithoutExtensionAndWithOtherGroup(): void
    {
        $this->writePackage(
            '<file type="plugin" id="x" group="content">plg_content_x.zip</file>'
            . '<file type="module" id="mod_y" client="site">mod_y.zip</file>',
        );

        self::assertSame([
            'ERROR package/entries: pkg_x.xml: dist/plg_content_x.zip (type="plugin" id="x" group="content") does not '
            . 'match plugin/x.xml (group="system"); Joomla would not find the extension when uninstalling the package.',
            'ERROR package/entries: pkg_x.xml: No manifest in the subdirectories matches dist/mod_y.zip '
            . '(type="module" id="mod_y" client="site"), and the file does not exist.',
        ], $this->findings(new PackageChecker(), $this->packageContext()));
    }

    public function testReportsAPackageWithoutEntries(): void
    {
        $this->writePackage('');

        self::assertSame(
            ['ERROR package/entries: pkg_x.xml: lists no extensions in <files>', 'INFO package/unlisted-extensions: '
                . 'plugin/x.xml: plg_system_x is not part of the package'],
            $this->findings(new PackageChecker(), $this->packageContext()),
        );
    }

    public function testReportsAManifestNotNamedAfterTheElement(): void
    {
        $this->writePackage('<file type="plugin" id="x" group="system">plg_system_x.zip</file>', 'package.xml');

        self::assertSame(
            ['ERROR package/file-name: package.xml: Joomla reads administrator/manifests/packages/pkg_x.xml when '
                . 'uninstalling the package; rename the manifest to pkg_x.xml'],
            $this->findings(new PackageChecker(), $this->packageContext('package.xml')),
        );
    }

    public function testWarnsAboutAZipFileNamedAfterAnotherVersion(): void
    {
        $this->writePackage('<file type="plugin" id="x" group="system">plg_system_x-0.9.0.zip</file>');

        self::assertSame(
            ['WARN package/zip-names: pkg_x.xml: dist/plg_system_x-0.9.0.zip contains plg_system_x 1.0.0, whose ZIP '
                . 'file is named plg_system_x-1.0.0.zip.'],
            $this->findings(new PackageChecker(), $this->packageContext()),
        );
    }

    private function writePackage(string $entries, string $fileName = 'pkg_x.xml'): void
    {
        $this->writeFile($fileName, '<extension type="package"><name>pkg_x</name><packagename>x</packagename>'
            . '<version>1.0.0</version><files folder="dist">' . $entries . '</files></extension>');
    }

    private function packageContext(string $fileName = 'pkg_x.xml'): CheckContext
    {
        $manifestFile = $this->temporaryDirectory . '/' . $fileName;
        $manifest = (new ManifestParser())->parseFile($manifestFile);
        $factory = new CheckContextFactory($this->processRunner());

        return $factory->withPackage(
            $factory->create($this->temporaryDirectory, $manifestFile, $manifest, false),
            (new PackageResolver())->resolve($manifest, $this->temporaryDirectory),
        );
    }
}
