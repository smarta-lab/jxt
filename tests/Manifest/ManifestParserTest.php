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
                <element>mod_db</element>
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
        $installer = $this->parser->parse('<extension type="module"><name>mod_x</name><element>mod_x</element></extension>', 'mod_x.xml')->installer;

        self::assertNull($installer->namespace);
        self::assertNull($installer->namespacePath);
        self::assertSame([], $installer->sections);
        self::assertSame([], $installer->targets);
        self::assertFalse($installer->upgrade);
    }

    public function testDetectsUpgradeMethod(): void
    {
        $xml = '<extension type="module" method="upgrade"><name>mod_x</name><element>mod_x</element></extension>';

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

    public function testModuleElementPrefersElementTagAndKeepsItsCase(): void
    {
        $xml = <<<'XML'
            <extension type="module">
                <name>Example</name>
                <element>mod_Custom</element>
                <files><folder module="mod_other">tmpl</folder></files>
            </extension>
            XML;

        $manifest = $this->parser->parse($xml, 'mod_custom.xml');

        self::assertSame('mod_Custom', $manifest->element);
        self::assertSame(ClientType::Site, $manifest->client);
        self::assertNull($manifest->version);
    }

    public function testModuleAttributeIsWrittenInLowerCase(): void
    {
        $xml = '<extension type="module"><name>x</name><files><folder module="MOD_Upper">tmpl</folder></files></extension>';

        self::assertSame('mod_upper', $this->parser->parse($xml, 'mod_upper.xml')->element);
    }

    /**
     * @param non-empty-string $xml
     */
    #[DataProvider('templateElements')]
    public function testTemplateElementFollowsTheJoomlaInstaller(string $xml, string $expectedElement): void
    {
        self::assertSame($expectedElement, $this->parser->parse($xml, 'templateDetails.xml')->element);
    }

    /**
     * @return iterable<string, array{non-empty-string, string}>
     */
    public static function templateElements(): iterable
    {
        yield 'name in lower case without spaces' => [
            '<extension type="template"><name>Mini Grid</name></extension>',
            'minigrid',
        ];
        yield 'characters outside the cmd filter removed' => [
            '<extension type="template"><name>.Grün_Tpl-2.0</name></extension>',
            'grn_tpl-2.0',
        ];
        yield 'element tag preferred' => [
            '<extension type="template"><name>YOOtheme Pro</name><element>YOOtheme</element></extension>',
            'yootheme',
        ];
    }

    public function testTreatsEveryChildExceptFolderAsFile(): void
    {
        $xml = <<<'XML'
            <extension type="plugin" group="sampledata">
                <name>plg_sampledata_x</name>
                <files>
                    <folder plugin="x">services</folder>
                    <file>script.php</file>
                    <filename>x.php</filename>
                    <!-- comments are no entries -->
                </files>
                <media destination="plg_sampledata_x" folder="media">
                    <file>joomla.asset.json</file>
                </media>
            </extension>
            XML;

        self::assertEquals([
            new PlanEntry('x.xml', 'plugins/sampledata/x/x.xml', EntryKind::File),
            new PlanEntry('services', 'plugins/sampledata/x/services', EntryKind::Directory),
            new PlanEntry('script.php', 'plugins/sampledata/x/script.php', EntryKind::File),
            new PlanEntry('x.php', 'plugins/sampledata/x/x.php', EntryKind::File),
            new PlanEntry('media/joomla.asset.json', 'media/plg_sampledata_x/joomla.asset.json', EntryKind::File),
        ], $this->parser->parse($xml, 'x.xml')->plan->entries);
    }

    public function testClientAttributeOfLanguageFileOverridesTheDefault(): void
    {
        $xml = <<<'XML'
            <extension type="plugin" group="system">
                <name>plg_system_x</name>
                <files><filename plugin="x">x.php</filename></files>
                <languages folder="language">
                    <language tag="en-GB">en-GB/plg_system_x.ini</language>
                    <language tag="en-GB" client="site">en-GB/plg_system_x.site.ini</language>
                    <language tag="en-GB" client="administrator">en-GB/plg_system_x.sys.ini</language>
                </languages>
            </extension>
            XML;

        $targets = array_map(
            static fn (PlanEntry $entry): string => $entry->target,
            array_slice($this->parser->parse($xml, 'x.xml')->plan->entries, 2),
        );

        self::assertSame([
            'administrator/language/en-GB/plg_system_x.ini',
            'language/en-GB/plg_system_x.site.ini',
            'administrator/language/en-GB/plg_system_x.sys.ini',
        ], $targets);
    }

    public function testReadsNameAndInheritanceOfTemplates(): void
    {
        $child = $this->parser->parse(
            '<extension type="template" client="site"><name>Cards</name><version>1.0.0</version>'
                . '<inheritable>0</inheritable><parent> cassiopeia </parent><files><filename>a.php</filename></files></extension>',
            'templateDetails.xml',
        );
        $parent = $this->parser->parse(
            '<extension type="template" client="site"><name>base</name><version>1.0.0</version>'
                . '<inheritable>1</inheritable><files><filename>a.php</filename></files></extension>',
            'templateDetails.xml',
        );

        self::assertSame('Cards', $child->title);
        self::assertFalse($child->inheritable);
        self::assertSame('cassiopeia', $child->parent);
        self::assertTrue($parent->inheritable);
        self::assertNull($parent->parent);
    }

    public function testBuildsPlanForTemplate(): void
    {
        $xml = <<<'XML'
            <extension type="template" client="site" method="upgrade">
                <name>Mini_Grid</name>
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
        yield 'module' => ['<extension type="module"><name>mod_x</name><element>mod_x</element></extension>', 'mod_x.xml', 'mod_x'];
        yield 'template' => [
            '<extension type="template"><name>Mini Grid</name></extension>',
            'templateDetails.xml',
            'tpl_minigrid',
        ];
        yield 'plugin' => [
            '<extension type="plugin" group="System"><name>x</name><files><filename plugin="x">x.php</filename></files></extension>',
            'x.xml',
            'plg_system_x',
        ];
        yield 'component' => ['<extension type="component"><name>Example</name></extension>', 'example.xml', 'com_example'];
    }

    public function testBuildsPlanForComponent(): void
    {
        $manifest = $this->parser->parse($this->componentXml(), 'example.xml');

        self::assertSame(ExtensionType::Component, $manifest->type);
        self::assertSame(ClientType::Administrator, $manifest->client);
        self::assertSame('com_example', $manifest->element);
        self::assertSame('administrator/components/com_example/example.xml', $manifest->installedManifest());
        self::assertEquals([
            new PlanEntry('example.xml', 'administrator/components/com_example/example.xml', EntryKind::File),
            new PlanEntry('site/src', 'components/com_example/src', EntryKind::Directory),
            new PlanEntry('site/tmpl', 'components/com_example/tmpl', EntryKind::Directory),
            new PlanEntry('admin/services', 'administrator/components/com_example/services', EntryKind::Directory),
            new PlanEntry('admin/sql', 'administrator/components/com_example/sql', EntryKind::Directory),
            new PlanEntry('admin/access.xml', 'administrator/components/com_example/access.xml', EntryKind::File),
            new PlanEntry('api/src', 'api/components/com_example/src', EntryKind::Directory),
            new PlanEntry('script.php', 'administrator/components/com_example/script.php', EntryKind::File),
            new PlanEntry('site/language/en-GB/com_example.ini', 'language/en-GB/com_example.ini', EntryKind::File),
            new PlanEntry(
                'admin/language/en-GB/com_example.ini',
                'administrator/language/en-GB/com_example.ini',
                EntryKind::File,
            ),
            new PlanEntry(
                'admin/language/en-GB/com_example.sys.ini',
                'language/en-GB/com_example.sys.ini',
                EntryKind::File,
            ),
            new PlanEntry('media/js', 'media/com_example/js', EntryKind::Directory),
        ], $manifest->plan->entries);
        self::assertSame([
            'administrator/components/com_example',
            'components/com_example',
            'api/components/com_example',
            'media/com_example',
        ], $manifest->plan->exclusiveDirectories);
        self::assertSame(['site', 'admin', 'api', 'site/language', 'admin/language', 'media'], $manifest->plan->sourceDirectories);
    }

    public function testComponentInstallerMetadataIsRelativeToTheAdministratorDirectory(): void
    {
        $installer = $this->parser->parse($this->componentXml(), 'example.xml')->installer;

        self::assertSame(
            ['scriptfile', 'install', 'update', 'administration/menu', 'administration/submenu'],
            array_keys($installer->sections),
        );
        self::assertSame('<menu link="option=com_example">COM_EXAMPLE</menu>', $installer->sections['administration/menu']);
        self::assertSame([
            'administrator/components/com_example/script.php',
            'administrator/components/com_example/sql/install.mysql.sql',
            'administrator/components/com_example/sql/updates/mysql',
        ], $installer->targets);
    }

    /**
     * @param non-empty-string $xml
     */
    #[DataProvider('componentElements')]
    public function testComponentElementFollowsTheJoomlaInstaller(string $xml, string $expectedElement): void
    {
        self::assertSame($expectedElement, $this->parser->parse($xml, 'x.xml')->element);
    }

    /**
     * @return iterable<string, array{non-empty-string, string}>
     */
    public static function componentElements(): iterable
    {
        yield 'name with prefix' => ['<extension type="component"><name>com_phocacart</name></extension>', 'com_phocacart'];
        yield 'name without prefix, filtered' => ['<extension type="component"><name>Phoca Cart</name></extension>', 'com_phocacart'];
        yield 'name with prefix in upper case' => ['<extension type="component"><name>COM_Example</name></extension>', 'com_example'];
        yield 'element tag preferred' => [
            '<extension type="component"><name>Example Shop</name><element>Shop</element></extension>',
            'com_shop',
        ];
    }

    public function testComponentAcceptsTrailingSlashInFolder(): void
    {
        $xml = '<extension type="component"><name>com_x</name><api><files folder="api/"><folder>src</folder></files></api></extension>';

        $plan = $this->parser->parse($xml, 'x.xml')->plan;

        self::assertEquals(new PlanEntry('api/src', 'api/components/com_x/src', EntryKind::Directory), $plan->entries[1]);
        self::assertSame(['api'], $plan->sourceDirectories);
    }

    public function testOtherTypesIgnoreAdministrationAndApiSections(): void
    {
        $xml = <<<'XML'
            <extension type="module">
                <name>mod_x</name>
                <element>mod_x</element>
                <administration><files folder="admin"><folder>src</folder></files></administration>
                <api><files folder="api"><folder>src</folder></files></api>
            </extension>
            XML;

        $plan = $this->parser->parse($xml, 'mod_x.xml')->plan;

        self::assertCount(1, $plan->entries);
        self::assertSame([], $plan->sourceDirectories);
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
        $xml = '<extension type="module"><name>mod_plain</name><element>mod_plain</element><files><folder>tmpl</folder></files></extension>';

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
            '<extension type="library"><name>lib_x</name></extension>',
            'library',
        ];
        yield 'component without valid characters' => [
            '<extension type="component"><name>Ärger</name><element>äöü</element></extension>',
            'element name',
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
        yield 'module without element' => [
            '<extension type="module"><name>mod_x</name><files><folder>tmpl</folder></files></extension>',
            'attribute module',
        ];
        yield 'module attribute outside the first files section' => [
            '<extension type="module"><name>mod_x</name><files><folder>tmpl</folder></files>'
                . '<files><folder module="mod_x">src</folder></files></extension>',
            'attribute module',
        ];
        yield 'plugin attribute outside the first files section' => [
            '<extension type="plugin" group="system"><name>x</name><files><folder>src</folder></files>'
                . '<files><filename plugin="x">x.php</filename></files></extension>',
            'attribute plugin',
        ];
        yield 'template without valid characters' => [
            '<extension type="template"><name>Ärger</name><element>äöü</element></extension>',
            'element name',
        ];
        yield 'unknown client of a language file' => [
            '<extension type="module"><name>mod_x</name><element>mod_x</element><languages>'
                . '<language tag="en-GB" client="api">en-GB/mod_x.ini</language></languages></extension>',
            'api',
        ];
        yield 'unknown client' => [
            '<extension type="module" client="api"><name>mod_x</name><element>mod_x</element></extension>',
            'api',
        ];
        yield 'empty name' => ['<extension type="module"><name> </name></extension>', 'extension name'];
        yield 'invalid element name' => [
            '<extension type="module"><name>x</name><element>../mod_x</element></extension>',
            '../mod_x',
        ];
        yield 'path with ..' => [
            '<extension type="module"><name>mod_x</name><element>mod_x</element><files><folder>../secret</folder></files></extension>',
            '../secret',
        ];
        yield 'absolute path' => [
            '<extension type="module"><name>mod_x</name><element>mod_x</element><files><filename>/etc/passwd</filename></files></extension>',
            '/etc/passwd',
        ];
        yield 'empty path' => [
            '<extension type="module"><name>mod_x</name><element>mod_x</element><files><folder> </folder></files></extension>',
            'Empty path',
        ];
        yield 'media without destination' => [
            '<extension type="module"><name>mod_x</name><element>mod_x</element><media folder="media"><folder>css</folder></media></extension>',
            'destination',
        ];
        yield 'language file without tag' => [
            '<extension type="module"><name>mod_x</name><element>mod_x</element><languages>'
                . '<language>en-GB/en-GB.mod_x.ini</language></languages></extension>',
            'Language file en-GB/en-GB.mod_x.ini without attribute tag is not supported',
        ];
        yield 'invalid language tag' => [
            '<extension type="module"><name>mod_x</name><element>mod_x</element><languages>'
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

    /**
     * Returns a component manifest in the classic layout with `site/`, `admin/` and `api/`.
     */
    private function componentXml(): string
    {
        return <<<'XML'
            <?xml version="1.0" encoding="utf-8"?>
            <extension type="component" method="upgrade">
                <name>COM_EXAMPLE</name>
                <version>1.0.0</version>
                <namespace path="src">SmartA\Component\Example</namespace>
                <scriptfile>script.php</scriptfile>
                <install>
                    <sql><file driver="mysql" charset="utf8">sql/install.mysql.sql</file></sql>
                </install>
                <update><schemas><schemapath type="mysql">sql/updates/mysql</schemapath></schemas></update>
                <files folder="site">
                    <folder>src</folder>
                    <folder>tmpl</folder>
                </files>
                <languages folder="site/language">
                    <language tag="en-GB">en-GB/com_example.ini</language>
                </languages>
                <media destination="com_example" folder="media">
                    <folder>js</folder>
                </media>
                <administration>
                    <menu link="option=com_example">COM_EXAMPLE</menu>
                    <submenu>
                        <menu link="option=com_example&amp;view=items">COM_EXAMPLE_ITEMS</menu>
                    </submenu>
                    <files folder="admin">
                        <folder>services</folder>
                        <folder>sql</folder>
                        <filename>access.xml</filename>
                    </files>
                    <languages folder="admin/language">
                        <language tag="en-GB">en-GB/com_example.ini</language>
                        <language tag="en-GB" client="site">en-GB/com_example.sys.ini</language>
                    </languages>
                </administration>
                <api>
                    <files folder="api"><folder>src</folder></files>
                </api>
            </extension>
            XML;
    }
}
