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
use SmartA\Jxt\Tests\TemporaryDirectoryTrait;

/**
 * Tests for loading and merging the global and the project configuration.
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

        self::assertNull($configuration->joomlaRoot);
        self::assertSame('www-data', $configuration->webUser);
        self::assertSame($this->project() . '/dist', $configuration->distDirectory);
        self::assertSame(0755, $configuration->directoryMode);
        self::assertSame(0644, $configuration->fileMode);
        self::assertNull($configuration->manifestFile);
        self::assertSame([], $configuration->checkExclude);
        self::assertSame([], $configuration->checkInclude);
        self::assertSame([], $configuration->cliCommand);
        self::assertNull($configuration->cliRoot);
    }

    public function testWorksWithoutGlobalConfigurationFile(): void
    {
        $configuration = (new ConfigurationLoader(null))->load($this->project());

        self::assertSame($this->project() . '/dist', $configuration->distDirectory);
    }

    public function testProjectConfigurationOverridesGlobalConfiguration(): void
    {
        $this->writeFile('config.json', (string) json_encode([
            'joomla_root' => '/var/www/html/',
            'web_user' => 'apache',
            'dist_dir' => '/srv/dist',
            'dir_mode' => '0775',
        ]));
        $this->writeFile('project/.jxt.json', (string) json_encode([
            'web_user' => null,
            'dist_dir' => 'build',
            'file_mode' => '664',
        ]));

        $configuration = (new ConfigurationLoader($this->directory . '/config.json'))->load($this->project());

        self::assertSame('/var/www/html', $configuration->joomlaRoot);
        self::assertNull($configuration->webUser);
        self::assertSame($this->project() . '/build', $configuration->distDirectory);
        self::assertSame(0775, $configuration->directoryMode);
        self::assertSame(0664, $configuration->fileMode);
    }

    public function testDescribesValuesWithTheirOrigin(): void
    {
        $this->writeFile('config.json', '{"joomla_root": "/var/www/html", "web_user": "apache", "dir_mode": "0775"}');
        $this->writeFile('project/.jxt.json', '{"web_user": null, "dir_mode": "0770"}');

        $entries = (new ConfigurationLoader($this->directory . '/config.json'))->describe($this->project());

        self::assertEquals([
            new ConfigurationEntry(ConfigurationKey::JoomlaRoot, '/var/www/html', ConfigurationSource::Global),
            new ConfigurationEntry(ConfigurationKey::WebUser, null, ConfigurationSource::Project),
            new ConfigurationEntry(ConfigurationKey::CliCommand, null, ConfigurationSource::None),
            new ConfigurationEntry(ConfigurationKey::CliRoot, null, ConfigurationSource::None),
            new ConfigurationEntry(ConfigurationKey::Manifest, null, ConfigurationSource::None),
            new ConfigurationEntry(ConfigurationKey::DistDirectory, 'dist', ConfigurationSource::Default),
            new ConfigurationEntry(ConfigurationKey::DirectoryMode, '0770', ConfigurationSource::Project),
            new ConfigurationEntry(ConfigurationKey::FileMode, '0644', ConfigurationSource::Default),
            new ConfigurationEntry(ConfigurationKey::CheckExclude, null, ConfigurationSource::None),
            new ConfigurationEntry(ConfigurationKey::CheckInclude, null, ConfigurationSource::None),
        ], $entries);
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
            $loader->describe($this->project())[9],
        );
    }

    public function testCliCommandReplacesWebUser(): void
    {
        $this->writeFile('config.json', (string) json_encode([
            'joomla_root' => '/home/user/site',
            'cli_command' => ['docker', 'exec', 'web', 'php', '-d', 'memory_limit=-1', '-d', 'display_errors=0'],
            'cli_root' => '/var/www/html/',
        ]));

        $configuration = (new ConfigurationLoader($this->directory . '/config.json'))->load($this->project());

        self::assertNull($configuration->webUser);
        self::assertSame(
            ['docker', 'exec', 'web', 'php', '-d', 'memory_limit=-1', '-d', 'display_errors=0'],
            $configuration->cliCommand,
        );
        self::assertSame('/var/www/html', $configuration->cliRoot);
    }

    public function testAllowsExplicitNullWebUserWithCliCommand(): void
    {
        $this->writeFile('config.json', '{"web_user": "apache"}');
        $this->writeFile('project/.jxt.json', '{"web_user": null, "cli_command": ["docker", "exec", "web", "php"]}');

        $configuration = (new ConfigurationLoader($this->directory . '/config.json'))->load($this->project());

        self::assertNull($configuration->webUser);
        self::assertSame(['docker', 'exec', 'web', 'php'], $configuration->cliCommand);
    }

    public function testReportsExplicitWebUserWithCliCommandAsSiteConflict(): void
    {
        $this->writeFile('config.json', '{"web_user": "apache"}');
        $this->writeFile('project/.jxt.json', '{"cli_command": ["docker", "exec", "web", "php"]}');

        $configuration = (new ConfigurationLoader($this->directory . '/config.json'))->load($this->project());

        self::assertStringContainsString(sprintf(
            'web_user (in %s) and cli_command (in %s) cannot be combined',
            $this->directory . '/config.json',
            $this->project() . '/.jxt.json',
        ), (string) $configuration->siteConflict);
    }

    public function testSuggestsNullForWebUserInTheFileThatTakesPrecedence(): void
    {
        $this->writeFile('config.json', '{"cli_command": ["docker", "exec", "web", "php"]}');
        $this->writeFile('project/.jxt.json', '{"web_user": "apache"}');

        $configuration = (new ConfigurationLoader($this->directory . '/config.json'))->load($this->project());

        self::assertStringContainsString(
            sprintf('Remove web_user or set it to null in %s.', $this->project() . '/.jxt.json'),
            (string) $configuration->siteConflict,
        );
    }

    public function testReportsCliRootWithoutCliCommandAsSiteConflict(): void
    {
        $this->writeFile('project/.jxt.json', '{"cli_root": "/var/www/html"}');

        $configuration = (new ConfigurationLoader(null))->load($this->project());

        self::assertStringContainsString('cli_root is only used together with cli_command', (string) $configuration->siteConflict);
    }

    public function testConsistentSiteHasNoConflict(): void
    {
        $this->writeFile('config.json', '{"web_user": "apache"}');
        $this->writeFile('project/.jxt.json', '{"cli_command": ["docker", "exec", "web", "php"], "web_user": null}');

        self::assertNull((new ConfigurationLoader($this->directory . '/config.json'))->load($this->project())->siteConflict);
    }

    public function testTestSiteRejectsSiteConflict(): void
    {
        $this->writeFile('project/.jxt.json', '{"cli_root": "/var/www/html"}');
        $configuration = (new ConfigurationLoader(null))->load($this->project());

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('cli_root is only used together with cli_command');

        $configuration->testSite();
    }

    public function testRejectsCheckListsInGlobalConfiguration(): void
    {
        $this->writeFile('config.json', '{"check_exclude": ["manifest"]}');

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessageMatches('/check_exclude can only be set in the project configuration/');

        (new ConfigurationLoader($this->directory . '/config.json'))->load($this->project());
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

        (new ConfigurationLoader($this->directory . '/config.json'))->load($this->project());
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
        yield 'wrong type' => ['{"web_user": 33}', 'web_user'];
        yield 'empty value' => ['{"web_user": " "}', 'web_user'];
        yield 'relative Joomla directory' => ['{"joomla_root": "html"}', 'joomla_root'];
        yield 'invalid mode' => ['{"file_mode": "0999"}', 'file_mode'];
        yield 'mode as number' => ['{"dir_mode": 755}', 'dir_mode'];
        yield 'absolute manifest path' => ['{"manifest": "/srv/mod_x.xml"}', 'Invalid value "/srv/mod_x.xml" for "manifest"'];
        yield 'list for a string key' => ['{"web_user": ["apache"]}', 'web_user'];
        yield 'string for a list' => ['{"check_exclude": "manifest"}', 'must be a list of check names'];
        yield 'object for a list' => ['{"check_exclude": {"a": "manifest"}}', 'must be a list of check names'];
        yield 'number in a list' => ['{"check_include": [1]}', 'must be a list of check names'];
        yield 'invalid check name' => ['{"check_exclude": ["Manifest"]}', 'Invalid check name "Manifest" for "check_exclude"'];
        yield 'check name with three parts' => ['{"check_exclude": ["a/b/c"]}', 'Invalid check name "a/b/c"'];
        yield 'string for a command' => ['{"cli_command": "docker exec web php"}', 'must be a non-empty list of arguments'];
        yield 'empty command' => ['{"cli_command": []}', 'must be a non-empty list of arguments'];
        yield 'empty argument in a command' => ['{"cli_command": ["php", ""]}', 'must be a non-empty list of arguments'];
        yield 'relative CLI root' => ['{"cli_command": ["php"], "cli_root": "html"}', 'Invalid value "html" for "cli_root"'];
    }

    /**
     * Returns the project directory inside the temporary directory.
     */
    private function project(): string
    {
        return $this->directory . '/project';
    }
}
