<?php

declare(strict_types=1);

namespace SmartA\Jxt\Tests\EditorConfig;

use PHPUnit\Framework\TestCase;
use SmartA\Jxt\EditorConfig\EditorConfigException;
use SmartA\Jxt\EditorConfig\EditorConfigResolver;
use SmartA\Jxt\Tests\TemporaryDirectoryTrait;

/**
 * Tests for resolving the properties of a file from all applicable `.editorconfig` files.
 */
final class EditorConfigResolverTest extends TestCase
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

    public function testLaterSectionsWin(): void
    {
        $this->writeFile('.editorconfig', "root = true\n[*.b]\noption1 = a\noption2 = a\n[b.b]\noption2 = b\n[*.b]\noption1 = c\n");

        self::assertSame(['option1' => 'c', 'option2' => 'b'], $this->resolve('b.b'));
    }

    public function testCloserFilesWinOverParentDirectories(): void
    {
        $this->writeFile('.editorconfig', "root = true\n[*]\nkey = parent\nparent_only = yes\n");
        $this->writeFile('sub/.editorconfig', "[*]\nkey = child\n");

        self::assertSame(['key' => 'child', 'parent_only' => 'yes'], $this->resolve('sub/file.txt'));
        self::assertSame(['key' => 'parent', 'parent_only' => 'yes'], $this->resolve('file.txt'));
    }

    public function testStopsAtRoot(): void
    {
        $this->writeFile('.editorconfig', "root = true\n[*]\nouter = yes\n");
        $this->writeFile('project/.editorconfig', "root = true\n[*]\ninner = yes\n");

        self::assertSame(['inner' => 'yes'], $this->resolve('project/file.txt'));
    }

    public function testSectionsAreRelativeToTheirFile(): void
    {
        $this->writeFile('.editorconfig', "root = true\n[sub/*.txt]\nfrom_root = yes\n");
        $this->writeFile('sub/.editorconfig', "[sub/*.txt]\nfrom_sub = yes\n");

        self::assertSame(['from_root' => 'yes'], $this->resolve('sub/file.txt'));
        self::assertSame(['from_sub' => 'yes'], $this->resolve('sub/sub/file.txt'));
    }

    public function testUnsetRemovesProperties(): void
    {
        $this->writeFile('.editorconfig', implode("\n", [
            'root = true',
            '[*]',
            'charset = utf-8',
            'end_of_line = lf',
            '[media/{css/leaflet.css,js/leaflet.js}]',
            'charset = unset',
            'end_of_line = UNSET',
            '[media/js/leaflet.js]',
            'charset = latin1',
        ]) . "\n");

        self::assertSame(['charset' => 'utf-8', 'end_of_line' => 'lf'], $this->resolve('media/css/site.css'));
        self::assertSame([], $this->resolve('media/css/leaflet.css'));
        self::assertSame(['charset' => 'latin1'], $this->resolve('media/js/leaflet.js'));
    }

    public function testWithoutEditorConfigThereAreNoProperties(): void
    {
        $this->writeFile('project/file.txt');

        self::assertSame([], (new EditorConfigResolver('.editorconfig-test'))->properties($this->directory . '/project/file.txt'));
    }

    public function testDirectoryWithSpecialCharacters(): void
    {
        $this->writeFile('path_with_special_[chars/.editorconfig', "root = true\n[*.a]\nkey = value\n");

        self::assertSame(['key' => 'value'], $this->resolve('path_with_special_[chars/test.a'));
    }

    public function testLeadingSlashAnchorsToTheDirectory(): void
    {
        $this->writeFile('.editorconfig', implode("\n", [
            'root = true',
            '[/test1/file.{java,js,rb}]',
            'key1 = val1',
            '[test1/file.{java,js,rb}]',
            'key2 = val2',
            '[*.{java,js,rb}]',
            'key3 = val3',
        ]) . "\n");

        self::assertSame(['key1' => 'val1', 'key2' => 'val2', 'key3' => 'val3'], $this->resolve('test1/file.java'));
    }

    public function testRejectsRelativePaths(): void
    {
        $this->expectException(EditorConfigException::class);

        (new EditorConfigResolver())->properties('relative/file.txt');
    }

    /**
     * Returns the properties of a file relative to the temporary directory.
     *
     * @return array<string, string>
     */
    private function resolve(string $file): array
    {
        return (new EditorConfigResolver())->properties($this->directory . '/' . $file);
    }
}
