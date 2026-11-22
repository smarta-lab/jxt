<?php

/**
 * @copyright  (C) 2026 Carsten Volkwein
 * @license    MIT License; see LICENSE
 */

declare(strict_types=1);

namespace SmartA\Jxt\Check\Checker;

use SmartA\Jxt\Check\CheckContext;
use SmartA\Jxt\Check\CheckReport;
use SmartA\Jxt\Check\CheckScope;

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
     * Returns what the checker checks, which decides whether it runs once or for each extension of a package.
     */
    public function scope(): CheckScope;

    /**
     * Runs the checks.
     */
    public function check(CheckContext $context, CheckReport $report): void;
}
