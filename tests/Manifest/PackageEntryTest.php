<?php

/**
 * @copyright  (C) 2026 Carsten Volkwein
 * @license    MIT License; see LICENSE
 */

declare(strict_types=1);

namespace SmartA\Jxt\Tests\Manifest;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SmartA\Jxt\Manifest\Manifest;
use SmartA\Jxt\Manifest\ManifestParser;
use SmartA\Jxt\Manifest\PackageEntry;

/**
 * Tests for assigning the entries of a package manifest to the manifests of its extensions.
 */
final class PackageEntryTest extends TestCase
{
    #[DataProvider('matchingEntries')]
    public function testMatchesByTheAttributesJoomlaUsesForUninstalling(PackageEntry $entry, string $xml, bool $expected): void
    {
        self::assertSame($expected, $entry->matches(self::manifest($xml)));
    }

    /**
     * @return iterable<string, array{PackageEntry, string, bool}>
     */
    public static function matchingEntries(): iterable
    {
        $component = '<extension type="component"><name>Example</name></extension>';
        $plugin = '<extension type="plugin" group="system"><name>x</name><files><folder plugin="example">src</folder></files></extension>';
        $module = '<extension type="module" client="administrator"><name>mod_x</name><element>mod_x</element></extension>';

        yield 'component' => [new PackageEntry('a.zip', 'component', 'com_example', null, null), $component, true];
        yield 'component ignores client and group' => [
            new PackageEntry('a.zip', 'component', 'com_example', 'site', 'system'),
            $component,
            true,
        ];
        yield 'other id' => [new PackageEntry('a.zip', 'component', 'com_other', null, null), $component, false];
        yield 'other type' => [new PackageEntry('a.zip', 'module', 'com_example', null, null), $component, false];
        yield 'plugin' => [new PackageEntry('a.zip', 'plugin', 'example', null, 'system'), $plugin, true];
        yield 'plugin in other group' => [new PackageEntry('a.zip', 'plugin', 'example', null, 'content'), $plugin, false];
        yield 'plugin without group' => [new PackageEntry('a.zip', 'plugin', 'example', null, null), $plugin, false];
        yield 'module' => [new PackageEntry('a.zip', 'module', 'mod_x', 'administrator', null), $module, true];
        yield 'module of other client' => [new PackageEntry('a.zip', 'module', 'mod_x', 'site', null), $module, false];
        yield 'module without client' => [new PackageEntry('a.zip', 'module', 'mod_x', null, null), $module, false];
        yield 'entry without type and id' => [new PackageEntry('a.zip', null, null, null, null), $component, false];
    }

    public function testDescribesTheIdentityForMessages(): void
    {
        self::assertSame(
            'type="plugin" id="example" group="system"',
            (new PackageEntry('a.zip', 'plugin', 'example', null, 'system'))->identity(),
        );
        self::assertSame('type="module" id="mod_x" client="site"', (new PackageEntry('a', 'module', 'mod_x', 'site', null))->identity());
        self::assertSame('no type and id', (new PackageEntry('a.zip', null, null, null, null))->identity());
    }

    public function testRecognisesZipEntries(): void
    {
        self::assertTrue((new PackageEntry('dist/a.ZIP', null, null, null, null))->isZip());
        self::assertFalse((new PackageEntry('dist/mod_a', null, null, null, null))->isZip());
    }

    private static function manifest(string $xml): Manifest
    {
        return (new ManifestParser())->parse($xml, 'x.xml');
    }
}
