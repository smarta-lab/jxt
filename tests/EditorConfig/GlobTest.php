<?php

declare(strict_types=1);

namespace SmartA\Jxt\Tests\EditorConfig;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SmartA\Jxt\EditorConfig\Glob;

/**
 * Tests for matching section names against file paths.
 *
 * Most cases are taken from the glob tests of editorconfig-core-test
 * (https://github.com/editorconfig/editorconfig-core-test, BSD-2-Clause).
 */
final class GlobTest extends TestCase
{
    private const string DIRECTORY = '/project';

    #[DataProvider('starCases')]
    #[DataProvider('questionMarkCases')]
    #[DataProvider('bracketCases')]
    #[DataProvider('braceCases')]
    #[DataProvider('numericRangeCases')]
    #[DataProvider('doubleStarCases')]
    #[DataProvider('pathCases')]
    public function testMatches(string $section, string $file, bool $expected): void
    {
        self::assertSame($expected, Glob::fromSection($section, self::DIRECTORY)->matches(self::DIRECTORY . '/' . $file));
    }

    public function testDirectoryIsMatchedLiterally(): void
    {
        $directory = '/path_with_special_[chars';

        self::assertTrue(Glob::fromSection('*.a', $directory)->matches($directory . '/test.a'));
        self::assertFalse(Glob::fromSection('*.a', $directory)->matches('/path_with_special_c/test.a'));
    }

    public function testDoesNotMatchFilesOutsideTheDirectory(): void
    {
        self::assertFalse(Glob::fromSection('*', self::DIRECTORY)->matches('/other/file.txt'));
        self::assertFalse(Glob::fromSection('*', self::DIRECTORY)->matches('/project2/file.txt'));
    }

    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function starCases(): iterable
    {
        yield 'star: single character' => ['a*e.c', 'ace.c', true];
        yield 'star: zero characters' => ['a*e.c', 'ae.c', true];
        yield 'star: multiple characters' => ['a*e.c', 'abcde.c', true];
        yield 'star: not over slash' => ['a*e.c', 'a/e.c', false];
        yield 'star: after slash' => ['Bar/*', 'Bar/foo.txt', true];
        yield 'star: dot file after slash' => ['Bar/*', 'Bar/.editorconfig', true];
        yield 'star: dot file' => ['*', '.editorconfig', true];
        yield 'star: slash anchors to the directory' => ['Bar/*', 'bat/Bar/foo.txt', false];
        yield 'star: without slash in subdirectory' => ['*.md', 'docs/sub/README.md', true];
        yield 'star: single star does not cross directories' => ['a/*.css', 'a/b/x.css', false];
    }

    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function questionMarkCases(): iterable
    {
        yield 'question mark: single character' => ['som?.c', 'some.c', true];
        yield 'question mark: not zero characters' => ['som?.c', 'som.c', false];
        yield 'question mark: not multiple characters' => ['som?.c', 'something.c', false];
        yield 'question mark: not slash' => ['som?.c', 'som/.c', false];
        yield 'question mark: multibyte character' => ['?.txt', 'ä.txt', true];
    }

    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function bracketCases(): iterable
    {
        yield 'brackets: choice' => ['[ab].a', 'a.a', true];
        yield 'brackets: choice negative' => ['[ab].a', 'c.a', false];
        yield 'brackets: negated choice' => ['[!ab].b', 'c.b', true];
        yield 'brackets: negated choice negative' => ['[!ab].b', 'a.b', false];
        yield 'brackets: range' => ['[d-g].c', 'f.c', true];
        yield 'brackets: range negative' => ['[d-g].c', 'h.c', false];
        yield 'brackets: negated range' => ['[!d-g].d', 'h.d', true];
        yield 'brackets: negated range negative' => ['[!d-g].d', 'f.d', false];
        yield 'brackets: range and choice' => ['[abd-g].e', 'e.e', true];
        yield 'brackets: choice with dash' => ['[-ab].f', '-.f', true];
        yield 'brackets: escaped close bracket inside' => ['[\]ab].g', '].g', true];
        yield 'brackets: close bracket outside' => ['[ab]].g', 'b].g', true];
        yield 'brackets: negated escaped close bracket inside' => ['[!\]ab].g', 'c.g', true];
        yield 'brackets: negated close bracket outside' => ['[!ab]].g', 'c].g', true];
        yield 'brackets: slash inside is literal 1' => ['ab[e/]cd.i', 'ab/cd.i', false];
        yield 'brackets: slash inside is literal 2' => ['ab[e/]cd.i', 'abecd.i', false];
        yield 'brackets: slash inside is literal 3' => ['ab[e/]cd.i', 'ab[e/]cd.i', true];
        yield 'brackets: slash after half-open bracket' => ['ab[/c', 'ab[/c', true];
        yield 'brackets: unclosed bracket is literal' => ['[ab', '[ab', true];
        yield 'brackets: star inside brackets is literal' => ['[*].txt', '*.txt', true];
        yield 'brackets: star inside brackets is no wildcard' => ['[*].txt', 'a.txt', false];
    }

    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function braceCases(): iterable
    {
        yield 'braces: word choice 1' => ['*.{py,js,html}', 'test.py', true];
        yield 'braces: word choice 2' => ['*.{py,js,html}', 'test.js', true];
        yield 'braces: word choice 3' => ['*.{py,js,html}', 'test.html', true];
        yield 'braces: word choice 4' => ['*.{py,js,html}', 'test.pyc', false];
        yield 'braces: single choice' => ['{single}.b', '{single}.b', true];
        yield 'braces: single choice negative' => ['{single}.b', '.b', false];
        yield 'braces: empty choice' => ['{}.c', '{}.c', true];
        yield 'braces: empty choice negative' => ['{}.c', '.c', false];
        yield 'braces: empty word 1' => ['a{b,c,}.d', 'a.d', true];
        yield 'braces: empty word 2' => ['a{b,c,}.d', 'ab.d', true];
        yield 'braces: empty word 3' => ['a{b,c,}.d', 'ac.d', true];
        yield 'braces: empty word 4' => ['a{b,c,}.d', 'a,.d', false];
        yield 'braces: empty words 1' => ['a{,b,,c,}.e', 'a.e', true];
        yield 'braces: empty words 2' => ['a{,b,,c,}.e', 'ab.e', true];
        yield 'braces: empty words 3' => ['a{,b,,c,}.e', 'ac.e', true];
        yield 'braces: empty words 4' => ['a{,b,,c,}.e', 'a,.e', false];
        yield 'braces: no closing brace' => ['{.f', '{.f', true];
        yield 'braces: no closing brace negative' => ['{.f', '.f', false];
        yield 'braces: nested 1' => ['{word,{also},this}.g', 'word,this}.g', false];
        yield 'braces: nested 2' => ['{word,{also},this}.g', '{also,this}.g', false];
        yield 'braces: nested 3' => ['{word,{also},this}.g', 'word.g', true];
        yield 'braces: nested 4' => ['{word,{also},this}.g', '{also}.g', true];
        yield 'braces: nested 5' => ['{word,{also},this}.g', 'this.g', true];
        yield 'braces: nested at start 1' => ['{{a,b},c}.k', '{{a,b},c}.k', false];
        yield 'braces: nested at start 2' => ['{{a,b},c}.k', '{a,b}.k', false];
        yield 'braces: nested at start 3' => ['{{a,b},c}.k', 'a.k', true];
        yield 'braces: nested at start 4' => ['{{a,b},c}.k', 'b.k', true];
        yield 'braces: nested at start 5' => ['{{a,b},c}.k', 'c.k', true];
        yield 'braces: nested at end 1' => ['{a,{b,c}}.l', '{a,{b,c}}.l', false];
        yield 'braces: nested at end 2' => ['{a,{b,c}}.l', '{b,c}.l', false];
        yield 'braces: nested at end 3' => ['{a,{b,c}}.l', 'a.l', true];
        yield 'braces: nested at end 4' => ['{a,{b,c}}.l', 'b.l', true];
        yield 'braces: nested at end 5' => ['{a,{b,c}}.l', 'c.l', true];
        yield 'braces: closing inside beginning' => ['{},b}.h', '{},b}.h', true];
        yield 'braces: unmatched 1' => ['{{,b,c{d}.i', '{{,b,c{d}.i', true];
        yield 'braces: unmatched 2' => ['{{,b,c{d}.i', '{.i', false];
        yield 'braces: unmatched 3' => ['{{,b,c{d}.i', 'b.i', false];
        yield 'braces: unmatched 4' => ['{{,b,c{d}.i', 'c{d.i', false];
        yield 'braces: unmatched 5' => ['{{,b,c{d}.i', '.i', false];
        yield 'braces: escaped comma 1' => ['{a\,b,cd}.txt', 'a,b.txt', true];
        yield 'braces: escaped comma 2' => ['{a\,b,cd}.txt', 'a.txt', false];
        yield 'braces: escaped comma 3' => ['{a\,b,cd}.txt', 'cd.txt', true];
        yield 'braces: escaped closing brace 1' => ['{e,\},f}.txt', 'e.txt', true];
        yield 'braces: escaped closing brace 2' => ['{e,\},f}.txt', '}.txt', true];
        yield 'braces: escaped closing brace 3' => ['{e,\},f}.txt', 'f.txt', true];
        yield 'braces: escaped backslash 1' => ['{g,\\\\,i}.txt', 'g.txt', true];
        yield 'braces: escaped backslash 2' => ['{g,\\\\,i}.txt', '\\.txt', true];
        yield 'braces: escaped backslash 3' => ['{g,\\\\,i}.txt', 'i.txt', true];
        yield 'braces: patterns nested 1' => ['{some,a{*c,b}[ef]}.j', 'some.j', true];
        yield 'braces: patterns nested 2' => ['{some,a{*c,b}[ef]}.j', 'abe.j', true];
        yield 'braces: patterns nested 3' => ['{some,a{*c,b}[ef]}.j', 'abf.j', true];
        yield 'braces: patterns nested 4' => ['{some,a{*c,b}[ef]}.j', 'abg.j', false];
        yield 'braces: patterns nested 5' => ['{some,a{*c,b}[ef]}.j', 'ace.j', true];
        yield 'braces: patterns nested 6' => ['{some,a{*c,b}[ef]}.j', 'acf.j', true];
        yield 'braces: patterns nested 7' => ['{some,a{*c,b}[ef]}.j', 'acg.j', false];
        yield 'braces: patterns nested 8' => ['{some,a{*c,b}[ef]}.j', 'abce.j', true];
        yield 'braces: patterns nested 9' => ['{some,a{*c,b}[ef]}.j', 'abcf.j', true];
        yield 'braces: patterns nested 10' => ['{some,a{*c,b}[ef]}.j', 'abcg.j', false];
        yield 'braces: patterns nested 11' => ['{some,a{*c,b}[ef]}.j', 'ae.j', false];
        yield 'braces: patterns nested 12' => ['{some,a{*c,b}[ef]}.j', '.j', false];
        yield 'braces: list with slashes' => ['media/{css/a.css,js/b.js}', 'media/js/b.js', true];
        yield 'braces: list with slashes negative' => ['media/{css/a.css,js/b.js}', 'media/js/a.css', false];
    }

    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function numericRangeCases(): iterable
    {
        yield 'numeric range: below' => ['{3..120}', '1', false];
        yield 'numeric range: lower bound' => ['{3..120}', '3', true];
        yield 'numeric range: inside 1' => ['{3..120}', '15', true];
        yield 'numeric range: inside 2' => ['{3..120}', '60', true];
        yield 'numeric range: not a number' => ['{3..120}', '5a', false];
        yield 'numeric range: upper bound' => ['{3..120}', '120', true];
        yield 'numeric range: above' => ['{3..120}', '121', false];
        yield 'numeric range: leading zero' => ['{3..120}', '060', false];
        yield 'numeric range: negative bounds' => ['f{-5..-1}.txt', 'f-3.txt', true];
        yield 'numeric range: in a file name' => ['f{1..3}.txt', 'f2.txt', true];
        yield 'numeric range: in a file name negative' => ['f{1..3}.txt', 'f5.txt', false];
        yield 'numeric range: alphabetical is literal' => ['{aardvark..antelope}', '{aardvark..antelope}', true];
        yield 'numeric range: alphabetical is no range 1' => ['{aardvark..antelope}', 'a', false];
        yield 'numeric range: alphabetical is no range 2' => ['{aardvark..antelope}', 'aardvark', false];
        yield 'numeric range: alphabetical is no range 3' => ['{aardvark..antelope}', 'agreement', false];
        yield 'numeric range: alphabetical is no range 4' => ['{aardvark..antelope}', 'antelope', false];
    }

    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function doubleStarCases(): iterable
    {
        yield 'double star: a 1' => ['a**z.c', 'a/z.c', true];
        yield 'double star: a 2' => ['a**z.c', 'amnz.c', true];
        yield 'double star: a 3' => ['a**z.c', 'am/nz.c', true];
        yield 'double star: a 4' => ['a**z.c', 'a/mnz.c', true];
        yield 'double star: a 5' => ['a**z.c', 'amn/z.c', true];
        yield 'double star: a 6' => ['a**z.c', 'a/mn/z.c', true];
        yield 'double star: b 1' => ['b/**z.c', 'b/z.c', true];
        yield 'double star: b 2' => ['b/**z.c', 'b/mnz.c', true];
        yield 'double star: b 3' => ['b/**z.c', 'b/mn/z.c', true];
        yield 'double star: b 4' => ['b/**z.c', 'bmnz.c', false];
        yield 'double star: b 5' => ['b/**z.c', 'bm/nz.c', false];
        yield 'double star: b 6' => ['b/**z.c', 'bmn/z.c', false];
        yield 'double star: c 1' => ['c**/z.c', 'c/z.c', true];
        yield 'double star: c 2' => ['c**/z.c', 'cmn/z.c', true];
        yield 'double star: c 3' => ['c**/z.c', 'c/mn/z.c', true];
        yield 'double star: c 4' => ['c**/z.c', 'cmnz.c', false];
        yield 'double star: c 5' => ['c**/z.c', 'cm/nz.c', false];
        yield 'double star: c 6' => ['c**/z.c', 'c/mnz.c', false];
        yield 'double star: d 1' => ['d/**/z.c', 'd/z.c', true];
        yield 'double star: d 2' => ['d/**/z.c', 'd/mn/z.c', true];
        yield 'double star: d 3' => ['d/**/z.c', 'dmnz.c', false];
        yield 'double star: d 4' => ['d/**/z.c', 'dm/nz.c', false];
        yield 'double star: d 5' => ['d/**/z.c', 'd/mnz.c', false];
        yield 'double star: d 6' => ['d/**/z.c', 'dmn/z.c', false];
        yield 'double star: trailing' => ['tests/fixtures/**', 'tests/fixtures/a/b.csv', true];
    }

    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function pathCases(): iterable
    {
        yield 'path: utf-8 characters' => ['中文.txt', '中文.txt', true];
        yield 'path: path separator' => ['path/separator', 'path/separator', true];
        yield 'path: nested path separator' => ['path/separator', 'nested/path/separator', false];
        yield 'path: leading slash' => ['/top/of/path', 'top/of/path', true];
        yield 'path: leading slash negative' => ['/top/of/path', 'not/top/of/path', false];
        yield 'path: escaped character is literal' => ['windows\separator', 'windows/separator', false];
        yield 'path: escaped backslash' => ['windows\\\\separator2', 'windows\\separator2', true];
        yield 'path: escaped backslash is no separator' => ['windows\\\\separator2', 'windows/separator2', false];
        yield 'path: escaped semicolon' => ['test\;.c', 'test;.c', true];
        yield 'path: escaped octothorpe' => ['test\#.c', 'test#.c', true];
        yield 'path: spaces' => [' test 7 ', ' test 7 ', true];
        yield 'path: leading slash with braces' => ['/test1/file.{java,js,rb}', 'test1/file.java', true];
        yield 'path: regex characters are literal' => ['a+b(c).txt', 'a+b(c).txt', true];
        yield 'path: dot is literal' => ['a.txt', 'abtxt', false];
    }
}
