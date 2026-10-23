<?php

/**
 * @copyright  (C) 2026 Carsten Volkwein
 * @license    MIT License; see LICENSE
 */

declare(strict_types=1);

namespace SmartA\Jxt\Tests\Install;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SmartA\Jxt\Install\HtmlToText;

/**
 * Tests for converting HTML from install scripts into plain text for the console.
 */
final class HtmlToTextTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function documents(): iterable
    {
        yield 'empty' => ['', ''];
        yield 'plain text' => ["  Folder\n  created  ", 'Folder created'];
        yield 'blocks without margin' => [
            '<div><b><span style="color:#009933">Folder /images/phocacart created!</span></b></div>'
                . '<div>Folder /images/phocacartdownload created!</div>',
            "Folder /images/phocacart created!\nFolder /images/phocacartdownload created!",
        ];
        yield 'line breaks' => ["First<br>Second <br/> Third<br><br>Fifth<br>", "First\nSecond\nThird\n\nFifth"];
        yield 'paragraphs' => ['<p>One</p><p>Two</p>Three', "One\n\nTwo\n\nThree"];
        yield 'headings' => [
            '<h1>Phoca Cart</h1><p>Installed.</p><h3>Next <em>steps</em></h3><div>Configure it.</div>',
            "Phoca Cart\n==========\n\nInstalled.\n\nNext steps\n----------\n\nConfigure it.",
        ];
        yield 'entities and non-breaking spaces' => ['Tom &amp; Jerry &lt;3&gt; 6.1.9&nbsp;GA', 'Tom & Jerry <3> 6.1.9 GA'];
        yield 'invisible content' => [
            '<style>.x{}</style><script>alert(1)</script><img src="logo.png" alt="Logo"><!-- note -->Visible',
            'Visible',
        ];
        yield 'links' => [
            '<a href="https://example.org/docs">Docs</a>, <a href="https://example.org">https://example.org</a>, '
                . '<a href="index.php?option=com_x">Options</a>, <a href="#top">Top</a>, '
                . '<a href="https://example.org/shop"><img src="shop.png" alt="Shop"></a>.',
            'Docs (https://example.org/docs), https://example.org, Options, Top, .',
        ];
        yield 'lists' => [
            '<p>Changes:</p><ul><li>One</li><li>Two<ol start="3"><li>Three</li><li>Four</li></ol></li></ul><p>End</p>',
            "Changes:\n\n- One\n- Two\n  3. Three\n  4. Four\n\nEnd",
        ];
        yield 'table' => [
            '<table><tr><th>Extension</th><th>Status</th></tr><tr><td>mod_x</td><td><b>Installed</b></td></tr></table>',
            "Extension | Status\nmod_x | Installed",
        ];
        yield 'preformatted' => ["<pre>\n  a  b\n\tc\n</pre><p>d   e</p>", "  a  b\n\tc\n\nd e"];
        yield 'quote' => ['<blockquote><p>Quoted</p></blockquote>', '  Quoted'];
        yield 'rule' => ['<p>Above</p><hr><p>Below</p>', "Above\n\n" . str_repeat('-', 80) . "\n\nBelow"];
        yield 'unknown and broken markup' => ['<extension>x</extension> <div>y', "x\ny"];
    }

    #[DataProvider('documents')]
    public function testConvertsHtml(string $html, string $expected): void
    {
        self::assertSame($expected, (new HtmlToText())->convert($html));
    }

    public function testWrapsLinesAtTheWidth(): void
    {
        $text = (new HtmlToText(20))->convert(
            '<p>The quick brown fox jumps over the lazy dog.</p>'
                . '<ul><li>A list item that needs more than one line</li></ul>'
                . '<p>https://example.org/a/very/long/path/that/cannot/be/wrapped end</p>'
                . '<p>Keeps&nbsp;these&nbsp;words&nbsp;together</p>',
        );

        self::assertSame(
            "The quick brown fox\njumps over the lazy\ndog.\n\n"
                . "- A list item that\n  needs more than\n  one line\n\n"
                . "https://example.org/a/very/long/path/that/cannot/be/wrapped\nend\n\n"
                . 'Keeps these words together',
            $text,
        );
    }

    public function testUnderlinesWrappedHeadingsWithTheLongestLine(): void
    {
        self::assertSame(
            "A heading that is\nwrapped\n=================",
            (new HtmlToText(20))->convert('<h1>A heading that is wrapped</h1>'),
        );
    }
}
