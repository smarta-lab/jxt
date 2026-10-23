<?php

/**
 * @copyright  (C) 2026 Carsten Volkwein
 * @license    MIT License; see LICENSE
 */

declare(strict_types=1);

namespace SmartA\Jxt\Tests\Check\Checker;

use PHPUnit\Framework\TestCase;
use SmartA\Jxt\Check\Checker\AssetChecker;
use SmartA\Jxt\Tests\Check\CheckProjectTrait;

/**
 * Tests for checking `joomla.asset.json`: JSON, URIs and versions against the last release tag.
 */
final class AssetCheckerTest extends TestCase
{
    use CheckProjectTrait;

    private const string FILE = 'media/joomla.asset.json';

    #[\Override]
    protected function setUp(): void
    {
        $this->createProject();
        $this->writeFile('media/css/site.css', "a {}\n");
        $this->writeFile('media/js/site.min.js', "a();\n");
        $this->writeFile('media/js/library.js', "lib();\n");
        $this->writeAssets('1.0.0', ['1.0.0', '1.0.0', '2.5.0']);
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->removeTemporaryDirectory();
    }

    public function testWithoutGitOnlyUrisAreChecked(): void
    {
        self::assertSame(
            ['INFO assets/baseline: asset versions not compared: no Git repository'],
            $this->findings(new AssetChecker()),
        );
    }

    public function testReportsInvalidJson(): void
    {
        $this->writeFile('media/joomla.asset.json', '{"assets": [');

        self::assertSame(
            'ERROR assets/json: media/joomla.asset.json: invalid JSON (Syntax error)',
            $this->findings(new AssetChecker())[1],
        );
    }

    public function testReportsUrisWithoutFile(): void
    {
        $this->writeFile('media/joomla.asset.json', (string) json_encode(['assets' => [
            ['name' => 'mod_example.missing', 'type' => 'style', 'uri' => 'mod_example/missing.css', 'version' => '1.0.0'],
            ['name' => 'mod_example.unminified', 'type' => 'script', 'uri' => 'mod_example/site.js', 'version' => '1.0.0'],
            ['name' => 'mod_example.external', 'type' => 'script', 'uri' => 'https://example.org/a.js'],
            ['name' => 'mod_example.foreign', 'type' => 'script', 'uri' => 'system/core.js'],
            ['name' => 'mod_example.preset', 'type' => 'preset', 'dependencies' => ['mod_example.missing']],
        ]]));

        self::assertSame(
            ['WARN assets/uri: ' . self::FILE . ': asset mod_example.missing: URI mod_example/missing.css does not match a file of the extension'],
            array_slice($this->findings(new AssetChecker()), 1),
        );
    }

    public function testMissingUserFilesOfTemplatesAreNotices(): void
    {
        $this->writeFile('templateDetails.xml', <<<'XML'
            <?xml version="1.0" encoding="utf-8"?>
            <extension type="template" client="site">
                <name>example</name>
                <version>1.0.0</version>
                <inheritable>1</inheritable>
                <files><filename>joomla.asset.json</filename></files>
                <media destination="templates/site/example" folder="media"><folder>css</folder></media>
            </extension>
            XML);
        $this->writeFile('joomla.asset.json', (string) json_encode(['assets' => [
            ['name' => 'template.user.style', 'type' => 'style', 'uri' => 'user.css'],
            ['name' => 'template.user.script', 'type' => 'script', 'uri' => 'user.js'],
            ['name' => 'template.missing', 'type' => 'style', 'uri' => 'missing.css'],
        ]]));

        self::assertSame(
            [
                'INFO assets/uri: joomla.asset.json: asset template.user.style: user.css is not shipped, site owners may create it',
                'INFO assets/uri: joomla.asset.json: asset template.user.script: user.js is not shipped, site owners may create it',
                'WARN assets/uri: joomla.asset.json: asset template.missing: URI missing.css does not match a file of the extension',
            ],
            array_slice($this->findings(new AssetChecker(), $this->context(manifest: 'templateDetails.xml')), 1),
        );
    }

    public function testUrisOfClassicTemplatesReferToTheTemplateDirectory(): void
    {
        $this->writeFile('templateDetails.xml', <<<'XML'
            <?xml version="1.0" encoding="utf-8"?>
            <extension type="template" client="site">
                <name>example</name>
                <version>1.0.0</version>
                <files><filename>joomla.asset.json</filename><folder>css</folder></files>
            </extension>
            XML);
        $this->writeFile('css/template.css', "a {}\n");
        $this->writeFile('css/mod_menu/menu.css', "a {}\n");
        $this->writeFile('joomla.asset.json', (string) json_encode(['assets' => [
            ['name' => 'template.example', 'type' => 'style', 'uri' => 'template.css'],
            ['name' => 'template.example.menu', 'type' => 'style', 'uri' => 'mod_menu/menu.css'],
            ['name' => 'template.example.missing', 'type' => 'style', 'uri' => 'missing.css'],
            ['name' => 'template.example.core', 'type' => 'script', 'uri' => 'system/core.js'],
        ]]));

        self::assertSame(
            ['WARN assets/uri: joomla.asset.json: asset template.example.missing: URI missing.css does not match a file of the extension'],
            array_slice($this->findings(new AssetChecker(), $this->context(manifest: 'templateDetails.xml')), 1),
        );
    }

    public function testChildTemplatesMayUseFilesOfTheirParent(): void
    {
        $this->writeFile('templateDetails.xml', <<<'XML'
            <?xml version="1.0" encoding="utf-8"?>
            <extension type="template" client="site">
                <name>child</name>
                <version>1.0.0</version>
                <parent>cassiopeia</parent>
                <files><filename>joomla.asset.json</filename></files>
                <media destination="templates/site/child" folder="media"><folder>css</folder></media>
            </extension>
            XML);
        $this->writeFile('joomla.asset.json', (string) json_encode(['assets' => [
            ['name' => 'template.child', 'type' => 'style', 'uri' => 'site.css'],
            ['name' => 'template.child.parent', 'type' => 'style', 'uri' => 'template.css'],
        ]]));

        self::assertSame([], array_slice($this->findings(new AssetChecker(), $this->context(manifest: 'templateDetails.xml')), 1));
    }

    public function testMissingUserFilesOfModulesAreWarnings(): void
    {
        $this->writeFile('media/joomla.asset.json', (string) json_encode(['assets' => [
            ['name' => 'mod_example.user', 'type' => 'style', 'uri' => 'mod_example/user.css'],
        ]]));

        self::assertSame(
            ['WARN assets/uri: ' . self::FILE . ': asset mod_example.user: URI mod_example/user.css does not match a file of the extension'],
            array_slice($this->findings(new AssetChecker()), 1),
        );
    }

    public function testFirstReleaseHasNoBaseline(): void
    {
        $this->initGit();

        self::assertSame(
            ['INFO assets/baseline: asset versions not compared: no release tag yet'],
            $this->findings(new AssetChecker()),
        );
    }

    public function testPreCheckPointsOutChangedFiles(): void
    {
        $this->writeManifestVersion('1.0.0');
        $this->initGit();
        $this->git('tag', 'v1.0.0');
        $this->writeFile('media/css/site.css', "b {}\n");
        $this->writeFile('media/js/site.min.js', "b();\n");
        $this->writeAssets('1.0.0', ['1.0.0', '1.0.1', '2.5.0']);

        self::assertSame(
            [
                'INFO assets/version-changed: ' . self::FILE . ': asset mod_example.style: media/css/site.css changed since v1.0.0, '
                    . 'raise its version 1.0.0 with the release',
            ],
            $this->findings(new AssetChecker()),
        );
    }

    public function testFinalCheckReportsChangedFilesAndCoupledVersions(): void
    {
        $this->writeManifestVersion('1.0.0');
        $this->initGit();
        $this->git('tag', 'v1.0.0');
        $this->writeManifestVersion('1.1.0');
        $this->writeFile('media/css/site.css', "b {}\n");
        $this->writeAssets('1.0.0', ['1.0.0', '1.0.0', '2.5.0']);

        $coupled = static fn (string $label): string => sprintf(
            'INFO assets/version-coupled: %s: %s is 1.0.0; it was the extension version 1.0.0 at the last release, should it be 1.1.0?',
            self::FILE,
            $label,
        );

        self::assertSame([
            'INFO assets/version-changed: ' . self::FILE . ': asset mod_example.style: media/css/site.css changed since v1.0.0, '
                . 'but its version is still 1.0.0',
            $coupled('top-level "version"'),
            $coupled('asset mod_example.style'),
            $coupled('asset mod_example.script'),
        ], $this->findings(new AssetChecker()));
        self::assertStringStartsWith('ERROR assets/version-changed', $this->findings(new AssetChecker(), $this->context(true))[0]);
    }

    public function testFinalCheckPassesAfterRaisingVersions(): void
    {
        $this->writeManifestVersion('1.0.0');
        $this->initGit();
        $this->git('tag', 'v1.0.0');
        $this->writeManifestVersion('1.1.0');
        $this->writeFile('media/css/site.css', "b {}\n");
        $this->writeAssets('1.1.0', ['1.1.0', '1.1.0', '2.5.0']);

        self::assertSame([], $this->findings(new AssetChecker()));
    }

    /**
     * Writes `media/joomla.asset.json` with a style, a minified script and a preset.
     *
     * @param array{string, string, string} $versions Versions of the style, the script and a library
     */
    private function writeAssets(string $version, array $versions): void
    {
        $this->writeFile('media/joomla.asset.json', (string) json_encode([
            'name' => 'mod_example',
            'version' => $version,
            'assets' => [
                ['name' => 'mod_example.style', 'type' => 'style', 'uri' => 'mod_example/site.css', 'version' => $versions[0]],
                ['name' => 'mod_example.script', 'type' => 'script', 'uri' => 'mod_example/site.min.js', 'version' => $versions[1]],
                ['name' => 'mod_example.library', 'type' => 'script', 'uri' => 'mod_example/library.js', 'version' => $versions[2]],
                ['name' => 'mod_example.auto', 'type' => 'style', 'uri' => 'mod_example/site.css', 'version' => 'auto'],
            ],
        ], JSON_PRETTY_PRINT));
    }

    /**
     * Sets the version in the manifest.
     */
    private function writeManifestVersion(string $version): void
    {
        $this->writeFile('mod_example.xml', str_replace('<version>1.1.0</version>', '<version>' . $version . '</version>', self::MANIFEST));
    }
}
