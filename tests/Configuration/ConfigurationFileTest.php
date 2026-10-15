<?php

declare(strict_types=1);

namespace SmartA\Jxt\Tests\Configuration;

use PHPUnit\Framework\TestCase;
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

        $file->write(['file_mode' => '0664', 'web_user' => null, 'joomla_root' => '/var/www/html']);

        self::assertSame(
            "{\n    \"joomla_root\": \"/var/www/html\",\n    \"web_user\": null,\n    \"file_mode\": \"0664\"\n}\n",
            file_get_contents($file->path),
        );
        self::assertSame(['joomla_root' => '/var/www/html', 'web_user' => null, 'file_mode' => '0664'], $file->read());
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

    public function testWritesEmptyObject(): void
    {
        $file = new ConfigurationFile($this->temporaryDirectory . '/config.json');

        $file->write([]);

        self::assertSame("{}\n", file_get_contents($file->path));
    }

    public function testKeysProvideDefaults(): void
    {
        self::assertNull(ConfigurationKey::JoomlaRoot->defaultValue());
        self::assertSame('dist', ConfigurationKey::DistDirectory->defaultValue());
        self::assertSame('0755', ConfigurationKey::DirectoryMode->defaultValue());
        self::assertSame('0644', ConfigurationKey::FileMode->defaultValue());
    }
}
