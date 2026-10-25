<?php

/**
 * @copyright  (C) 2026 Carsten Volkwein
 * @license    MIT License; see LICENSE
 */

declare(strict_types=1);

namespace SmartA\Jxt\Tests\Configuration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SmartA\Jxt\Configuration\ConfigurationEntry;
use SmartA\Jxt\Configuration\ConfigurationException;
use SmartA\Jxt\Configuration\ConfigurationKey;
use SmartA\Jxt\Configuration\ConfigurationLoader;
use SmartA\Jxt\Configuration\ConfigurationSource;
use SmartA\Jxt\Configuration\PrepareStep;
use SmartA\Jxt\Configuration\SiteEntry;
use SmartA\Jxt\Configuration\SiteSelection;
use SmartA\Jxt\Configuration\SiteSelectionSource;
use SmartA\Jxt\Tests\TemporaryDirectoryTrait;

/**
 * Tests for loading and merging the global and the project configuration and for selecting the test site.
 */
final class ConfigurationLoaderTest extends TestCase
{
    use TemporaryDirectoryTrait;

    private string $directory;

    #[\Override]
    protected function setUp(): void
    {
        $this->directory = $this->createTemporaryDirectory();
        mkdir($this->directory . '/project');
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->removeTemporaryDirectory();
    }

    public function testUsesDefaultsWithoutConfigurationFiles(): void
    {
        $configuration = (new ConfigurationLoader($this->directory . '/missing.json'))->load($this->project());

        self::assertSame($this->project() . '/dist', $configuration->distDirectory);
        self::assertNull($configuration->manifestFile);
        self::assertSame([], $configuration->checkExclude);
        self::assertSame([], $configuration->checkInclude);
        self::assertSame([], $configuration->prepare);
        self::assertNull($configuration->site);
        self::assertEquals(new SiteSelection(), $configuration->siteSelection);
        self::assertStringContainsString('No test site is configured in', (string) $configuration->siteProblem());
    }

    public function testWorksWithoutGlobalConfigurationFile(): void
    {
        $configuration = (new ConfigurationLoader(null))->load($this->project());

        self::assertSame($this->project() . '/dist', $configuration->distDirectory);
    }

    public function testProjectConfigurationOverridesGlobalConfiguration(): void
    {
        $this->writeFile('config.json', '{"dist_dir": "/srv/dist"}');
        $this->writeFile('project/.jxt.json', '{"dist_dir": "build"}');

        $configuration = $this->loader()->load($this->project());

        self::assertSame($this->project() . '/build', $configuration->distDirectory);
    }

    public function testDescribesValuesOutsideOfTheSitesWithTheirOrigin(): void
    {
        $this->writeFile(
            'config.json',
            '{"dist_dir": "/srv/dist", "default_site": "local", "sites": [{"id": "local", "joomla_root": "/var/www/html"}]}',
        );
        $this->writeFile('project/.jxt.json', '{"manifest": null}');

        self::assertEquals([
            new ConfigurationEntry(ConfigurationKey::Manifest, null, ConfigurationSource::Project),
            new ConfigurationEntry(ConfigurationKey::Prepare, null, ConfigurationSource::None),
            new ConfigurationEntry(ConfigurationKey::DistDirectory, '/srv/dist', ConfigurationSource::Global),
            new ConfigurationEntry(ConfigurationKey::CheckExclude, null, ConfigurationSource::None),
            new ConfigurationEntry(ConfigurationKey::CheckInclude, null, ConfigurationSource::None),
            new ConfigurationEntry(ConfigurationKey::DefaultSite, 'local', ConfigurationSource::Global),
        ], $this->loader()->describe($this->project()));
    }

    public function testReadsCheckListsWithoutDuplicates(): void
    {
        $this->writeFile('project/.jxt.json', (string) json_encode([
            'check_exclude' => ['manifest', 'editorconfig/max-line-length', 'manifest'],
            'check_include' => ['manifest/version-semver'],
        ]));

        $loader = new ConfigurationLoader(null);
        $configuration = $loader->load($this->project());

        self::assertSame(['manifest', 'editorconfig/max-line-length'], $configuration->checkExclude);
        self::assertSame(['manifest/version-semver'], $configuration->checkInclude);
        self::assertEquals(
            new ConfigurationEntry(ConfigurationKey::CheckInclude, ['manifest/version-semver'], ConfigurationSource::Project),
            $loader->describe($this->project())[4],
        );
    }

    public function testReadsPrepareStepsAsWritten(): void
    {
        $steps = [
            ['run' => ['npm', 'run', 'css']],
            ['run' => ['npm', 'run', 'minify'], 'sync' => ['npm', 'run', 'minify:copy']],
            ['run' => ['npm', 'run', 'images'], 'sync' => false],
            ['run' => ['php', 'build/version.php']],
        ];
        $this->writeFile('project/.jxt.json', (string) json_encode(['prepare' => $steps]));

        $loader = new ConfigurationLoader(null);

        self::assertEquals([
            new PrepareStep(['npm', 'run', 'css']),
            new PrepareStep(['npm', 'run', 'minify'], ['npm', 'run', 'minify:copy']),
            new PrepareStep(['npm', 'run', 'images'], false),
            new PrepareStep(['php', 'build/version.php']),
        ], $loader->load($this->project())->prepare);
        self::assertEquals(
            new ConfigurationEntry(ConfigurationKey::Prepare, $steps, ConfigurationSource::Project),
            $loader->describe($this->project())[1],
        );
    }

    public function testRejectsPrepareInGlobalConfiguration(): void
    {
        $this->writeFile('config.json', '{"prepare": [{"run": ["npm", "run", "build"]}]}');

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessageMatches('/prepare can only be set in the project configuration/');

        $this->loader()->load($this->project());
    }

    public function testSelectsTheDefaultSite(): void
    {
        $this->writeSites(
            'local',
            ['id' => 'ddev', 'joomla_root' => '/srv/ddev'],
            ['id' => 'local', 'joomla_root' => '/var/www/html/', 'web_user' => 'apache'],
        );

        $configuration = $this->loader()->load($this->project());

        self::assertEquals(new SiteEntry('local', $this->globalFile(), '/var/www/html', 'apache'), $configuration->site);
        self::assertNull($configuration->siteProblem());
        self::assertEquals(new SiteSelection('local', SiteSelectionSource::DefaultSite), $configuration->siteSelection);
        self::assertSame('site "local" (default_site)', $configuration->siteSelection->describe());
        self::assertFalse($configuration->siteSelection->isExplicit());
    }

    public function testSelectsTheSiteOfTheEnvironmentVariable(): void
    {
        $this->writeSites('local', ['id' => 'local', 'joomla_root' => '/var/www/html'], ['id' => 'ddev', 'joomla_root' => '/srv/ddev']);

        $configuration = $this->loader('ddev')->load($this->project());

        self::assertSame('ddev', $configuration->site?->id);
        self::assertEquals(new SiteSelection('ddev', SiteSelectionSource::Environment), $configuration->siteSelection);
        self::assertSame('site "ddev" via JXT_SITE', $configuration->siteSelection->describe());
        self::assertTrue($configuration->siteSelection->isExplicit());
    }

    public function testOptionTakesPrecedenceOverTheEnvironmentVariable(): void
    {
        $this->writeSites(null, ['id' => 'local', 'joomla_root' => '/var/www/html'], ['id' => 'ddev', 'joomla_root' => '/srv/ddev']);

        $configuration = $this->loader('ddev')->load($this->project(), 'local');

        self::assertSame('/var/www/html', $configuration->site?->joomlaRoot);
        self::assertSame('site "local" via --site', $configuration->siteSelection->describe());
    }

    public function testEmptyEnvironmentVariableSelectsTheDefaultSite(): void
    {
        $this->writeSites('local', ['id' => 'local', 'joomla_root' => '/var/www/html']);

        self::assertSame('/var/www/html', $this->loader('')->load($this->project())->site?->joomlaRoot);
    }

    public function testAnyIdIsJustAName(): void
    {
        $this->writeSites('default', ['id' => 'default', 'joomla_root' => '/var/www/html']);

        self::assertSame('default', $this->loader()->load($this->project(), 'default')->site?->id);
    }

    public function testRejectsEmptySiteOption(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('--site needs the id of a site.');

        $this->loader()->load($this->project(), '');
    }

    public function testReportsUnknownSiteWhenItIsUsed(): void
    {
        $this->writeSites('local', ['id' => 'local', 'joomla_root' => '/var/www/html'], ['id' => 'ddev', 'joomla_root' => '/srv/ddev']);
        $configuration = $this->loader('j5')->load($this->project());

        self::assertNull($configuration->site);
        self::assertSame(sprintf(
            'There is no site "j5" (selected via JXT_SITE) in %s. Configured sites: local, ddev. Add it with jxt site add j5.',
            $this->globalFile(),
        ), $configuration->siteProblem());

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('There is no site "j5"');

        $configuration->testSite();
    }

    public function testReportsUnknownDefaultSite(): void
    {
        $this->writeSites('j5', ['id' => 'local', 'joomla_root' => '/var/www/html']);

        self::assertStringContainsString(
            'There is no site "j5" (selected via default_site)',
            (string) $this->loader()->load($this->project())->siteProblem(),
        );
    }

    public function testWorksWithoutDefaultSiteIfASiteIsSelected(): void
    {
        $this->writeSites(null, ['id' => 'ddev', 'joomla_root' => '/srv/ddev'], ['id' => 'j5', 'joomla_root' => '/srv/j5']);

        self::assertSame('/srv/j5', $this->loader('j5')->load($this->project())->site?->joomlaRoot);
    }

    public function testReportsThatNoSiteIsSelected(): void
    {
        $this->writeSites(null, ['id' => 'ddev', 'joomla_root' => '/srv/ddev']);

        $configuration = $this->loader()->load($this->project());

        self::assertSame(sprintf(
            'No test site is selected: select one with --site or JXT_SITE, or set default_site in %s. Configured sites: ddev.',
            $this->globalFile(),
        ), $configuration->siteProblem());
        self::assertSame('no site selected', $configuration->siteSelection->describe());
    }

    public function testReportsMissingJoomlaRootOfTheSite(): void
    {
        $this->writeSites(null, ['id' => 'ddev', 'web_user' => 'www-data']);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage(sprintf(
            'joomla_root is not set in site "ddev" of %s. Please set it with jxt config set joomla_root <path> --site=ddev.',
            $this->globalFile(),
        ));

        $this->loader()->load($this->project(), 'ddev')->validJoomlaRoot();
    }

    public function testReadsCliCommandOfTheSite(): void
    {
        $this->writeSites('local', [
            'id' => 'local',
            'joomla_root' => '/home/user/site',
            'cli_command' => ['docker', 'exec', 'web', 'php', '-d', 'memory_limit=-1', '-d', 'display_errors=0'],
            'cli_root' => '/var/www/html/',
        ]);

        $site = $this->loader()->load($this->project())->site;

        self::assertNotNull($site);
        self::assertNull($site->webUser);
        self::assertSame(['docker', 'exec', 'web', 'php', '-d', 'memory_limit=-1', '-d', 'display_errors=0'], $site->cliCommand);
        self::assertSame('/var/www/html', $site->cliRoot);
    }

    public function testSiteInheritsNothingFromTheDefaultSite(): void
    {
        $this->writeSites(
            'local',
            ['id' => 'local', 'joomla_root' => '/var/www/html', 'web_user' => 'apache'],
            ['id' => 'ddev', 'joomla_root' => '/srv/ddev'],
        );

        self::assertNull($this->loader('ddev')->load($this->project())->site?->webUser);
    }

    public function testReportsWebUserWithCliCommandAsSiteProblem(): void
    {
        $this->writeSites(null, ['id' => 'ddev', 'joomla_root' => '/srv/ddev', 'web_user' => 'apache', 'cli_command' => ['ddev', 'exec', 'php']]);

        self::assertSame(
            sprintf(
                'web_user and cli_command cannot be combined in site "ddev" of %s: with cli_command, the command itself '
                . 'determines the user. Remove web_user.',
                $this->globalFile(),
            ),
            $this->loader('ddev')->load($this->project())->siteProblem(),
        );
    }

    public function testReportsCliRootWithoutCliCommandAsSiteProblem(): void
    {
        $this->writeSites('local', ['id' => 'local', 'joomla_root' => '/srv/site', 'cli_root' => '/var/www/html']);

        self::assertSame(
            sprintf('cli_root is only used together with cli_command (in site "local" of %s).', $this->globalFile()),
            $this->loader()->load($this->project())->siteProblem(),
        );
    }

    public function testTestSiteRejectsSiteConflict(): void
    {
        $this->writeSites('local', ['id' => 'local', 'joomla_root' => '/srv/site', 'cli_root' => '/var/www/html']);
        $configuration = $this->loader()->load($this->project());

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('cli_root is only used together with cli_command');

        $configuration->testSite();
    }

    public function testTestSiteKeepsTheIdOfTheSite(): void
    {
        $this->writeFile('joomla/configuration.php');
        $this->writeSites(null, ['id' => 'ddev', 'joomla_root' => $this->directory . '/joomla', 'cli_command' => ['ddev', 'exec', 'php']]);

        $site = $this->loader('ddev')->load($this->project())->testSite();

        self::assertSame('ddev', $site->id);
        self::assertSame(['ddev', 'exec', 'php'], $site->cliCommand);
    }

    public function testRejectsSiteKeysInTheProjectConfigurationWithASuggestion(): void
    {
        $this->writeFile('project/.jxt.json', '{"joomla_root": "/var/www/html", "web_user": null, "manifest": "mod_x.xml"}');

        try {
            $this->loader()->load($this->project());
            self::fail('Site keys in the project configuration were accepted.');
        } catch (ConfigurationException $exception) {
            self::assertSame(
                sprintf(
                    'joomla_root, web_user can no longer be set at the top level of %s: the keys of the test site belong '
                    . 'to a site in "sites" of the global configuration ~/.config/jxt/config.json, e.g.:',
                    $this->project() . '/.jxt.json',
                ) . "\n{\n    \"sites\": [\n        {\n            \"id\": \"project\",\n"
                . "            \"joomla_root\": \"/var/www/html\"\n        }\n    ]\n}\n"
                . 'Select it for this project with --site or JXT_SITE, e.g. in its .envrc.',
                $exception->getMessage(),
            );
        }
    }

    public function testRejectsSiteKeysAtTheTopLevelOfTheGlobalConfiguration(): void
    {
        $this->writeFile('config.json', '{"joomla_root": "/var/www/html", "web_user": "www-data"}');

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage(
            sprintf('joomla_root, web_user can no longer be set at the top level of %s', $this->globalFile()) . ': the '
            . "keys of the test site belong to a site in \"sites\" of the global configuration ~/.config/jxt/config.json, e.g.:\n{\n"
            . "    \"default_site\": \"local\",\n    \"sites\": [\n        {\n            \"id\": \"local\",\n",
        );

        $this->loader()->load($this->project());
    }

    public function testRejectsInvalidDefaultSite(): void
    {
        $this->writeFile('config.json', '{"default_site": "my site"}');

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('Invalid value "my site" for "default_site"');

        $this->loader()->load($this->project());
    }

    public function testRejectsSitesInTheProjectConfiguration(): void
    {
        $this->writeFile('project/.jxt.json', '{"sites": [{"joomla_root": "/var/www/html"}]}');

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('sites can only be set in the global configuration');

        $this->loader()->load($this->project());
    }

    public function testRejectsCheckListsInGlobalConfiguration(): void
    {
        $this->writeFile('config.json', '{"check_exclude": ["manifest"]}');

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessageMatches('/check_exclude can only be set in the project configuration/');

        $this->loader()->load($this->project());
    }

    public function testResolvesManifestRelativeToProjectDirectory(): void
    {
        $this->writeFile('project/.jxt.json', '{"manifest": "extension/mod_x.xml"}');

        $configuration = (new ConfigurationLoader(null))->load($this->project());

        self::assertSame($this->project() . '/extension/mod_x.xml', $configuration->manifestFile);
    }

    public function testRejectsManifestInGlobalConfiguration(): void
    {
        $this->writeFile('config.json', '{"manifest": "mod_x.xml"}');

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessageMatches('/manifest can only be set in the project configuration/');

        $this->loader()->load($this->project());
    }

    public function testKeepsAbsoluteDistDirectory(): void
    {
        $this->writeFile('project/.jxt.json', '{"dist_dir": "/srv/dist/"}');

        $configuration = (new ConfigurationLoader(null))->load($this->project());

        self::assertSame('/srv/dist', $configuration->distDirectory);
    }

    /**
     * @param non-empty-string $expectedMessage Part of the expected error message
     */
    #[DataProvider('invalidConfigurations')]
    public function testRejectsInvalidConfiguration(string $json, string $expectedMessage): void
    {
        $this->writeFile('project/.jxt.json', $json);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessageIsOrContains($expectedMessage);

        (new ConfigurationLoader(null))->load($this->project());
    }

    /**
     * @return iterable<string, array{string, non-empty-string}>
     */
    public static function invalidConfigurations(): iterable
    {
        yield 'no JSON' => ['{', '.jxt.json'];
        yield 'no object' => ['[1, 2]', 'must be a JSON object'];
        yield 'unknown key' => ['{"joomla_rot": "/var/www"}', 'joomla_rot'];
        yield 'removed mode' => ['{"dir_mode": "0775"}', 'is no longer supported: jxt sync uses the modes of the Joomla installer'];
        yield 'default site in the project' => ['{"default_site": "local"}', 'default_site can only be set in the global configuration'];
        yield 'wrong type' => ['{"dist_dir": 33}', 'dist_dir'];
        yield 'empty value' => ['{"dist_dir": " "}', 'dist_dir'];
        yield 'absolute manifest path' => ['{"manifest": "/srv/mod_x.xml"}', 'Invalid value "/srv/mod_x.xml" for "manifest"'];
        yield 'list for a string key' => ['{"dist_dir": ["build"]}', 'dist_dir'];
        yield 'string for a list' => ['{"check_exclude": "manifest"}', 'must be a list of check names'];
        yield 'object for a list' => ['{"check_exclude": {"a": "manifest"}}', 'must be a list of check names'];
        yield 'number in a list' => ['{"check_include": [1]}', 'must be a list of check names'];
        yield 'invalid check name' => ['{"check_exclude": ["Manifest"]}', 'Invalid check name "Manifest" for "check_exclude"'];
        yield 'check name with three parts' => ['{"check_exclude": ["a/b/c"]}', 'Invalid check name "a/b/c"'];
        yield 'string for prepare' => ['{"prepare": "npm run build"}', 'Value of "prepare" in'];
        yield 'empty prepare' => ['{"prepare": []}', 'must be a non-empty list of steps'];
        yield 'single command for prepare' => ['{"prepare": ["npm", "run", "build"]}', 'Step 1 of "prepare" in'];
        yield 'command as step' => [
            '{"prepare": [["npm", "run", "build"]]}',
            'must be an object with "run" and optionally "sync", e.g. {"run": ["npm", "run", "build"]}',
        ];
        yield 'string as step' => ['{"prepare": [{"run": ["npm"]}, "npm"]}', 'Step 2 of "prepare"'];
        yield 'empty step' => ['{"prepare": [{}]}', 'Step 1 of "prepare"'];
        yield 'empty argument in run' => ['{"prepare": [{"run": ["npm", ""]}]}', 'Step 1 of "prepare"'];
        yield 'step without run' => ['{"prepare": [{"sync": false}]}', 'Step 1 of "prepare" in'];
        yield 'invalid run' => ['{"prepare": [{"run": "npm run build"}]}', 'Step 1 of "prepare"'];
        yield 'unknown key in a step' => ['{"prepare": [{"run": ["npm"], "dir": "x"}]}', 'unknown key "dir"'];
        yield 'sync null' => [
            '{"prepare": [{"run": ["npm"], "sync": null}]}',
            'sync: null is ambiguous. Omit sync to run the same command, or use false to skip the step for jxt sync.',
        ];
        yield 'sync true' => ['{"prepare": [{"run": ["npm"], "sync": true}]}', 'Step 1 of "prepare"'];
        yield 'empty sync' => ['{"prepare": [{"run": ["npm"], "sync": []}]}', 'Step 1 of "prepare"'];
    }

    /**
     * @param non-empty-string $expectedMessage Part of the expected error message
     */
    #[DataProvider('invalidSites')]
    public function testRejectsInvalidSites(string $sites, string $expectedMessage): void
    {
        $this->writeFile('config.json', sprintf('{"sites": %s}', $sites));

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessageIsOrContains($expectedMessage);

        $this->loader()->load($this->project());
    }

    /**
     * @return iterable<string, array{string, non-empty-string}>
     */
    public static function invalidSites(): iterable
    {
        yield 'object for sites' => ['{"a": {"joomla_root": "/var/www"}}', 'must be a non-empty list of sites'];
        yield 'empty sites' => ['[]', 'must be a non-empty list of sites'];
        yield 'string as site' => ['["/var/www"]', 'Site 1 of "sites" in'];
        yield 'list as site' => ['[{"id": "a"}, ["/srv"]]', 'Site 2 of "sites"'];
        yield 'unknown key in a site' => [
            '[{"id": "a", "dist_dir": "build"}]',
            'has the unknown key "dist_dir". Allowed: id, joomla_root, web_user, cli_command, cli_root.',
        ];
        yield 'removed mode in a site' => ['[{"id": "a", "dir_mode": "0775"}]', 'unknown key "dir_mode"'];
        yield 'site without id' => ['[{"joomla_root": "/var/www"}]', 'has no id; every site needs one'];
        yield 'id with a space' => ['[{"id": "my site"}]', 'has an invalid id'];
        yield 'number as id' => ['[{"id": 1}]', 'has an invalid id'];
        yield 'duplicate id' => ['[{"id": "a"}, {"id": "a"}]', 'The id "a" is used by more than one site'];
        yield 'relative Joomla directory' => ['[{"id": "a", "joomla_root": "html"}]', 'Invalid value "html" for "joomla_root" in Site 1 of "sites"'];
        yield 'wrong type' => ['[{"id": "a", "web_user": 33}]', 'web_user'];
        yield 'empty value' => ['[{"id": "a", "web_user": " "}]', 'web_user'];
        yield 'string for a command' => ['[{"id": "a", "cli_command": "docker exec web php"}]', 'must be a non-empty list of arguments'];
        yield 'empty command' => ['[{"id": "a", "cli_command": []}]', 'must be a non-empty list of arguments'];
        yield 'empty argument in a command' => ['[{"id": "a", "cli_command": ["php", ""]}]', 'must be a non-empty list of arguments'];
        yield 'relative CLI root' => ['[{"id": "a", "cli_command": ["php"], "cli_root": "html"}]', 'Invalid value "html" for "cli_root"'];
    }

    /**
     * Writes the sites and `default_site` into the global configuration.
     *
     * @param string|null          $defaultSite Value of `default_site`; null to leave it out
     * @param array<string, mixed> ...$sites
     */
    private function writeSites(?string $defaultSite, array ...$sites): void
    {
        $this->writeFile('config.json', (string) json_encode([
            ...($defaultSite === null ? [] : ['default_site' => $defaultSite]),
            'sites' => array_values($sites),
        ]));
    }

    /**
     * Returns a loader for the global configuration in the temporary directory.
     *
     * @param string|null $environmentSite Value of `JXT_SITE`
     */
    private function loader(?string $environmentSite = null): ConfigurationLoader
    {
        return new ConfigurationLoader($this->globalFile(), $environmentSite);
    }

    /**
     * Returns the path of the global configuration.
     */
    private function globalFile(): string
    {
        return $this->directory . '/config.json';
    }

    /**
     * Returns the project directory inside the temporary directory.
     */
    private function project(): string
    {
        return $this->directory . '/project';
    }
}
