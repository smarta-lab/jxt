<?php

/**
 * @copyright  (C) 2026 Carsten Volkwein
 * @license    MIT License; see LICENSE
 */

declare(strict_types=1);

namespace SmartA\Jxt\Install;

/**
 * Lays out plain text in blocks: collects the inline text of a block, wraps it at the width and separates the
 * blocks by a line break or a blank line.
 *
 * Blocks nest like HTML elements; each one may indent its content, start with a prefix such as a list bullet,
 * underline its lines like a heading or keep its text preformatted. Non-breaking spaces keep words together and
 * become ordinary spaces in the result.
 */
final class TextLayout
{
    /**
     * Gap that separates blocks by a line break.
     */
    public const int LINE = 1;

    /**
     * Gap that separates blocks by a blank line.
     */
    public const int BLANK_LINE = 2;

    private const int MINIMUM_WIDTH = 10;

    /**
     * @var list<array{gap: int, lines: list<string>}>
     */
    private array $blocks = [];

    /**
     * @var non-empty-list<array{indent: int, underline: string|null, preformatted: bool}>
     */
    private array $frames = [['indent' => 0, 'underline' => null, 'preformatted' => false]];

    private string $text = '';

    private string $prefix = '';

    private int $gap = 0;

    /**
     * @param int $width Maximum line length; words longer than that stay unbroken
     */
    public function __construct(private readonly int $width)
    {
    }

    /**
     * Starts a block inside the current one.
     *
     * @param int         $gap          Gap before the block, e.g. {@see self::BLANK_LINE}
     * @param int         $indent       Additional indentation of the content
     * @param string      $prefix       Start of the first line, e.g. `- `; the content is indented by its length
     * @param string|null $underline    Character that underlines the lines of the block like a heading
     * @param bool        $preformatted Keeps spaces and line breaks of the text and does not wrap it
     */
    public function open(int $gap, int $indent = 0, string $prefix = '', ?string $underline = null, bool $preformatted = false): void
    {
        $this->flush();
        $this->gap = max($this->gap, $gap);
        $frame = $this->frame();
        $this->frames[] = [
            'indent' => $frame['indent'] + $indent + mb_strlen($prefix),
            'underline' => $underline ?? $frame['underline'],
            'preformatted' => $preformatted || $frame['preformatted'],
        ];

        if ($prefix !== '') {
            $this->prefix = $prefix;
        }
    }

    /**
     * Ends the current block.
     *
     * @param int $gap Gap after the block
     */
    public function close(int $gap): void
    {
        $this->flush();

        if (count($this->frames) > 1) {
            array_pop($this->frames);
        }

        $this->gap = max($this->gap, $gap);
    }

    /**
     * Adds inline text to the current block; outside of preformatted blocks, whitespace collapses to one space.
     */
    public function text(string $text): void
    {
        if (!$this->frame()['preformatted']) {
            $text = preg_replace('/[ \t\n\r\f]+/', ' ', $text) ?? $text;

            if ($this->text === '' || str_ends_with($this->text, ' ') || str_ends_with($this->text, "\n")) {
                $text = ltrim($text, ' ');
            }
        }

        $this->text .= $text;
    }

    /**
     * Ends the current line inside the block.
     */
    public function lineBreak(): void
    {
        $this->text .= "\n";
    }

    /**
     * Adds a horizontal line over the available width as a block of its own.
     */
    public function rule(): void
    {
        $this->open(self::BLANK_LINE);
        $indent = $this->frame()['indent'];
        $this->addBlock([str_repeat(' ', $indent) . str_repeat('-', $this->availableWidth($indent))]);
        $this->close(self::BLANK_LINE);
    }

    /**
     * Returns the text of all blocks.
     */
    public function render(): string
    {
        $this->flush();
        $text = '';

        foreach ($this->blocks as $block) {
            $text .= str_repeat("\n", $block['gap']) . implode("\n", $block['lines']);
        }

        return $text;
    }

    /**
     * Turns the collected text into a block, if it is not empty.
     */
    private function flush(): void
    {
        $frame = $this->frame();
        $lines = $frame['preformatted'] ? $this->preformattedLines() : $this->inlineLines();
        $this->text = '';

        if ($lines === []) {
            return;
        }

        $indent = str_repeat(' ', $frame['indent']);
        $first = $this->prefix === '' ? $indent : str_repeat(' ', max(0, $frame['indent'] - mb_strlen($this->prefix))) . $this->prefix;
        $this->prefix = '';
        $width = $this->availableWidth($frame['indent']);
        $result = [];

        foreach ($lines as $line) {
            foreach ($frame['preformatted'] ? [$line] : $this->wrap($line, $width) as $piece) {
                $result[] = rtrim(($result === [] ? $first : $indent) . str_replace("\u{A0}", ' ', $piece));
            }
        }

        if ($frame['underline'] !== null) {
            $length = max(0, ...array_map(static fn (string $line): int => mb_strlen($line), $result)) - $frame['indent'];
            $result[] = $indent . str_repeat($frame['underline'], $length);
        }

        $this->addBlock($result);
    }

    /**
     * Adds finished lines as a block after the pending gap.
     *
     * @param list<string> $lines
     */
    private function addBlock(array $lines): void
    {
        $this->blocks[] = ['gap' => $this->blocks === [] ? 0 : max(self::LINE, $this->gap), 'lines' => $lines];
        $this->gap = 0;
    }

    /**
     * Returns the lines of the collected inline text without surrounding spaces and empty lines.
     *
     * @return list<string>
     */
    private function inlineLines(): array
    {
        return $this->withoutOuterEmptyLines(array_map(trim(...), explode("\n", $this->text)));
    }

    /**
     * Returns the lines of the collected preformatted text without trailing whitespace and empty lines.
     *
     * @return list<string>
     */
    private function preformattedLines(): array
    {
        return $this->withoutOuterEmptyLines(array_map(rtrim(...), preg_split('/\r\n|\r|\n/', $this->text) ?: []));
    }

    /**
     * @param list<string> $lines
     *
     * @return list<string>
     */
    private function withoutOuterEmptyLines(array $lines): array
    {
        while ($lines !== [] && $lines[0] === '') {
            array_shift($lines);
        }

        while ($lines !== [] && $lines[array_key_last($lines)] === '') {
            array_pop($lines);
        }

        return $lines;
    }

    /**
     * Wraps the line at spaces, so that no piece is longer than the width unless it consists of a single word.
     *
     * @return list<string>
     */
    private function wrap(string $line, int $width): array
    {
        $pieces = [];
        $current = '';

        foreach (explode(' ', $line) as $word) {
            if ($current !== '' && mb_strlen($current) + 1 + mb_strlen($word) > $width) {
                $pieces[] = $current;
                $current = $word;
            } else {
                $current = $current === '' ? $word : $current . ' ' . $word;
            }
        }

        return [...$pieces, $current];
    }

    /**
     * Returns the width that is left for text after the indentation.
     */
    private function availableWidth(int $indent): int
    {
        return max(self::MINIMUM_WIDTH, $this->width - $indent);
    }

    /**
     * @return array{indent: int, underline: string|null, preformatted: bool}
     */
    private function frame(): array
    {
        return $this->frames[array_key_last($this->frames)];
    }
}
