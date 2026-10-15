<?php

declare(strict_types=1);

namespace SmartA\Jxt\Check\Checker;

use SmartA\Jxt\Check\CheckContext;
use SmartA\Jxt\Check\CheckReport;

/**
 * Runs the checks of one group and adds the findings to the report.
 */
interface Checker
{
    /**
     * Returns the group of the checks, e.g. `manifest`; the checker is skipped if the whole group is excluded.
     */
    public function group(): string;

    /**
     * Runs the checks.
     */
    public function check(CheckContext $context, CheckReport $report): void;
}
