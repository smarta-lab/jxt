<?php

declare(strict_types=1);

namespace SmartA\Jxt\Check;

/**
 * Result of a single check, e.g. a missing file or a line that is too long.
 */
final readonly class Finding
{
    /**
     * @param string      $message Description without the file, which `text()` puts in front
     * @param string|null $file    Path relative to the project directory, null if the finding concerns no file
     * @param int|null    $line    Line in the file, starting at 1; the first one if the finding covers several
     */
    public function __construct(
        public CheckName $check,
        public Severity $severity,
        public string $message,
        public ?string $file = null,
        public ?int $line = null,
    ) {
    }

    /**
     * Returns the message for the text output, preceded by the file if there is one.
     */
    public function text(): string
    {
        return $this->file !== null ? $this->file . ': ' . $this->message : $this->message;
    }
}
