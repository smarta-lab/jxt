<?php

declare(strict_types=1);

namespace SmartA\Jxt\Check\Checker;

use SmartA\Jxt\Check\CheckContext;
use SmartA\Jxt\Check\CheckName;
use SmartA\Jxt\Check\CheckReport;
use SmartA\Jxt\Check\Severity;

/**
 * Checks `composer.json`: Composer recommends taking the version from Git tags instead of a `version` field.
 */
final readonly class ComposerChecker implements Checker
{
    #[\Override]
    public function group(): string
    {
        return 'composer';
    }

    #[\Override]
    public function check(CheckContext $context, CheckReport $report): void
    {
        $path = $context->projectDirectory . '/composer.json';
        $data = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;

        if (is_array($data) && array_key_exists('version', $data)) {
            $report->add(
                CheckName::ComposerVersionField,
                Severity::Warning,
                'composer.json has a "version" field; Composer recommends omitting it and using Git tags',
            );
        }
    }
}
