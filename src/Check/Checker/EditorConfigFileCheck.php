<?php

declare(strict_types=1);

namespace SmartA\Jxt\Check\Checker;

use SmartA\Jxt\Check\CheckName;
use SmartA\Jxt\Check\CheckReport;
use SmartA\Jxt\Check\Severity;

/**
 * Checks the content of one text file against its resolved `.editorconfig` properties.
 *
 * Line-based violations are reported once per file with their number and the first line.
 */
final readonly class EditorConfigFileCheck
{
    private const string BYTE_ORDER_MARK = "\u{FEFF}";

    /**
     * Patterns of line endings that violate `end_of_line`.
     */
    private const array WRONG_LINE_ENDINGS = [
        'lf' => '/\r/',
        'crlf' => '/(?<!\r)\n|\r(?!\n)/',
        'cr' => '/\n/',
    ];

    /**
     * Patterns of lines that violate `indent_style`. With tabs, two or more spaces at the start of the
     * indentation are a violation; a single space as in block comment continuations (` *`) is allowed.
     */
    private const array WRONG_INDENTATION = [
        'space' => '/^ *\t/',
        'tab' => '/^\t* {2,}/',
    ];

    /** @var list<string> */
    private array $lines;

    /**
     * @param string                $file       Path relative to the project directory
     * @param array<string, string> $properties Resolved EditorConfig properties of the file
     */
    public function __construct(
        private string $file,
        private string $content,
        private array $properties,
        private CheckReport $report,
    ) {
        $lines = preg_split('/\r\n|\r|\n/', $content) ?: [];

        if (preg_match('/[\r\n]$/', $content) === 1) {
            array_pop($lines);
        }

        $this->lines = $lines;
    }

    /**
     * Runs all checks for which the file has a property.
     */
    public function run(): void
    {
        $this->checkLineEndings();
        $this->checkFinalNewline();
        $this->checkLines(
            CheckName::EditorConfigTrailingWhitespace,
            Severity::Error,
            ($this->properties['trim_trailing_whitespace'] ?? '') === 'true',
            static fn (string $line): bool => preg_match('/[ \t]$/', $line) === 1,
            'trailing whitespace',
        );
        $this->checkIndentation();
        $this->checkMaxLineLength();
        $this->checkCharset();
    }

    /**
     * Checks `end_of_line`.
     */
    private function checkLineEndings(): void
    {
        $style = $this->properties['end_of_line'] ?? '';
        $pattern = self::WRONG_LINE_ENDINGS[$style] ?? null;

        if ($pattern === null) {
            return;
        }

        $count = (int) preg_match_all($pattern, $this->content, $matches, PREG_OFFSET_CAPTURE);

        if ($count > 0) {
            $line = substr_count(substr($this->content, 0, $matches[0][0][1]), "\n") + 1;
            $this->add(CheckName::EditorConfigEndOfLine, Severity::Error, sprintf(
                '%d line ending(s) other than %s, first: line %d',
                $count,
                strtoupper($style),
                $line,
            ), $line);
        }
    }

    /**
     * Checks `insert_final_newline`.
     */
    private function checkFinalNewline(): void
    {
        $expected = $this->properties['insert_final_newline'] ?? '';
        $hasNewline = preg_match('/[\r\n]$/', $this->content) === 1;

        if ($expected === 'true' && $this->content !== '' && !$hasNewline) {
            $this->add(CheckName::EditorConfigFinalNewline, Severity::Error, 'no newline at end of file');
        } elseif ($expected === 'false' && $hasNewline) {
            $this->add(CheckName::EditorConfigFinalNewline, Severity::Error, 'newline at end of file (insert_final_newline = false)');
        }
    }

    /**
     * Checks `indent_style`.
     */
    private function checkIndentation(): void
    {
        $style = $this->properties['indent_style'] ?? '';
        $pattern = self::WRONG_INDENTATION[$style] ?? null;

        $this->checkLines(
            CheckName::EditorConfigIndentStyle,
            Severity::Error,
            $pattern !== null,
            static fn (string $line): bool => $pattern !== null && preg_match($pattern, $line) === 1,
            sprintf('indentation not with %ss', $style),
        );
    }

    /**
     * Checks `max_line_length`; in Markdown, code blocks, tables, headings and lines with links are skipped.
     */
    private function checkMaxLineLength(): void
    {
        $maximum = $this->properties['max_line_length'] ?? '';

        if (preg_match('/^[1-9]\d*$/', $maximum) !== 1) {
            return;
        }

        $isMarkdown = preg_match('/\.(md|markdown)$/i', $this->file) === 1;
        $inCodeBlock = false;
        $long = [];

        foreach ($this->lines as $index => $line) {
            if ($isMarkdown && preg_match('/^\s*(```|~~~)/', $line) === 1) {
                $inCodeBlock = !$inCodeBlock;

                continue;
            }

            if (
                mb_strlen($line) > (int) $maximum
                && !($isMarkdown && ($inCodeBlock || $this->isUnwrappableMarkdown($line)))
            ) {
                $long[] = $index + 1;
            }
        }

        $this->addLines(CheckName::EditorConfigMaxLineLength, Severity::Warning, $long, sprintf('longer than %d characters', $maximum));
    }

    /**
     * Checks `charset` for UTF-8 with or without byte order mark; other charsets are not checked.
     */
    private function checkCharset(): void
    {
        $charset = $this->properties['charset'] ?? '';
        $hasBom = str_starts_with($this->content, self::BYTE_ORDER_MARK);

        if (!in_array($charset, ['utf-8', 'utf-8-bom'], true)) {
            return;
        }

        if (!mb_check_encoding($this->content, 'UTF-8')) {
            $this->add(CheckName::EditorConfigCharset, Severity::Error, sprintf('not valid UTF-8 (charset = %s)', $charset));
        } elseif ($charset === 'utf-8' && $hasBom) {
            $this->add(CheckName::EditorConfigCharset, Severity::Error, 'starts with a byte order mark (charset = utf-8)');
        } elseif ($charset === 'utf-8-bom' && !$hasBom) {
            $this->add(CheckName::EditorConfigCharset, Severity::Error, 'has no byte order mark (charset = utf-8-bom)');
        }
    }

    /**
     * Reports the lines for which `$violates` is true, if the check applies.
     *
     * @param callable(string): bool $violates
     */
    private function checkLines(CheckName $check, Severity $severity, bool $applies, callable $violates, string $problem): void
    {
        if (!$applies) {
            return;
        }

        $lines = [];

        foreach ($this->lines as $index => $line) {
            if ($violates($line)) {
                $lines[] = $index + 1;
            }
        }

        $this->addLines($check, $severity, $lines, $problem);
    }

    /**
     * Tells whether a Markdown line must not be wrapped (table row, heading, URL or link).
     */
    private function isUnwrappableMarkdown(string $line): bool
    {
        $trimmed = ltrim($line);

        return str_starts_with($trimmed, '|')
            || str_starts_with($trimmed, '#')
            || str_contains($line, '://')
            || str_contains($line, '](');
    }

    /**
     * Adds one finding for several lines.
     *
     * @param list<int> $lines Line numbers, starting at 1
     */
    private function addLines(CheckName $check, Severity $severity, array $lines, string $problem): void
    {
        if ($lines !== []) {
            $this->add($check, $severity, sprintf('%s in %d line(s), first: line %d', $problem, count($lines), $lines[0]), $lines[0]);
        }
    }

    /**
     * Adds a finding for the file.
     *
     * @param int|null $line First line of the violation, null if it concerns the whole file
     */
    private function add(CheckName $check, Severity $severity, string $message, ?int $line = null): void
    {
        $this->report->add($check, $severity, $message, $this->file, $line);
    }
}
