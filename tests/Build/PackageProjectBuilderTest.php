<?php

/**
 * @copyright  (C) 2026 Carsten Volkwein
 * @license    MIT License; see LICENSE
 */

declare(strict_types=1);

namespace SmartA\Jxt\Tests\Build;

use PHPUnit\Framework\TestCase;
use SmartA\Jxt\Build\BuildException;
use SmartA\Jxt\Build\ProjectBuild;
use SmartA\Jxt\Build\ProjectBuilder;
use SmartA\Jxt\Configuration\ConfigurationLoader;
use SmartA\Jxt\Tests\GitTrait;
use SmartA\Jxt\Tests\TemporaryDirectoryTrait;
use Symfony\Component\Console\Output\BufferedOutput;
use ZipArchive;

/**
 * Tests for building a package: the ZIP files of its extensions from the subdirectories and the package ZIP.
 */
final class PackageProjectBuilderTest extends TestCase
{
    use GitTrait;
    use TemporaryDirectoryTrait;

    private const string COMPONENT_ENTRY = '<file type="component" id="com_x">com_x-1.0.0.zip</file>';
    private const string PLUGIN_ENTRY = '<file type="plugin" id="x" group="system">plg_system_x-1.0.0.zip</file>';

    private string $project;

    private string $dist;

    #[\Override]
    protected function setUp(): void
    {
        $this->createTemporaryDirectory();
        $this->project = $this->temporaryDirectory . '/project';
        $this->dist = $this->project . '/dist';
        $this->writeFile('project/script.php', '<?php');
        $this->writeFile('project/language/en-GB/pkg_x.sys.ini', 'PKG_X="X"');
        $this->writeFile('project/component/x.xml', self::componentManifest());
        $this->writeFile('project/component/admin/services/provider.php', '<?php');
        $this->writeFile('project/plugin/x.xml', self::pluginManifest('system'));
        $this->writeFile('project/plugin/src/Extension/X.php', '<?php');
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->removeTemporaryDirectory();
    }

    public function testBuildsTheExtensionsAndPacksThemWithTheFilesOfThePackage(): void
    {
        $this->writePackageManifest(self::COMPONENT_ENTRY . self::PLUGIN_ENTRY);

        $build = $this->build();

        self::assertSame($this->dist . '/pkg_x-1.0.0.zip', $build->package->path);
        self::assertSame(5, $build->package->fileCount);
        self::assertSame([
            'dist/com_x-1.0.0.zip',
            'dist/plg_system_x-1.0.0.zip',
            'language/en-GB/pkg_x.sys.ini',
            'pkg_x.xml',
            'script.php',
        ], $this->zipEntries($build->package->path));
        self::assertSame(['pkg_x-1.0.0.zip'], $this->directoryEntries($this->dist));
        self::assertSame(['com_x', 'plg_system_x'], array_map(
            static fn (ProjectBuild $child): string => $child->manifest->name(),
            $build->children,
        ));
        self::assertSame([], $build->warnings);
    }

    public function testThePackagedZipFilesContainTheFilesOfTheExtensions(): void
    {
        $this->writePackageManifest(self::COMPONENT_ENTRY . self::PLUGIN_ENTRY);

        $nested = $this->temporaryDirectory . '/nested.zip';
        file_put_contents($nested, $this->zipContent($this->build()->package->path, 'dist/plg_system_x-1.0.0.zip'));

        self::assertSame(['src/Extension/X.php', 'x.xml'], $this->zipEntries($nested));
    }

    public function testPacksTheUnpackedFilesForAnEntryThatIsNoZipFile(): void
    {
        $this->writePackageManifest('<folder type="plugin" id="x" group="system">plg_system_x</folder>');

        self::assertSame([
            'dist/plg_system_x/src/Extension/X.php',
            'dist/plg_system_x/x.xml',
            'language/en-GB/pkg_x.sys.ini',
            'pkg_x.xml',
            'script.php',
        ], $this->zipEntries($this->build()->package->path));
    }

    public function testTakesAnExistingFileOrDirectoryWithoutManifestAsItIs(): void
    {
        $this->writePackageManifest(
            '<file type="library" id="acme">lib_acme.zip</file><folder type="file" id="x">files_x</folder>',
            'vendor-packages',
        );
        $this->writeFile('project/vendor-packages/lib_acme.zip', 'zip');
        $this->writeFile('project/vendor-packages/files_x/x.xml', '<extension type="file"/>');

        $build = $this->build();

        self::assertSame([
            'language/en-GB/pkg_x.sys.ini',
            'pkg_x.xml',
            'script.php',
            'vendor-packages/files_x/x.xml',
            'vendor-packages/lib_acme.zip',
        ], $this->zipEntries($build->package->path));
        self::assertSame('zip', $this->zipContent($build->package->path, 'vendor-packages/lib_acme.zip'));
        self::assertSame([], $build->children);
    }

    public function testBuildsAnExtensionInsteadOfTakingAnOutdatedZipFile(): void
    {
        $this->writePackageManifest(self::PLUGIN_ENTRY);
        $this->writeFile('project/dist/plg_system_x-1.0.0.zip', 'outdated');

        $build = $this->build();

        self::assertNotSame('outdated', $this->zipContent($build->package->path, 'dist/plg_system_x-1.0.0.zip'));
        self::assertCount(1, $build->children);
    }

    public function testFailsForAnEntryWithoutManifestOrFile(): void
    {
        $this->writePackageManifest('<file type="module" id="mod_x" client="site">mod_x.zip</file>');
        $this->writeFile('project/broken/x.xml', '<extension type="module"><name>x</name></extension>');

        $this->expectException(BuildException::class);
        $this->expectExceptionMessageMatches(
            '#^No manifest in the subdirectories matches dist/mod_x\.zip \(type="module" id="mod_x" client="site"\), '
            . 'and the file does not exist\.\nManifests that could not be read:\nbroken/x\.xml: .*module element#',
        );

        $this->build();
    }

    public function testFailsIfOnlyGroupOrClientDiffer(): void
    {
        $this->writePackageManifest('<file type="plugin" id="x" group="content">plg_content_x.zip</file>');

        $this->expectException(BuildException::class);
        $this->expectExceptionMessage(
            'dist/plg_content_x.zip (type="plugin" id="x" group="content") does not match plugin/x.xml '
            . '(group="system"); Joomla would not find the extension when uninstalling the package.',
        );

        $this->build();
    }

    public function testFailsIfTwoManifestsMatchAnEntry(): void
    {
        $this->writePackageManifest(self::PLUGIN_ENTRY);
        $this->writeFile('project/build/plugin/x.xml', self::pluginManifest('system'));

        $this->expectException(BuildException::class);
        $this->expectExceptionMessage(
            'Several manifests match dist/plg_system_x-1.0.0.zip (type="plugin" id="x" group="system"): '
            . 'build/plugin/x.xml, plugin/x.xml.',
        );

        $this->build();
    }

    public function testFailsForAPackageWithoutExtensions(): void
    {
        $this->writePackageManifest('');

        $this->expectException(BuildException::class);
        $this->expectExceptionMessage('The package manifest lists no extensions in <files>.');

        $this->build();
    }

    public function testWarnsAboutAZipFileNamedAfterAnotherVersion(): void
    {
        $this->writePackageManifest(
            '<file type="component" id="com_x">com_x-0.9.0.zip</file><file type="plugin" id="x" group="system">plg_system_x.zip</file>',
        );

        $build = $this->build();

        self::assertSame(
            ['dist/com_x-0.9.0.zip contains com_x 1.0.0, whose ZIP file is named com_x-1.0.0.zip.'],
            $build->warnings,
        );
        self::assertContains('dist/com_x-0.9.0.zip', $this->zipEntries($build->package->path));
    }

    public function testReportsUnlistedFilesOfTheExtensionsWithTheirDirectory(): void
    {
        $this->writePackageManifest(self::COMPONENT_ENTRY);
        $this->writeFile('project/component/admin/Unlisted.php', '<?php');
        $this->writeFile('project/language/de-DE/pkg_x.sys.ini', 'PKG_X="X"');

        self::assertSame(['language/de-DE/', 'component/admin/Unlisted.php'], $this->build()->unlisted);
    }

    public function testBuildsAPackageFromATag(): void
    {
        $this->writePackageManifest(self::PLUGIN_ENTRY);
        $this->initGit();
        $this->git('tag', 'v1.0.0');
        $this->writeFile('project/plugin/src/Extension/X.php', 'changed');

        $build = $this->builder()->buildTag($this->project, 'v1.0.0', $this->dist, new BufferedOutput());

        $nested = $this->temporaryDirectory . '/nested.zip';
        file_put_contents($nested, $this->zipContent($build->package->path, 'dist/plg_system_x-1.0.0.zip'));
        self::assertSame('v1.0.0', $build->tag);
        self::assertSame('<?php', $this->zipContent($nested, 'src/Extension/X.php'));
        self::assertCount(1, $build->children);
    }

    private function build(): ProjectBuild
    {
        return $this->builder()->build($this->project, $this->dist, new BufferedOutput());
    }

    private function builder(): ProjectBuilder
    {
        return new ProjectBuilder(new ConfigurationLoader(null));
    }

    private function writePackageManifest(string $entries, string $folder = 'dist'): void
    {
        $this->writeFile('project/pkg_x.xml', <<<XML
            <extension type="package" method="upgrade">
                <name>pkg_x</name>
                <packagename>x</packagename>
                <version>1.0.0</version>
                <scriptfile>script.php</scriptfile>
                <languages folder="language"><language tag="en-GB">en-GB/pkg_x.sys.ini</language></languages>
                <files folder="{$folder}">{$entries}</files>
            </extension>
            XML);
    }

    private static function componentManifest(): string
    {
        return <<<'XML'
            <extension type="component"><name>com_x</name><version>1.0.0</version>
                <administration><files folder="admin"><folder>services</folder></files></administration>
            </extension>
            XML;
    }

    private static function pluginManifest(string $group): string
    {
        return <<<XML
            <extension type="plugin" group="{$group}"><name>plg_{$group}_x</name><version>1.0.0</version>
                <files><folder plugin="x">src</folder></files>
            </extension>
            XML;
    }

    /**
     * @return list<string>
     */
    private function directoryEntries(string $directory): array
    {
        return array_values(array_diff(scandir($directory) ?: [], ['.', '..']));
    }

    /**
     * @return list<string>
     */
    private function zipEntries(string $path): array
    {
        $zip = new ZipArchive();
        $zip->open($path);
        $entries = [];

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $entries[] = (string) $zip->getNameIndex($index);
        }

        $zip->close();
        sort($entries);

        return $entries;
    }

    private function zipContent(string $path, string $entry): string
    {
        $zip = new ZipArchive();
        $zip->open($path);
        $content = (string) $zip->getFromName($entry);
        $zip->close();

        return $content;
    }
}
