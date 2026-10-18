<?php

/**
 * @copyright  (C) 2026 Carsten Volkwein
 * @license    MIT License; see LICENSE
 */

declare(strict_types=1);

namespace SmartA\Jxt\Command;

use SmartA\Jxt\Bridge\Bridge;
use SmartA\Jxt\Configuration\Configuration;
use SmartA\Jxt\Install\ProcessResult;
use SmartA\Jxt\JxtException;
use SmartA\Jxt\Manifest\Manifest;
use SmartA\Jxt\Sync\SyncPlan;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Applies plans via the jxt bridge for `jxt sync` and `jxt install --clean` and reports failures of the Joomla CLI.
 */
final readonly class BridgeRun
{
    /**
     * @param string $currentUser Name of the calling user, for the sudoers rules in the error hint
     */
    public function __construct(
        private Bridge $bridge,
        private string $currentUser,
    ) {
    }

    /**
     * Applies the plan via the bridge, after replacing a bridge of another version; false if a step failed.
     *
     * Remarks of `jxt:apply` beyond its summary line, e.g. a manifest cache that could not be refreshed, are shown.
     *
     * @param string $installedVersion Version of the bridge installed on the site
     *
     * @throws JxtException if the bridge cannot be built, the plan cannot be staged or the Joomla CLI cannot be started
     */
    public function apply(
        SymfonyStyle $io,
        SyncPlan $plan,
        Manifest $manifest,
        string $extensionDirectory,
        Configuration $configuration,
        string $installedVersion,
    ): bool {
        $joomlaRoot = $configuration->validJoomlaRoot();
        $bundledVersion = $this->bridge->bundledVersion();

        if ($installedVersion !== $bundledVersion) {
            $io->note(sprintf('Updating the jxt bridge on the site: %s → %s.', $installedVersion ?: '?', $bundledVersion));
            $result = $this->bridge->install($joomlaRoot, $configuration->webUser);

            if ($result->exitCode !== 0) {
                $io->writeln(trim($result->output));
                $this->explainFailure($io, $result, $configuration, $joomlaRoot, 'Updating the jxt bridge');

                return false;
            }
        }

        $result = $this->bridge->apply($plan, $manifest, $extensionDirectory, $joomlaRoot, $configuration);

        if ($result->exitCode !== 0) {
            $io->writeln(trim($result->output));
            $this->explainFailure($io, $result, $configuration, $joomlaRoot, 'jxt:apply');

            return false;
        }

        $remarks = trim((string) preg_replace('/^Applied: .*$/m', '', $result->output));

        if ($remarks !== '') {
            $io->writeln($remarks);
        }

        return true;
    }

    /**
     * Reports a failed call of the Joomla CLI, with the sudoers rules if sudo refused; the caller shows the output.
     *
     * @param string $subject What failed, e.g. `jxt:apply`
     */
    public function explainFailure(
        SymfonyStyle $io,
        ProcessResult $result,
        Configuration $configuration,
        string $joomlaRoot,
        string $subject,
    ): void {
        if ($configuration->webUser !== null && $result->sudoRefused()) {
            SudoersHint::show(
                $io,
                $configuration->webUser,
                $this->bridge->sudoersRules($this->currentUser, $joomlaRoot, $configuration->webUser),
            );

            return;
        }

        $io->error(sprintf('%s failed (exit code %d).', $subject, $result->exitCode));
    }
}
