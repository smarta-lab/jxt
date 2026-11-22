<?php

/**
 * @copyright  (C) 2026 Carsten Volkwein
 * @license    MIT License; see LICENSE
 */

declare(strict_types=1);

namespace SmartA\Jxt\Check\Checker;

use SmartA\Jxt\Check\CheckContext;
use SmartA\Jxt\Check\CheckName;
use SmartA\Jxt\Check\CheckReport;
use SmartA\Jxt\Check\CheckScope;
use SmartA\Jxt\Check\Severity;
use SmartA\Jxt\Manifest\PackageResolution;

/**
 * Checks a package manifest against the extensions in the subdirectories: entries that match no extension, a
 * manifest file name under which Joomla does not find it when uninstalling, ZIP entries named after another version,
 * and extensions that are not part of the package.
 */
final readonly class PackageChecker implements Checker
{
    #[\Override]
    public function group(): string
    {
        return 'package';
    }

    #[\Override]
    public function scope(): CheckScope
    {
        return CheckScope::Package;
    }

    #[\Override]
    public function check(CheckContext $context, CheckReport $report): void
    {
        $resolution = $context->package;

        if ($resolution === null) {
            return;
        }

        $manifestFile = $context->projectPath($context->manifest->fileName);
        $this->checkEntries($resolution, $manifestFile, $context, $report);
        $this->checkFileName($context, $manifestFile, $report);

        foreach ($resolution->entries as $resolved) {
            $warning = $resolved->child === null ? null : $resolved->entry->nameWarning($resolved->child->manifest);

            if ($warning !== null) {
                $report->add(CheckName::PackageZipNames, Severity::Warning, $warning, $manifestFile);
            }
        }

        foreach ($resolution->unassigned as $child) {
            $report->add(
                CheckName::PackageUnlistedExtensions,
                Severity::Notice,
                sprintf('%s is not part of the package', $child->manifest->name()),
                $context->projectPath($child->relativePath),
            );
        }
    }

    /**
     * Reports a package without entries and the entries that cannot be resolved.
     */
    private function checkEntries(PackageResolution $resolution, string $manifestFile, CheckContext $context, CheckReport $report): void
    {
        if ($context->manifest->packageEntries === []) {
            $report->add(CheckName::PackageEntries, Severity::Error, 'lists no extensions in <files>', $manifestFile);
        }

        foreach ($resolution->errors() as $error) {
            $report->add(CheckName::PackageEntries, Severity::Error, $error, $manifestFile);
        }
    }

    /**
     * Reports a manifest whose file name differs from the element: Joomla copies it under its own name, but reads
     * `<element>.xml` when uninstalling.
     */
    private function checkFileName(CheckContext $context, string $manifestFile, CheckReport $report): void
    {
        $expected = $context->manifest->element . '.xml';

        if ($context->manifest->fileName !== $expected) {
            $report->add(CheckName::PackageFileName, Severity::Error, sprintf(
                'Joomla reads administrator/manifests/packages/%s when uninstalling the package; rename the manifest to %s',
                $expected,
                $expected,
            ), $manifestFile);
        }
    }
}
