<?php

/**
 * @copyright  (C) 2026 Carsten Volkwein
 * @license    MIT License; see LICENSE
 */

declare(strict_types=1);

namespace SmartA\Jxt\Tests\Configuration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SmartA\Jxt\Configuration\ConfigurationException;
use SmartA\Jxt\Configuration\ConfigurationFile;
use SmartA\Jxt\Configuration\ConfigurationKey;
use SmartA\Jxt\Tests\TemporaryDirectoryTrait;

/**
 * Tests for reading and writing a single configuration file.
 */
final class ConfigurationFileTest extends TestCase
{
    use TemporaryDirectoryTrait;

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

    public function testMissingFileIsEmpty(): void
    {
        self::assertSame([], (new ConfigurationFile($this->temporaryDirectory . '/missing.json'))->read());
    }

    public function testWritesValuesInKeyOrderAndCreatesDirectory(): void
    {
        $file = new ConfigurationFile($this->temporaryDirectory . '/sub/dir/config.json');

        $file->write(['check_include' => ['manifest'], 'manifest' => null, 'dist_dir' => 'build']);

        self::assertSame(
            "{\n    \"manifest\": null,\n    \"dist_dir\": \"build\",\n    \"check_include\": [\n        \"manifest\"\n    ]\n}\n",
            file_get_contents($file->path),
        );
        self::assertSame(['manifest' => null, 'dist_dir' => 'build', 'check_include' => ['manifest']], $file->read());
    }

    public function testWritesLists(): void
    {
        $file = new ConfigurationFile($this->temporaryDirectory . '/.jxt.json');

        $file->write(['check_exclude' => ['manifest', 'assets/uri']]);

        self::assertSame(
            "{\n    \"check_exclude\": [\n        \"manifest\",\n        \"assets/uri\"\n    ]\n}\n",
            file_get_contents($file->path),
        );
        self::assertSame(['check_exclude' => ['manifest', 'assets/uri']], $file->read());
    }

    public function testWritesPrepareStepsInTheirForm(): void
    {
        $file = new ConfigurationFile($this->temporaryDirectory . '/.jxt.json');
        $steps = [['run' => ['npm', 'run', 'css']], ['run' => ['npm', 'run', 'images'], 'sync' => false]];

        $file->write(['prepare' => $steps]);

        self::assertSame($steps, $file->read()['prepare']);
        self::assertStringContainsString('"sync": false', (string) file_get_contents($file->path));
    }

    public function testDeletesTheFileWithoutValues(): void
    {
        $file = new ConfigurationFile($this->temporaryDirectory . '/config.json');
        $file->write(['dist_dir' => 'build']);

        $file->write([]);

        self::assertFileDoesNotExist($file->path);
        self::assertSame([], $file->read());
    }

    public function testWritesNothingWithoutValuesAndFile(): void
    {
        $file = new ConfigurationFile($this->temporaryDirectory . '/jxt/config.json');

        $file->write([]);

        self::assertDirectoryDoesNotExist($this->temporaryDirectory . '/jxt');
    }

    public function testKeysProvideDefaults(): void
    {
        self::assertNull(ConfigurationKey::JoomlaRoot->defaultValue());
        self::assertSame('dist', ConfigurationKey::DistDirectory->defaultValue());
    }

    public function testWritesSitesAsWritten(): void
    {
        $file = new ConfigurationFile($this->temporaryDirectory . '/config.json', true);
        $sites = ['local' => ['joomla_root' => '/var/www/html', 'web_user' => 'www-data'], 'ddev' => ['joomla_root' => '/srv/ddev']];

        $file->write(['sites' => $sites, 'default_site' => 'local', 'dist_dir' => '/srv/dist']);

        self::assertSame(['dist_dir' => '/srv/dist', 'default_site' => 'local', 'sites' => $sites], $file->read());
    }

    public function testWritesSitesWithoutKeysAsObjects(): void
    {
        $file = new ConfigurationFile($this->temporaryDirectory . '/config.json', true);

        $file->write(['sites' => ['local' => [], 'ddev' => ['joomla_root' => '/srv/ddev']]]);

        self::assertJsonStringEqualsJsonFile($file->path, '{"sites": {"local": {}, "ddev": {"joomla_root": "/srv/ddev"}}}');
    }

    /**
     * @param array<string, string|non-empty-list<mixed>|non-empty-array<string, mixed>> $values
     */
    #[DataProvider('keysOutsideOfTheirScope')]
    public function testRejectsKeysOutsideOfTheirScope(bool $isGlobal, array $values, string $expectedMessage): void
    {
        $file = new ConfigurationFile($this->temporaryDirectory . '/config.json', $isGlobal);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage($expectedMessage);

        $file->write($values);
    }

    /**
     * @return iterable<string, array{bool, array<string, string|non-empty-list<mixed>|non-empty-array<string, mixed>>, string}>
     */
    public static function keysOutsideOfTheirScope(): iterable
    {
        yield 'site key in the project' => [false, ['web_user' => 'www-data'], 'web_user can no longer be set at the top level'];
        yield 'site key at the top level of the global file' => [
            true,
            ['joomla_root' => '/var/www'],
            'joomla_root can no longer be set at the top level',
        ];
        yield 'sites in the project' => [
            false,
            ['sites' => ['a' => ['joomla_root' => '/var/www']]],
            'sites can only be set in the global configuration',
        ];
        yield 'prepare in the global file' => [true, ['prepare' => [['run' => ['npm']]]], 'prepare can only be set in the project configuration'];
    }
}
