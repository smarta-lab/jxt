<?php

/**
 * @copyright  (C) 2026 Carsten Volkwein
 * @license    MIT License; see LICENSE
 */

declare(strict_types=1);

namespace SmartA\Jxt\Install;

/**
 * Extensions of a ZIP file: the extension itself, or the extensions of a package.
 */
final readonly class ZipContent
{
    /**
     * @param list<ZipExtension>    $extensions Extensions that jxt can read
     * @param array<string, string> $skipped    Reason per entry of a package that jxt cannot read, by its path in
     *                                          the package
     */
    public function __construct(
        public array $extensions,
        public array $skipped = [],
    ) {
    }
}
