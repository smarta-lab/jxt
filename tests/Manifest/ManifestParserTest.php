<?php

/**
 * @copyright  (C) 2026 Carsten Volkwein
 * @license    MIT License; see LICENSE
 */

declare(strict_types=1);

namespace SmartA\Jxt\Tests\Manifest;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SmartA\Jxt\Deployment\DeploymentPlan;
use SmartA\Jxt\Deployment\EntryKind;
use SmartA\Jxt\Deployment\PlanEntry;
use SmartA\Jxt\Manifest\ClientType;
use SmartA\Jxt\Manifest\ExtensionType;
use SmartA\Jxt\Manifest\ManifestException;
use SmartA\Jxt\Manifest\ManifestParser;

/**
 * Tests for the ManifestParser and the deployment plan it creates.
 */
final class ManifestParserTest extends TestCase
{
    private ManifestParser $parser;

    #[\Override]
    protected function setUp(): void
    {
        $this->parser = new ManifestParser();
    }

    public function testParsesSiteModuleMetadata(): void
    {
        $manifest = $this->parser->parse($this->siteModuleXml(), 'mod_example.xml');

        self::assertSame(ExtensionType::Module, $manifest->type);
        self::assertSame(ClientType::Site, $manifest->client);
        self::assertSame('mod_example', $manifest->element);
        self::assertSame('1.2.3', $manifest->version);
        self::assertSame('modules/mod_example/mod_example.xml', $manifest->installedManifest());
    }

    public function testCollectsSourceDirectoriesOfSections(): void
    {
        $xml = <<<'XML'
            <extension type="module">
                <name>mod_x</name>
                <files><folder module="mod_x">tmpl</folder></files>
                <languages><language tag="de-DE">language/de-DE/mod_x.ini</language></languages>
                <media destination="mod_x" folder="media"><folder>css</folder></media>
            </extension>
            XML;

        self::assertSame(['language', 'media'], $this->parser->parse($xml, 'mod_x.xml')->plan->sourceDirectories);
    }

    public function testCollectsFolderAttributesAsSourceDirectories(): void
    {
        $xml = <<<'XML'
            <extension type="module">
                <name>mod_x</name>
                <files folder="site"><folder module="mod_x">tmpl</folder></files>
                <languages folder="lang"><language tag="de-DE">de-DE/mod_x.ini</language></languages>
                <languages><language tag="en-GB">mod_x.ini</language></languages>
                <media destination="mod_x"><folder>css</folder></media>
            </extension>
            XML;

        self::assertSame(['site', 'lang'], $this->parser->parse($xml, 'mod_x.xml')->plan->sourceDirectories);
    }

    public function testParsesInstallerMetadata(): void
    {
        $xml = <<<'XML'
            <extension type="module">
                <name>mod_db</name>
                <namespace path="src">SmartA\Module\Db</namespace>
                <scriptfile>script.php</scriptfile>
                <install>
                    <sql><file driver="mysql" charset="utf8">sql/install.mysql.sql</file></sql>
                </install>
                <update><schemas><schemapath type="mysql">sql/updates/mysql</schemapath></schemas></update>
                <updateservers>
                    <server type="extension">https://example.org/update.xml</server>
                </updateservers>
            </extension>
            XML;

        $installer = $this->parser->parse($xml, 'mod_db.xml')->installer;

        self::assertSame('SmartA\Module\Db', $installer->namespace);
        self::assertSame('src', $installer->namespacePath);
        self::assertSame(['scriptfile', 'install', 'update', 'updateservers'], array_keys($installer->sections));
        self::assertSame(
            '<updateservers><server type="extension">https://example.org/update.xml</server></updateservers>',
            $installer->sections['updateservers'],
        );
        self::assertSame(
            ['modules/mod_db/script.php', 'modules/mod_db/sql/install.mysql.sql', 'modules/mod_db/sql/updates/mysql'],
            $installer->targets,
        );
    }

    public function testInstallerMetadataIsEmptyForPlainModule(): void
    {
        $installer = $this->parser->parse('<extension type="module"><name>mod_x</name></extension>', 'mod_x.xml')->installer;

        self::assertNull($installer->namespace);
        self::assertNull($installer->namespacePath);
        self::assertSame([], $installer->sections);
        self::assertSame([], $installer->targets);
        self::assertFalse($installer->upgrade);
    }

    public function testDetectsUpgradeMethod(): void
    {
        $xml = '<extension type="module" method="upgrade"><name>mod_x</name></extension>';

        self::assertTrue($this->parser->parse($xml, 'mod_x.xml')->installer->upgrade);
    }

    public function testBuildsPlanForSiteModule(): void
    {
        $plan = $this->parser->parse($this->siteModuleXml(), 'mod_example.xml')->plan;

        self::assertEquals([
            new PlanEntry('mod_example.xml', 'modules/mod_example/mod_example.xml', EntryKind::File),
            new PlanEntry('services', 'modules/mod_example/services', EntryKind::Directory),
            new PlanEntry('tmpl', 'modules/mod_example/tmpl', EntryKind::Directory),
            new PlanEntry('src', 'modules/mod_example/src', EntryKind::Directory),
            new PlanEntry('language/de-DE/mod_example.ini', 'language/de-DE/mod_example.ini', EntryKind::File),
            new PlanEntry('language/de-DE/mod_example.sys.ini', 'language/de-DE/mod_example.sys.ini', EntryKind::File),
            new PlanEntry('media/joomla.asset.json', 'media/mod_example/joomla.asset.json', EntryKind::File),
            new PlanEntry('media/css', 'media/mod_example/css', EntryKind::Directory),
        ], $plan->entries);
        self::assertSame(['modules/mod_example', 'media/mod_example'], $plan->exclusiveDirectories);
    }

    public function testInstallsAdministratorModuleBelowAdministrator(): void
    {
        $xml = <<<'XML'
            <extension type="module" client="administrator">
                <name>mod_admin</name>
                <files>
                    <filename module="mod_admin">mod_admin.php</filename>
                </files>
                <languages>
                    <language tag="en-GB">language/en-GB/mod_admin.ini</language>
                </languages>
            </extension>
            XML;

        $manifest = $this->parser->parse($xml, 'mod_admin.xml');

        self::assertSame(ClientType::Administrator, $manifest->client);
        self::assertEquals([
            new PlanEntry('mod_admin.xml', 'administrator/modules/mod_admin/mod_admin.xml', EntryKind::File),
            new PlanEntry('mod_admin.php', 'administrator/modules/mod_admin/mod_admin.php', EntryKind::File),
            new PlanEntry(
                'language/en-GB/mod_admin.ini',
                'administrator/language/en-GB/mod_admin.ini',
                EntryKind::File,
            ),
        ], $manifest->plan->entries);
        self::assertSame(['administrator/modules/mod_admin'], $manifest->plan->exclusiveDirectories);
    }

    public function testModuleElementFallsBackToName(): void
    {
        $xml = <<<'XML'
            <extension type="module">
                <name>MOD_Fallback</name>
                <files><folder>tmpl</folder></files>
            </extension>
            XML;

        $manifest = $this->parser->parse($xml, 'mod_fallback.xml');

        self::assertSame('mod_fallback', $manifest->element);
        self::assertSame(ClientType::Site, $manifest->client);
        self::assertNull($manifest->version);
    }

    public function testBuildsPlanForTemplate(): void
    {
        $xml = <<<'XML'
            <extension type="template" client="site" method="upgrade">
                <name>Mini Grid</name>
                <version>1.0.0</version>
                <files>
                    <filename>index.php</filename>
                    <filename>templateDetails.xml</filename>
                    <folder>html</folder>
                </files>
                <languages folder="language">
                    <language tag="de-DE">de-DE/tpl_mini_grid.ini</language>
                </languages>
                <media destination="templates/site/mini_grid" folder="media">
                    <folder>css</folder>
                </media>
            </extension>
            XML;

        $manifest = $this->parser->parse($xml, 'templateDetails.xml');

        self::assertSame(ExtensionType::Template, $manifest->type);
        self::assertSame('mini_grid', $manifest->element);
        self::assertEquals([
            new PlanEntry('templateDetails.xml', 'templates/mini_grid/templateDetails.xml', EntryKind::File),
            new PlanEntry('index.php', 'templates/mini_grid/index.php', EntryKind::File),
            new PlanEntry('html', 'templates/mini_grid/html', EntryKind::Directory),
            new PlanEntry('language/de-DE/tpl_mini_grid.ini', 'language/de-DE/tpl_mini_grid.ini', EntryKind::File),
            new PlanEntry('media/css', 'media/templates/site/mini_grid/css', EntryKind::Directory),
        ], $manifest->plan->entries);
        self::assertSame(
            ['templates/mini_grid', 'media/templates/site/mini_grid'],
            $manifest->plan->exclusiveDirectories,
        );
    }

    public function testBuildsPlanForPlugin(): void
    {
        $xml = <<<'XML'
            <extension type="plugin" group="system" method="upgrade">
                <name>plg_system_example</name>
                <version>1.0.0</version>
                <files>
                    <folder plugin="example">services</folder>
                    <folder>src</folder>
                </files>
                <languages>
                    <language tag="en-GB">language/en-GB/plg_system_example.ini</language>
                </languages>
                <media destination="plg_system_example" folder="media">
                    <folder>js</folder>
                </media>
            </extension>
            XML;

        $manifest = $this->parser->parse($xml, 'example.xml');

        self::assertSame(ExtensionType::Plugin, $manifest->type);
        self::assertSame('example', $manifest->element);
        self::assertSame('system', $manifest->group);
        self::assertSame('plugins/system/example/example.xml', $manifest->installedManifest());
        self::assertEquals([
            new PlanEntry('example.xml', 'plugins/system/example/example.xml', EntryKind::File),
            new PlanEntry('services', 'plugins/system/example/services', EntryKind::Directory),
            new PlanEntry('src', 'plugins/system/example/src', EntryKind::Directory),
            new PlanEntry(
                'language/en-GB/plg_system_example.ini',
                'administrator/language/en-GB/plg_system_example.ini',
                EntryKind::File,
            ),
            new PlanEntry('media/js', 'media/plg_system_example/js', EntryKind::Directory),
        ], $manifest->plan->entries);
        self::assertSame(
            ['plugins/system/example', 'media/plg_system_example'],
            $manifest->plan->exclusiveDirectories,
        );
    }

    public function testPluginIgnoresClientAttribute(): void
    {
        $xml = <<<'XML'
            <extension type="plugin" group="content" client="administrator">
                <name>Example</name>
                <files><filename plugin="example">example.php</filename></files>
            </extension>
            XML;

        $manifest = $this->parser->parse($xml, 'example.xml');

        self::assertSame(ClientType::Site, $manifest->client);
        self::assertSame('plugins/content/example/example.php', $manifest->plan->entries[1]->target);
    }

    public function testPluginElementKeepsCaseAsTheJoomlaInstallerDoes(): void
    {
        $xml = <<<'XML'
            <extension type="plugin" group="editors-xtd">
                <name>Example</name>
                <files><filename plugin="MyButton">MyButton.php</filename></files>
            </extension>
            XML;

        $manifest = $this->parser->parse($xml, 'MyButton.xml');

        self::assertSame('MyButton', $manifest->element);
        self::assertSame('plugins/editors-xtd/MyButton/MyButton.xml', $manifest->installedManifest());
        self::assertSame('plg_editors-xtd_mybutton', $manifest->name());
    }

    /**
     * @param non-empty-string $xml
     */
    #[DataProvider('extensionNames')]
    public function testReturnsExtensionName(string $xml, string $fileName, string $expectedName): void
    {
        self::assertSame($expectedName, $this->parser->parse($xml, $fileName)->name());
    }

    /**
     * @return iterable<string, array{non-empty-string, string, string}>
     */
    public static function extensionNames(): iterable
    {
        yield 'module' => ['<extension type="module"><name>mod_x</name></extension>', 'mod_x.xml', 'mod_x'];
        yield 'template' => [
            '<extension type="template"><name>Mini Grid</name></extension>',
            'templateDetails.xml',
            'tpl_mini_grid',
        ];
        yield 'plugin' => [
            '<extension type="plugin" group="System"><name>x</name><files><filename plugin="x">x.php</filename></files></extension>',
            'x.xml',
            'plg_system_x',
        ];
    }

    public function testRespectsFolderAttributeOfFilesAndIncludesScriptfile(): void
    {
        $xml = <<<'XML'
            <extension type="module">
                <name>mod_nested</name>
                <scriptfile>script.php</scriptfile>
                <files folder="site">
                    <folder module="mod_nested">services</folder>
                </files>
            </extension>
            XML;

        $plan = $this->parser->parse($xml, 'mod_nested.xml')->plan;

        self::assertEquals([
            new PlanEntry('mod_nested.xml', 'modules/mod_nested/mod_nested.xml', EntryKind::File),
            new PlanEntry('site/services', 'modules/mod_nested/services', EntryKind::Directory),
            new PlanEntry('script.php', 'modules/mod_nested/script.php', EntryKind::File),
        ], $plan->entries);
    }

    public function testNormalizesPaths(): void
    {
        $xml = <<<'XML'
            <extension type="module">
                <name>mod_paths</name>
                <files folder="./site/">
                    <folder module="mod_paths"> tmpl/ </folder>
                    <filename>sub\helper.php</filename>
                </files>
            </extension>
            XML;

        $plan = $this->parser->parse($xml, 'mod_paths.xml')->plan;

        self::assertEquals([
            new PlanEntry('mod_paths.xml', 'modules/mod_paths/mod_paths.xml', EntryKind::File),
            new PlanEntry('site/tmpl', 'modules/mod_paths/tmpl', EntryKind::Directory),
            new PlanEntry('site/sub/helper.php', 'modules/mod_paths/sub/helper.php', EntryKind::File),
        ], $plan->entries);
    }

    public function testPlanWithoutMediaHasOnlyInstallDirectoryAsExclusive(): void
    {
        $xml = '<extension type="module"><name>mod_plain</name><files><folder>tmpl</folder></files></extension>';

        $plan = $this->parser->parse($xml, 'mod_plain.xml')->plan;

        self::assertInstanceOf(DeploymentPlan::class, $plan);
        self::assertSame(['modules/mod_plain'], $plan->exclusiveDirectories);
    }

    public function testParseFileReadsManifestFromDisk(): void
    {
        $manifest = $this->parser->parseFile(__DIR__ . '/../fixtures/mod_fixture/mod_fixture.xml');

        self::assertSame('mod_fixture', $manifest->element);
        self::assertSame('mod_fixture.xml', $manifest->plan->entries[0]->source);
    }

    public function testParseFileFailsForMissingFile(): void
    {
        $this->expectException(ManifestException::class);

        $this->parser->parseFile(__DIR__ . '/does-not-exist.xml');
    }

    /**
     * @param non-empty-string $expectedMessage Part of the expected error message
     */
    #[DataProvider('invalidManifests')]
    public function testRejectsInvalidManifest(string $xml, string $expectedMessage): void
    {
        $this->expectException(ManifestException::class);
        $this->expectExceptionMessageIsOrContains($expectedMessage);

        $this->parser->parse($xml, 'mod_invalid.xml');
    }

    /**
     * @return iterable<string, array{string, non-empty-string}>
     */
    public static function invalidManifests(): iterable
    {
        yield 'no XML' => ['not xml', 'not valid XML'];
        yield 'wrong root element' => ['<phpunit/>', '<extension>'];
        yield 'unsupported type' => [
            '<extension type="component"><name>com_x</name></extension>',
            'component',
        ];
        yield 'plugin without group' => [
            '<extension type="plugin"><name>x</name><files><filename plugin="x">x.php</filename></files></extension>',
            'group',
        ];
        yield 'invalid plugin group' => [
            '<extension type="plugin" group="../system"><name>x</name>'
                . '<files><filename plugin="x">x.php</filename></files></extension>',
            '../system',
        ];
        yield 'plugin without element' => [
            '<extension type="plugin" group="system"><name>plg_system_x</name>'
                . '<files><filename>x.php</filename></files></extension>',
            'attribute plugin',
        ];
        yield 'unknown client' => [
            '<extension type="module" client="api"><name>mod_x</name></extension>',
            'api',
        ];
        yield 'empty name' => ['<extension type="module"><name> </name></extension>', 'extension name'];
        yield 'invalid element name' => [
            '<extension type="module"><name>../mod_x</name></extension>',
            '../mod_x',
        ];
        yield 'path with ..' => [
            '<extension type="module"><name>mod_x</name><files><folder>../secret</folder></files></extension>',
            '../secret',
        ];
        yield 'absolute path' => [
            '<extension type="module"><name>mod_x</name><files><filename>/etc/passwd</filename></files></extension>',
            '/etc/passwd',
        ];
        yield 'empty path' => [
            '<extension type="module"><name>mod_x</name><files><folder> </folder></files></extension>',
            'Empty path',
        ];
        yield 'media without destination' => [
            '<extension type="module"><name>mod_x</name><media folder="media"><folder>css</folder></media></extension>',
            'destination',
        ];
        yield 'invalid language tag' => [
            '<extension type="module"><name>mod_x</name><languages>'
                . '<language tag="../x">language/x.ini</language></languages></extension>',
            '../x',
        ];
    }

    /**
     * Returns a typical site module manifest, structured like the existing modules.
     */
    private function siteModuleXml(): string
    {
        return <<<'XML'
            <?xml version="1.0" encoding="utf-8"?>
            <extension type="module" version="4.0" client="site" method="upgrade">
                <name>mod_example</name>
                <version>1.2.3</version>
                <namespace path="src">SmartA\Module\Example</namespace>
                <files>
                    <folder module="mod_example">services</folder>
                    <folder>tmpl</folder>
                    <folder>src</folder>
                </files>
                <languages>
                    <language tag="de-DE">language/de-DE/mod_example.ini</language>
                    <language tag="de-DE">language/de-DE/mod_example.sys.ini</language>
                </languages>
                <media destination="mod_example" folder="media">
                    <filename>joomla.asset.json</filename>
                    <folder>css</folder>
                </media>
                <config>
                    <fields name="params"/>
                </config>
            </extension>
            XML;
    }
}
