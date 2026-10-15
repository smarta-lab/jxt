<?php

declare(strict_types=1);

namespace SmartA\Jxt\EditorConfig;

/**
 * Determines the EditorConfig properties of a file.
 *
 * Reads all `.editorconfig` files from the file's directory upwards until one has `root = true`.
 * Files closer to the file and later sections within a file win. A value of `unset` (in any case)
 * removes the property. Unlike the reference implementation, no defaults are derived (e.g.
 * `tab_width` from `indent_size`); jxt does not need them. Parsed files are cached per instance.
 */
final class EditorConfigResolver
{
    /** @var array<string, ?EditorConfigFile> */
    private array $files = [];

    public function __construct(private readonly string $fileName = '.editorconfig')
    {
    }

    /**
     * Returns the properties of the file at the absolute `$path`; the file itself need not exist.
     *
     * @return array<string, string>
     *
     * @throws EditorConfigException for relative paths or unreadable `.editorconfig` files
     */
    public function properties(string $path): array
    {
        if (!str_starts_with($path, '/')) {
            throw new EditorConfigException(sprintf('EditorConfig needs an absolute path, "%s" given.', $path));
        }

        $properties = [];

        foreach ($this->applicableFiles(dirname($path)) as $file) {
            foreach ($file->sections as $section) {
                if ($section->glob->matches($path)) {
                    $properties = array_replace($properties, $section->properties);
                }
            }
        }

        return array_filter($properties, static fn (string $value): bool => strtolower($value) !== 'unset');
    }

    /**
     * Checks whether any `.editorconfig` file applies to files in the absolute `$directory`.
     */
    public function hasFiles(string $directory): bool
    {
        return $this->applicableFiles(rtrim($directory, '/')) !== [];
    }

    /**
     * Returns the `.editorconfig` files that apply to files in `$directory`, the outermost first.
     *
     * @return list<EditorConfigFile>
     */
    private function applicableFiles(string $directory): array
    {
        $files = [];

        while (true) {
            $file = $this->file($directory);

            if ($file !== null) {
                array_unshift($files, $file);

                if ($file->root) {
                    break;
                }
            }

            $parent = dirname($directory);

            if ($parent === $directory) {
                break;
            }

            $directory = $parent;
        }

        return $files;
    }

    /**
     * Returns the parsed `.editorconfig` file of a directory, or null if there is none.
     */
    private function file(string $directory): ?EditorConfigFile
    {
        if (!array_key_exists($directory, $this->files)) {
            $path = rtrim($directory, '/') . '/' . $this->fileName;
            $this->files[$directory] = is_file($path) ? EditorConfigFile::load($path) : null;
        }

        return $this->files[$directory];
    }
}
