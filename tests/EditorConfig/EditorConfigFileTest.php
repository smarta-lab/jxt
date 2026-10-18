<?php

/**
 * @copyright  (C) 2026 Carsten Volkwein
 * @license    MIT License; see LICENSE
 */

declare(strict_types=1);

namespace SmartA\Jxt\Tests\EditorConfig;

use PHPUnit\Framework\TestCase;
use SmartA\Jxt\EditorConfig\EditorConfigException;
use SmartA\Jxt\EditorConfig\EditorConfigFile;

/**
 * Tests for reading `.editorconfig` files.
 *
 * Several cases follow the parser tests of editorconfig-core-test.
 */
final class EditorConfigFileTest extends TestCase
{
    public function testReadsRootAndSections(): void
    {
        $file = EditorConfigFile::parse("root = true\n\n[*]\nindent_style = space\n\n[*.md]\nmax_line_length = 80\n", '/p');

        self::assertTrue($file->root);
        self::assertCount(2, $file->sections);
        self::assertSame(['indent_style' => 'space'], $file->sections[0]->properties);
        self::assertSame(['max_line_length' => '80'], $file->sections[1]->properties);
        self::assertTrue($file->sections[1]->glob->matches('/p/docs/README.md'));
    }

    public function testRootIsFalseByDefaultAndCaseInsensitive(): void
    {
        self::assertFalse(EditorConfigFile::parse("[*]\nkey = value\n", '/p')->root);
        self::assertTrue(EditorConfigFile::parse("ROOT = TRUE\n", '/p')->root);
    }

    public function testRootInsideASectionIsAnOrdinaryProperty(): void
    {
        $file = EditorConfigFile::parse("[*]\nroot = true\n", '/p');

        self::assertFalse($file->root);
        self::assertSame(['root' => 'true'], $file->sections[0]->properties);
    }

    public function testIgnoresCommentsBlankLinesAndInvalidLines(): void
    {
        $content = "; comment\n# comment\n\n[*]\n; between\nkey1 = value1\nno equals sign\n  # indented comment\nkey2=value2\n";

        self::assertSame(
            ['key1' => 'value1', 'key2' => 'value2'],
            EditorConfigFile::parse($content, '/p')->sections[0]->properties,
        );
    }

    public function testKeepsSemicolonsAndOctothorpesInValues(): void
    {
        $content = "[*]\nkey1=value; not comment\nkey2=value # not comment\nkey3=value \\# not comment\n";

        self::assertSame(
            ['key1' => 'value; not comment', 'key2' => 'value # not comment', 'key3' => 'value \\# not comment'],
            EditorConfigFile::parse($content, '/p')->sections[0]->properties,
        );
    }

    public function testTrimsWhitespace(): void
    {
        $content = "  [test.c]  \n  key  =   value with whitespace inside  \nke y=value\nempty=  \n";

        self::assertSame(
            ['key' => 'value with whitespace inside', 'ke y' => 'value', 'empty' => ''],
            EditorConfigFile::parse($content, '/p')->sections[0]->properties,
        );
    }

    public function testKeepsWhitespaceInsideSectionNames(): void
    {
        $file = EditorConfigFile::parse("[ test 7 ]\nkey=value\n", '/p');

        self::assertTrue($file->sections[0]->glob->matches('/p/ test 7 '));
    }

    public function testSplitsAtTheFirstEqualsSign(): void
    {
        self::assertSame(['key' => 'a=b'], EditorConfigFile::parse("[*]\nkey = a=b\n", '/p')->sections[0]->properties);
    }

    public function testLowercasesNamesAndValuesOfKnownProperties(): void
    {
        $content = "[*]\nIndent_Style = Space\nEND_OF_LINE = CRLF\ninsert_final_newline = TRUE\n"
            . "trim_trailing_whitespace = False\ncharset = UTF-8\nindent_size = TAB\nTestProperty = TestValue\n";

        self::assertSame(
            [
                'indent_style' => 'space',
                'end_of_line' => 'crlf',
                'insert_final_newline' => 'true',
                'trim_trailing_whitespace' => 'false',
                'charset' => 'utf-8',
                'indent_size' => 'tab',
                'testproperty' => 'TestValue',
            ],
            EditorConfigFile::parse($content, '/p')->sections[0]->properties,
        );
    }

    public function testLaterPairsInASectionWin(): void
    {
        self::assertSame(['key' => 'b'], EditorConfigFile::parse("[*]\nkey = a\nkey = b\n", '/p')->sections[0]->properties);
    }

    public function testHandlesByteOrderMarkAndLineEndings(): void
    {
        $file = EditorConfigFile::parse("\u{FEFF}root = true\r\n[*]\r\nkey = value\rother = x\r\n", '/p');

        self::assertTrue($file->root);
        self::assertSame(['key' => 'value', 'other' => 'x'], $file->sections[0]->properties);
    }

    public function testEmptyFile(): void
    {
        $file = EditorConfigFile::parse('', '/p');

        self::assertFalse($file->root);
        self::assertSame([], $file->sections);
    }

    public function testLoadThrowsForUnreadableFile(): void
    {
        $this->expectException(EditorConfigException::class);

        EditorConfigFile::load(__DIR__ . '/does-not-exist/.editorconfig');
    }
}
