<?php

declare(strict_types=1);

namespace SmartA\Jxt\Tests\Check\Checker;

use PHPUnit\Framework\TestCase;
use SmartA\Jxt\Check\Checker\EditorConfigChecker;
use SmartA\Jxt\EditorConfig\EditorConfigResolver;
use SmartA\Jxt\Tests\Check\CheckProjectTrait;

/**
 * Tests for checking text files against their `.editorconfig` properties.
 */
final class EditorConfigCheckerTest extends TestCase
{
    use CheckProjectTrait;

    private const string EDITORCONFIG = <<<'INI'
        root = true

        [*]
        end_of_line = lf
        indent_style = space
        insert_final_newline = true
        trim_trailing_whitespace = true
        charset = utf-8

        [*.md]
        trim_trailing_whitespace = false
        max_line_length = 20

        [*.mk]
        indent_style = tab

        [crlf.txt]
        end_of_line = crlf

        [vendor/**]
        end_of_line = unset
        indent_style = unset
        insert_final_newline = unset
        trim_trailing_whitespace = unset
        charset = unset
        INI;

    #[\Override]
    protected function setUp(): void
    {
        $this->createProject();
        $this->writeFile('.editorconfig', self::EDITORCONFIG . "\n");
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->removeTemporaryDirectory();
    }

    public function testCleanFilesHaveNoFindings(): void
    {
        $this->writeFile('tmpl/default.php', "<?php\n\nif (true) {\n    echo 'a';\n}\n");
        $this->writeFile('README.md', "# A long heading that is fine\n\nShort text.  \n\n```\na very long code line inside a block\n```\n");
        $this->writeFile('rules.mk', "all:\n\techo a\n\t * comment\n");
        $this->writeFile('crlf.txt', "a\r\nb\r\n");
        $this->writeFile('vendor/lib.js', "\tx  \r\n");

        self::assertSame([], $this->findings(new EditorConfigChecker()));
    }

    public function testReportsViolations(): void
    {
        $this->writeFile('tmpl/default.php', "<?php\r\n\tif (true) {  \r\n\t\techo 'a';\r\n}");
        $this->writeFile('README.md', "Text that is too long for twenty characters.\n");
        $this->writeFile('rules.mk', "all:\n    echo a\n");
        $this->writeFile('crlf.txt', "a\nb\r\n");
        $this->writeFile('latin1.txt', "Gr\xFC\xDFe\n");
        $this->writeFile('bom.txt', "\u{FEFF}text\n");

        self::assertSame([
            'WARN editorconfig/max-line-length: README.md: longer than 20 characters in 1 line(s), first: line 1',
            'ERROR editorconfig/charset: bom.txt: starts with a byte order mark (charset = utf-8)',
            'ERROR editorconfig/end-of-line: crlf.txt: 1 line ending(s) other than CRLF, first: line 1',
            'ERROR editorconfig/charset: latin1.txt: not valid UTF-8 (charset = utf-8)',
            'ERROR editorconfig/indent-style: rules.mk: indentation not with tabs in 1 line(s), first: line 2',
            'ERROR editorconfig/end-of-line: tmpl/default.php: 3 line ending(s) other than LF, first: line 1',
            'ERROR editorconfig/final-newline: tmpl/default.php: no newline at end of file',
            'ERROR editorconfig/trailing-whitespace: tmpl/default.php: trailing whitespace in 1 line(s), first: line 2',
            'ERROR editorconfig/indent-style: tmpl/default.php: indentation not with spaces in 2 line(s), first: line 2',
        ], $this->findings(new EditorConfigChecker()));
    }

    public function testSkipsBinaryFiles(): void
    {
        $this->writeFile('media/images/icon.png', "\x89PNG\0\0\tdata");

        self::assertSame([], $this->findings(new EditorConfigChecker()));
    }

    public function testWithoutGitSkipsHiddenFilesInTheProjectDirectory(): void
    {
        $this->writeFile('.envrc', 'export A=1');

        self::assertSame([], $this->findings(new EditorConfigChecker()));
    }

    public function testWithGitChecksTrackedAndUntrackedFilesButNotIgnoredOnes(): void
    {
        $this->writeFile('.gitignore', "/ignored.txt\n");
        $this->writeFile('.example-rc', 'export A=1');
        $this->writeFile('ignored.txt', 'no newline');
        $this->initGit();
        $this->writeFile('untracked.txt', 'no newline');

        self::assertSame([
            'ERROR editorconfig/final-newline: .example-rc: no newline at end of file',
            'ERROR editorconfig/final-newline: untracked.txt: no newline at end of file',
        ], $this->findings(new EditorConfigChecker()));
    }

    public function testWithoutEditorConfigOnlyANoticeIsReported(): void
    {
        $this->writeFile('README.md', "\tno newline");

        self::assertSame(
            ['INFO editorconfig/missing: no .editorconfig found, whitespace checks skipped'],
            $this->findings(new EditorConfigChecker(new EditorConfigResolver('.editorconfig-none'))),
        );
    }
}
