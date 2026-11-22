<?php

/**
 * @copyright  (C) 2026 Carsten Volkwein
 * @license    MIT License; see LICENSE
 */

declare(strict_types=1);

namespace SmartA\Jxt\Tests\Manifest;

use PHPUnit\Framework\TestCase;
use SmartA\Jxt\Deployment\EntryKind;
use SmartA\Jxt\Deployment\PlanEntry;
use SmartA\Jxt\Manifest\ClientType;
use SmartA\Jxt\Manifest\ExtensionType;
use SmartA\Jxt\Manifest\ManifestException;
use SmartA\Jxt\Manifest\ManifestParser;
use SmartA\Jxt\Manifest\PackageEntry;

/**
 * Tests for parsing package manifests (`type="package"`).
 */
final class PackageManifestParserTest extends TestCase
{
    private ManifestParser $parser;

    #[\Override]
    protected function setUp(): void
    {
        $this->parser = new ManifestParser();
    }

    public function testParsesPackageMetadata(): void
    {
        $manifest = $this->parser->parse(self::packageXml(), 'pkg_example.xml');

        self::assertSame(ExtensionType::Package, $manifest->type);
        self::assertSame(ClientType::Site, $manifest->client);
        self::assertSame('pkg_example', $manifest->element);
        self::assertSame('pkg_example', $manifest->name());
        self::assertSame('2.0.0', $manifest->version);
        self::assertSame('administrator/manifests/packages/pkg_example.xml', $manifest->installedManifest());
    }

    public function testPlanContainsManifestScriptAndLanguagesButNoChildren(): void
    {
        $plan = $this->parser->parse(self::packageXml(), 'pkg_example.xml')->plan;

        self::assertEquals([
            new PlanEntry('pkg_example.xml', 'administrator/manifests/packages/pkg_example.xml', EntryKind::File),
            new PlanEntry('script.php', 'administrator/manifests/packages/example/script.php', EntryKind::File),
            new PlanEntry('language/de-DE/pkg_example.sys.ini', 'language/de-DE/pkg_example.sys.ini', EntryKind::File),
        ], $plan->entries);
        self::assertSame(['administrator/manifests/packages/example'], $plan->exclusiveDirectories);
        self::assertSame(['language'], $plan->sourceDirectories);
    }

    public function testReadsTheEntriesOfTheFirstFilesSection(): void
    {
        $manifest = $this->parser->parse(self::packageXml(), 'pkg_example.xml');

        self::assertEquals([
            new PackageEntry('dist/com_example-2.0.0.zip', 'component', 'com_example', null, null),
            new PackageEntry('dist/plg_system_example.zip', 'plugin', 'example', null, 'system'),
            new PackageEntry('dist/mod_example', 'module', 'mod_example', 'site', null),
            new PackageEntry('dist/other.zip', null, null, null, null),
        ], $manifest->packageEntries);
    }

    public function testOtherTypesHaveNoPackageEntries(): void
    {
        $xml = '<extension type="module"><name>mod_x</name><files><filename module="mod_x">mod_x.php</filename></files></extension>';

        self::assertSame([], $this->parser->parse($xml, 'mod_x.xml')->packageEntries);
    }

    public function testRequiresPackageName(): void
    {
        $this->expectException(ManifestException::class);
        $this->expectExceptionMessage('<packagename>');

        $this->parser->parse('<extension type="package"><name>pkg_x</name></extension>', 'pkg_x.xml');
    }

    public function testRejectsUnsafePackageName(): void
    {
        $this->expectException(ManifestException::class);
        $this->expectExceptionMessage('Invalid package name "../x"');

        $this->parser->parse('<extension type="package"><name>x</name><packagename>../x</packagename></extension>', 'pkg_x.xml');
    }

    private static function packageXml(): string
    {
        return <<<'XML'
            <extension type="package" method="upgrade">
                <name>pkg_example</name>
                <packagename>example</packagename>
                <version>2.0.0</version>
                <scriptfile>script.php</scriptfile>
                <languages folder="language">
                    <language tag="de-DE">de-DE/pkg_example.sys.ini</language>
                </languages>
                <files folder="dist">
                    <file type="component" id="com_example">com_example-2.0.0.zip</file>
                    <file type="plugin" id="example" group="system">plg_system_example.zip</file>
                    <folder type="module" id="mod_example" client="site">mod_example</folder>
                    <file>other.zip</file>
                </files>
                <files folder="ignored">
                    <file type="module" id="mod_ignored">mod_ignored.zip</file>
                </files>
            </extension>
            XML;
    }
}
