<?php

/**
 * @copyright  (C) 2026 Carsten Volkwein
 * @license    MIT License; see LICENSE
 */

declare(strict_types=1);

namespace SmartA\Jxt\Command;

use SmartA\Jxt\Manifest\ExtensionType;
use SmartA\Jxt\Manifest\Manifest;

/**
 * Rejects package manifests in the commands that only support single extensions so far (`jxt sync`, `jxt check`);
 * `jxt build` and `jxt install` support packages.
 */
final class PackageGuard
{
    /**
     * Throws if the manifest is a package manifest.
     *
     * @param string $command Name of the command, e.g. `sync`
     *
     * @throws UnsupportedPackageException for a package manifest
     */
    public static function reject(Manifest $manifest, string $command): void
    {
        if ($manifest->type === ExtensionType::Package) {
            throw new UnsupportedPackageException(sprintf(
                'jxt %s does not support packages yet. Run it in the directory of each extension of %s.',
                $command,
                $manifest->name(),
            ));
        }
    }
}
