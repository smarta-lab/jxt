<?php

declare(strict_types=1);

namespace SmartA\Jxt\Check;

/**
 * Severity of a finding: errors fail the check, warnings only with `--strict`, notices never.
 */
enum Severity: string
{
    case Error = 'error';
    case Warning = 'warning';
    case Notice = 'notice';

    /**
     * Returns the label in the output.
     */
    public function label(): string
    {
        return match ($this) {
            self::Error => 'ERROR',
            self::Warning => 'WARN',
            self::Notice => 'INFO',
        };
    }
}
