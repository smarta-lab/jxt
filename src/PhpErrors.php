<?php

/**
 * @copyright  (C) 2026 Carsten Volkwein
 * @license    MIT License; see LICENSE
 */

declare(strict_types=1);

namespace SmartA\Jxt;

use Closure;

/**
 * Captures warnings from PHP functions so that they can be included in an error message.
 */
final class PhpErrors
{
    /**
     * Runs the operation and returns its result and the last PHP message raised during it.
     *
     * @template T
     *
     * @param Closure(): T $operation
     *
     * @return array{T, string|null}
     */
    public static function capture(Closure $operation): array
    {
        $error = null;
        set_error_handler(static function (int $level, string $message) use (&$error): bool {
            $error = $message;

            return true;
        });

        try {
            return [$operation(), $error];
        } finally {
            restore_error_handler();
        }
    }
}
