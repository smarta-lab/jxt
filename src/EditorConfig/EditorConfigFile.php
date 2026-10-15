<?php

declare(strict_types=1);

namespace SmartA\Jxt\EditorConfig;

/**
 * Content of an `.editorconfig` file, read according to the EditorConfig specification.
 *
 * Lines are trimmed; blank lines and lines starting with `;` or `#` are comments, there are no
 * comments at the end of a line. `[name]` starts a section, `key = value` is split at the first `=`.
 * Before the first section only `root` is evaluated. Property names are lowercased, and so are the
 * values of the properties defined by the specification. Other lines are ignored.
 */
final readonly class EditorConfigFile
{
    private const array CASE_INSENSITIVE_PROPERTIES = [
        'charset',
        'end_of_line',
        'indent_size',
        'indent_style',
        'insert_final_newline',
        'tab_width',
        'trim_trailing_whitespace',
    ];

    /**
     * @param list<Section> $sections
     */
    public function __construct(public bool $root, public array $sections)
    {
    }

    /**
     * Reads the file at `$path`.
     *
     * @throws EditorConfigException if the file cannot be read
     */
    public static function load(string $path): self
    {
        $content = is_file($path) ? @file_get_contents($path) : false;

        if ($content === false) {
            throw new EditorConfigException(sprintf('Cannot read "%s".', $path));
        }

        return self::parse($content, dirname($path));
    }

    /**
     * Parses the content of a file located in `$directory` (absolute, without trailing `/`).
     */
    public static function parse(string $content, string $directory): self
    {
        $root = false;
        $sections = [];
        $glob = null;
        $properties = [];

        foreach (self::lines($content) as $line) {
            if (str_starts_with($line, '[') && str_ends_with($line, ']')) {
                if ($glob !== null) {
                    $sections[] = new Section($glob, $properties);
                }

                $glob = Glob::fromSection(substr($line, 1, -1), $directory);
                $properties = [];
            } elseif (str_contains($line, '=')) {
                [$name, $value] = self::pair($line);

                if ($glob !== null) {
                    $properties[$name] = $value;
                } elseif ($name === 'root') {
                    $root = strtolower($value) === 'true';
                }
            }
        }

        if ($glob !== null) {
            $sections[] = new Section($glob, $properties);
        }

        return new self($root, $sections);
    }

    /**
     * Returns the trimmed lines without blank lines and comments.
     *
     * @return list<string>
     */
    private static function lines(string $content): array
    {
        $content = str_starts_with($content, "\u{FEFF}") ? substr($content, 3) : $content;
        $lines = array_map(trim(...), preg_split('/\r\n|\r|\n/', $content) ?: []);

        return array_values(array_filter(
            $lines,
            static fn (string $line): bool => $line !== '' && !str_starts_with($line, ';') && !str_starts_with($line, '#'),
        ));
    }

    /**
     * Splits a line at the first `=` into the lowercased name and the value.
     *
     * @return array{string, string}
     */
    private static function pair(string $line): array
    {
        [$name, $value] = explode('=', $line, 2);
        $name = strtolower(trim($name));
        $value = trim($value);

        if (in_array($name, self::CASE_INSENSITIVE_PROPERTIES, true)) {
            $value = strtolower($value);
        }

        return [$name, $value];
    }
}
