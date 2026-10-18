<?php

/**
 * @copyright  (C) 2026 Carsten Volkwein
 * @license    MIT License; see LICENSE
 */

declare(strict_types=1);

namespace SmartA\Jxt\EditorConfig;

/**
 * Translates an EditorConfig glob into a regular expression (without delimiters and anchors).
 *
 * Follows the reference implementation editorconfig-core-py: `*` matches any string without `/`,
 * `**` any string, `?` one character without `/`, `[...]` and `[!...]` one character from or not
 * from a set, `{a,b}` one of the alternatives, `{n..m}` an integer in the range. A backslash makes
 * the next character literal. Braces without a matching counterpart and brackets that contain a
 * `/` or are not closed are literal. Each numeric range becomes a capturing group, the bounds are
 * collected in `$ranges` and must be checked after a match (see Glob).
 */
final class GlobTranslator
{
    /** @var list<string> */
    private readonly array $characters;

    private readonly int $length;

    private readonly bool $bracesMatch;

    private int $index = 0;

    private int $braceLevel = 0;

    private bool $inBrackets = false;

    private bool $escaped = false;

    /** @var list<array{int, int}> */
    private array $ranges = [];

    public function __construct(string $glob)
    {
        $this->characters = mb_str_split($glob);
        $this->length = count($this->characters);
        $this->bracesMatch = preg_match_all('/(?<!\\\\)\{/', $glob) === preg_match_all('/(?<!\\\\)\}/', $glob);
    }

    /**
     * Returns the regular expression and the bounds of the numeric ranges in the order of their groups.
     *
     * @return array{string, list<array{int, int}>}
     */
    public function translate(): array
    {
        $regex = '';

        while ($this->index < $this->length) {
            $character = $this->characters[$this->index++];
            $regex .= $this->translateCharacter($character);
            $this->escaped = $character === '\\' && !$this->escaped;
        }

        return [$regex, $this->ranges];
    }

    /**
     * Translates the character before `$this->index`; `$this->escaped` still refers to the previous one.
     */
    private function translateCharacter(string $character): string
    {
        if ($character === '\\') {
            return $this->escaped ? '\\\\' : '';
        }

        if ($this->escaped) {
            return preg_quote($character, '#');
        }

        if ($this->inBrackets) {
            return $this->translateInBrackets($character);
        }

        return match ($character) {
            '*' => $this->translateStar(),
            '?' => '[^/]',
            '[' => $this->openBrackets(),
            '{' => $this->openBraces(),
            ',' => $this->braceLevel > 0 ? '|' : '\\,',
            '}' => $this->closeBraces(),
            '/' => $this->translateSlash(),
            default => preg_quote($character, '#'),
        };
    }

    /**
     * Translates a character of a set; only `]` (end) and `-` (range) have a special meaning there.
     */
    private function translateInBrackets(string $character): string
    {
        if ($character === ']') {
            $this->inBrackets = false;

            return ']';
        }

        return $character === '-' ? '-' : preg_quote($character, '#');
    }

    /**
     * Translates `*` or `**`.
     */
    private function translateStar(): string
    {
        if (($this->characters[$this->index] ?? '') === '*') {
            $this->index++;

            return '.*';
        }

        return '[^/]*';
    }

    /**
     * Translates `/`; `/`, `**` and `/` in a row also match a single `/`.
     */
    private function translateSlash(): string
    {
        if (array_slice($this->characters, $this->index, 3) === ['*', '*', '/']) {
            $this->index += 3;

            return '(?:/|/.*/)';
        }

        return '/';
    }

    /**
     * Starts a set or returns a literal `[` if the set is not closed or contains a `/`.
     */
    private function openBrackets(): string
    {
        if (!$this->bracketsClose()) {
            return '\\[';
        }

        $this->inBrackets = true;
        $regex = '[';

        if (in_array($this->characters[$this->index] ?? '', ['!', '^'], true)) {
            $this->index++;
            $regex = '[^';
        }

        if (($this->characters[$this->index] ?? '') === ']') {
            $this->index++;
            $regex .= '\\]';
        }

        return $regex;
    }

    /**
     * Checks whether an unescaped `]` follows before any `/`.
     *
     * A `]` directly after `[` or `[!` belongs to the set, as in POSIX.
     */
    private function bracketsClose(): bool
    {
        $start = in_array($this->characters[$this->index] ?? '', ['!', '^'], true) ? $this->index + 1 : $this->index;

        for ($position = $start; $position < $this->length; $position++) {
            $character = $this->characters[$position];

            if ($character === '\\') {
                $position++;
            } elseif ($character === '/') {
                return false;
            } elseif ($character === ']' && $position > $start) {
                return true;
            }
        }

        return false;
    }

    /**
     * Translates `{`: alternatives, a numeric range or literal braces.
     */
    private function openBraces(): string
    {
        [$end, $hasComma] = $this->scanBraces();

        if (!$hasComma && $end < $this->length) {
            $content = implode('', array_slice($this->characters, $this->index, $end - $this->index));
            $this->index = $end + 1;

            return $this->translateBraceContent($content);
        }

        if ($hasComma && $this->bracesMatch) {
            $this->braceLevel++;

            return '(?:';
        }

        return '\\{';
    }

    /**
     * Returns the position of the closing `}` (or the end of the glob) and whether an unescaped comma comes first.
     *
     * @return array{int, bool}
     */
    private function scanBraces(): array
    {
        $escaped = false;

        for ($position = $this->index; $position < $this->length; $position++) {
            $character = $this->characters[$position];

            if (!$escaped && $character === '}') {
                return [$position, false];
            }

            if (!$escaped && $character === ',') {
                return [$position, true];
            }

            $escaped = $character === '\\' && !$escaped;
        }

        return [$this->length, false];
    }

    /**
     * Translates the content of braces without a comma: a numeric range, otherwise literal braces.
     */
    private function translateBraceContent(string $content): string
    {
        if (preg_match('/^([+-]?\d+)\.\.([+-]?\d+)$/', $content, $bounds) === 1) {
            $this->ranges[] = [(int) $bounds[1], (int) $bounds[2]];

            return '([+-]?\d+)';
        }

        [$regex, $ranges] = (new self($content))->translate();
        $this->ranges = [...$this->ranges, ...$ranges];

        return '\\{' . $regex . '\\}';
    }

    /**
     * Translates `}`: ends alternatives or is literal.
     */
    private function closeBraces(): string
    {
        if ($this->braceLevel === 0) {
            return '\\}';
        }

        $this->braceLevel--;

        return ')';
    }
}
