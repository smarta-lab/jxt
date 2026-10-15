<?php

declare(strict_types=1);

namespace SmartA\Jxt\EditorConfig;

/**
 * Section of an `.editorconfig` file: the files it applies to and its properties.
 */
final readonly class Section
{
    /**
     * @param array<string, string> $properties Lowercase names, values as in the file (see EditorConfigFile)
     */
    public function __construct(public Glob $glob, public array $properties)
    {
    }
}
