<?php

declare(strict_types=1);

namespace SmartA\Jxt\Check;

/**
 * Result of a single check, e.g. a missing file or a line that is too long.
 */
final readonly class Finding
{
    public function __construct(
        public CheckName $check,
        public Severity $severity,
        public string $message,
    ) {
    }
}
