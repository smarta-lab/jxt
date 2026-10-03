<?php

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
        self::assertNull($configuration->owner);
        self::assertNull($configuration->group);
        self::assertSame($this->project() . '/dist', $configuration->distDirectory);
        self::assertSame(0755, $configuration->directoryMode);
        self::assertSame(0644, $configuration->fileMode);
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
            'owner' => 'www-data',
            'group' => 'www-data',
            'dist_dir' => '/srv/dist',
            'dir_mode' => '0775',
        ]));
        $this->writeFile('project/.jxt.json', (string) json_encode([
            'owner' => null,
            'dist_dir' => 'build',
            'file_mode' => '664',
        ]));

        $configuration = (new ConfigurationLoader($this->directory . '/config.json'))->load($this->project());

        self::assertSame('/var/www/html', $configuration->joomlaRoot);
        self::assertNull($configuration->owner);
        self::assertSame('www-data', $configuration->group);
        self::assertSame($this->project() . '/build', $configuration->distDirectory);
        self::assertSame(0775, $configuration->directoryMode);
        self::assertSame(0664, $configuration->fileMode);
    }

    public function testDescribesValuesWithTheirOrigin(): void
    {
        $this->writeFile('config.json', '{"joomla_root": "/var/www/html", "owner": "www-data", "dir_mode": "0775"}');
        $this->writeFile('project/.jxt.json', '{"owner": null, "dir_mode": "0770"}');

        $entries = (new ConfigurationLoader($this->directory . '/config.json'))->describe($this->project());

        self::assertEquals([
            new ConfigurationEntry(ConfigurationKey::JoomlaRoot, '/var/www/html', ConfigurationSource::Global),
            new ConfigurationEntry(ConfigurationKey::Owner, null, ConfigurationSource::Project),
            new ConfigurationEntry(ConfigurationKey::Group, null, ConfigurationSource::None),
            new ConfigurationEntry(ConfigurationKey::DistDirectory, 'dist', ConfigurationSource::Default),
            new ConfigurationEntry(ConfigurationKey::DirectoryMode, '0770', ConfigurationSource::Project),
            new ConfigurationEntry(ConfigurationKey::FileMode, '0644', ConfigurationSource::Default),
        ], $entries);
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
        yield 'wrong type' => ['{"owner": 33}', 'owner'];
        yield 'empty value' => ['{"group": " "}', 'group'];
        yield 'relative Joomla directory' => ['{"joomla_root": "html"}', 'joomla_root'];
        yield 'invalid mode' => ['{"file_mode": "0999"}', 'file_mode'];
        yield 'mode as number' => ['{"dir_mode": 755}', 'dir_mode'];
    }

    /**
     * Returns the project directory inside the temporary directory.
     */
    private function project(): string
    {
        return $this->directory . '/project';
    }
}
