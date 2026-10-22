<?php

/**
 * @copyright  (C) 2026 Carsten Volkwein
 * @license    MIT License; see LICENSE
 */

declare(strict_types=1);

namespace SmartA\Jxt\Tests\Check;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SmartA\Jxt\Check\CommentStripper;

/**
 * Tests for removing comments from PHP, XML, JS and SQL files.
 */
final class CommentStripperTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, list<string>, list<string>}>
     */
    public static function files(): iterable
    {
        yield 'PHP' => [
            'default.php',
            "<?php\n// DROP1\n/* DROP2 */\n/** DROP3 */\n# DROP4\necho KEEP1, '// KEEP2', \"/* KEEP3 */\";\n?>\n<!-- KEEP4 -->\n",
            ['KEEP1', '// KEEP2', '/* KEEP3 */', '<!-- KEEP4 -->'],
            ['DROP1', 'DROP2', 'DROP3', 'DROP4'],
        ];
        yield 'XML' => [
            'form.xml',
            "<form>\n<!-- <field label=\"DROP1\"/>\n<field label=\"DROP2\"/> -->\n<field label=\"KEEP1\"/><![CDATA[KEEP2]]>\n</form>\n",
            ['KEEP1', 'KEEP2'],
            ['DROP1', 'DROP2'],
        ];
        yield 'JS' => [
            'site.js',
            "// DROP1\n/* DROP2\n */\nKEEP1; const url = 'https://example.org/KEEP2'; const s = \"/* KEEP3 */\"; const t = `// KEEP4`;\n",
            ['KEEP1', 'https://example.org/KEEP2', '/* KEEP3 */', '// KEEP4'],
            ['DROP1', 'DROP2'],
        ];
        yield 'SQL' => [
            'install.sql',
            "-- DROP1\n# DROP2\n/* DROP3 */\nINSERT INTO `#__t` VALUES ('KEEP1', 'it''s -- KEEP2', \"# KEEP3\");\nSELECT 1--KEEP4;\n",
            ['`#__t`', 'KEEP1', "it''s -- KEEP2", '# KEEP3', '1--KEEP4'],
            ['DROP1', 'DROP2', 'DROP3'],
        ];
    }

    /**
     * @param list<string> $kept
     * @param list<string> $removed
     */
    #[DataProvider('files')]
    public function testRemovesCommentsButKeepsStrings(string $path, string $content, array $kept, array $removed): void
    {
        $stripped = (new CommentStripper())->strip($path, $content);

        foreach ($kept as $text) {
            self::assertStringContainsString($text, $stripped);
        }

        foreach ($removed as $text) {
            self::assertStringNotContainsString($text, $stripped);
        }
    }

    public function testCommentDoesNotJoinNeighbouringWords(): void
    {
        self::assertSame('KEEP1 KEEP2', (new CommentStripper())->strip('site.js', 'KEEP1/* DROP1 */KEEP2'));
    }

    public function testUnclosedQuoteDoesNotHideLaterComments(): void
    {
        $stripped = (new CommentStripper())->strip('site.js', "const re = /'/;\n// DROP1\nText._('KEEP1');\n");

        self::assertStringNotContainsString('DROP1', $stripped);
        self::assertStringContainsString("'KEEP1'", $stripped);
    }

    public function testLeavesOtherFilesUnchanged(): void
    {
        self::assertSame('// KEEP1', (new CommentStripper())->strip('style.css', '// KEEP1'));
    }
}
