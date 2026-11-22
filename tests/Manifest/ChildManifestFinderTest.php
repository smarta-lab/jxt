<?php

/**
 * @copyright  (C) 2026 Carsten Volkwein
 * @license    MIT License; see LICENSE
 */

declare(strict_types=1);

namespace SmartA\Jxt\Tests\Manifest;

use PHPUnit\Framework\TestCase;
use SmartA\Jxt\Manifest\ChildManifest;
use SmartA\Jxt\Manifest\ChildManifestFinder;
use SmartA\Jxt\Tests\TemporaryDirectoryTrait;

/**
 * Tests for finding the manifests of the extensions of a package in its subdirectories.
 */
final class ChildManifestFinderTest extends TestCase
{
    use TemporaryDirectoryTrait;

    private const string PLUGIN = '<extension type="plugin" group="system"><name>x</name><files><folder plugin="x">src</folder></files></extension>';

    #[\Override]
    protected function setUp(): void
    {
        $this->createTemporaryDirectory();
        $this->writeFile('pkg_x.xml', '<extension type="package"><name>x</name><packagename>x</packagename></extension>');
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->removeTemporaryDirectory();
    }

    public function testFindsManifestsInSubdirectoriesUpToThreeLevels(): void
    {
        $this->writeFile('component/x.xml', '<extension type="component"><name>x</name></extension>');
        $this->writeFile('component/administrator/forms/item.xml', '<form/>');
        $this->writeFile('extensions/plugins/x/x.xml', self::PLUGIN);
        $this->writeFile('a/b/c/d/mod_deep.xml', '<extension type="module"><name>mod_deep</name><element>mod_deep</element></extension>');

        $search = (new ChildManifestFinder())->find($this->temporaryDirectory);

        self::assertSame(['component/x.xml', 'extensions/plugins/x/x.xml'], $this->paths($search->manifests));
        self::assertSame('com_x', $search->manifests[0]->manifest->element);
        self::assertSame($this->temporaryDirectory . '/component', $search->manifests[0]->directory());
        self::assertSame([], $search->invalid);
    }

    public function testSkipsBuildOutputDependenciesTestsAndHiddenDirectories(): void
    {
        foreach (['dist', 'build', 'vendor/acme', 'node_modules/x', 'tests/fixtures', '.git/x'] as $directory) {
            $this->writeFile($directory . '/x.xml', self::PLUGIN);
        }

        $search = (new ChildManifestFinder())->find($this->temporaryDirectory, [$this->temporaryDirectory . '/build']);

        self::assertSame([], $search->manifests);
    }

    public function testReportsManifestsThatCannotBeParsed(): void
    {
        $this->writeFile('files/x.xml', '<extension type="file"><name>x</name></extension>');

        $search = (new ChildManifestFinder())->find($this->temporaryDirectory);

        self::assertSame([], $search->manifests);
        self::assertSame(['files/x.xml'], array_keys($search->invalid));
        self::assertStringContainsString('"file" is not supported', $search->invalid['files/x.xml']);
    }

    /**
     * @param list<ChildManifest> $manifests
     *
     * @return list<string>
     */
    private function paths(array $manifests): array
    {
        return array_map(static fn (ChildManifest $child): string => $child->relativePath, $manifests);
    }
}
