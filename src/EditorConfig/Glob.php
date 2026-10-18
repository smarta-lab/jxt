<?php

/**
 * @copyright  (C) 2026 Carsten Volkwein
 * @license    MIT License; see LICENSE
 */

declare(strict_types=1);

namespace SmartA\Jxt\EditorConfig;

/**
 * Section name of an `.editorconfig` file, compiled for matching absolute file paths.
 *
 * A name containing `/` is relative to the directory of the `.editorconfig` file (a leading `/` is
 * removed), otherwise it matches files at any depth below that directory.
 */
final readonly class Glob
{
    /**
     * @param list<array{int, int}> $ranges Bounds of the numeric ranges, one per capturing group
     */
    private function __construct(private string $regex, private array $ranges)
    {
    }

    /**
     * Compiles a section name of the `.editorconfig` file in `$directory` (absolute, without trailing `/`).
     */
    public static function fromSection(string $section, string $directory): self
    {
        if (str_contains($section, '/')) {
            $section = str_starts_with($section, '/') ? substr($section, 1) : $section;
            $prefix = '/';
        } else {
            $prefix = '/(?:.*/)?';
        }

        [$regex, $ranges] = (new GlobTranslator($section))->translate();

        return new self('#^' . preg_quote(rtrim($directory, '/'), '#') . $prefix . $regex . '$#su', $ranges);
    }

    /**
     * Checks whether the absolute path matches; paths that are not valid UTF-8 never match.
     */
    public function matches(string $path): bool
    {
        if (preg_match($this->regex, $path, $groups) !== 1) {
            return false;
        }

        foreach ($this->ranges as $index => [$minimum, $maximum]) {
            if (!$this->inRange($groups[$index + 1], $minimum, $maximum)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Checks a matched number against a range; numbers with leading zeros never match.
     */
    private function inRange(string $number, int $minimum, int $maximum): bool
    {
        return preg_match('/^[+-]?(0|[1-9]\d*)$/', $number) === 1
            && (int) $number >= $minimum
            && (int) $number <= $maximum;
    }
}
