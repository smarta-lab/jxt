<?php

declare(strict_types=1);

namespace SmartA\Jxt\Tests\Manifest;

use PHPUnit\Framework\TestCase;
use SmartA\Jxt\Manifest\ManifestException;
use SmartA\Jxt\Manifest\ManifestLocator;
use SmartA\Jxt\Tests\TemporaryDirectoryTrait;

/**
 * Tests for locating the manifest in the project directory.
 */
final class ManifestLocatorTest extends TestCase
{
    use TemporaryDirectoryTrait;

    private string $directory;

    #[\Override]
    protected function setUp(): void
    {
        $this->directory = $this->createTemporaryDirectory();
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->removeTemporaryDirectory();
    }

    public function testFindsManifestAndIgnoresOtherXmlFiles(): void
    {
        $this->writeFile('phpunit.xml', '<phpunit/>');
        $this->writeFile('phpcs.xml', '<ruleset name="x"/>');
        $this->writeFile('broken.xml', 'not xml');
        $this->writeFile('mod_x.xml', '<extension type="module"><name>mod_x</name></extension>');

        self::assertSame($this->directory . '/mod_x.xml', (new ManifestLocator())->locate($this->directory));
    }

    public function testFailsWithoutManifest(): void
    {
        $this->writeFile('phpunit.xml', '<phpunit/>');

        $this->expectException(ManifestException::class);
        $this->expectExceptionMessageIsOrContains('No Joomla manifest');

        (new ManifestLocator())->locate($this->directory);
    }

    public function testFailsWithSeveralManifests(): void
    {
        $this->writeFile('mod_a.xml', '<extension type="module"><name>mod_a</name></extension>');
        $this->writeFile('mod_b.xml', '<extension type="module"><name>mod_b</name></extension>');

        $this->expectException(ManifestException::class);
        $this->expectExceptionMessageMatches('/^Several Joomla manifests found in .+: mod_a\.xml, mod_b\.xml\.$/');

        (new ManifestLocator())->locate($this->directory);
    }
}
