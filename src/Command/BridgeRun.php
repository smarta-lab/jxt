<?php

/**
 * @copyright  (C) 2026 Carsten Volkwein
 * @license    MIT License; see LICENSE
 */

declare(strict_types=1);

namespace SmartA\Jxt\Command;

use SmartA\Jxt\Bridge\Bridge;
use SmartA\Jxt\Install\ProcessResult;
use SmartA\Jxt\JxtException;
use SmartA\Jxt\Manifest\Manifest;
use SmartA\Jxt\Site\TestSite;
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
     * Applies the plan via the bridge, after updating an older bridge; false if a step failed.
     *
     * A newer bridge is used as it is, so that projects with different jxt versions on one site do not reinstall
     * the bridge in turn; if it no longer reads the plan format, `jxt:apply` says how to resolve that.
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
        TestSite $site,
        string $installedVersion,
    ): bool {
        $bundledVersion = $this->bridge->bundledVersion();

        if (version_compare($installedVersion, $bundledVersion, '>')) {
            $io->note(sprintf('jxt bridge %s on the site is newer than the bundled %s; using it.', $installedVersion, $bundledVersion));
        } elseif ($installedVersion !== $bundledVersion) {
            $io->note(sprintf('Updating the jxt bridge on the site: %s → %s.', $installedVersion ?: '?', $bundledVersion));
            $result = $this->bridge->install($site);

            if ($result->exitCode !== 0) {
                $io->writeln(trim($result->output));
                $this->explainFailure($io, $result, $site, 'Updating the jxt bridge');

                return false;
            }
        }

        $result = $this->bridge->apply($plan, $manifest, $extensionDirectory, $site);

        if ($result->exitCode !== 0) {
            $io->writeln(trim($result->output));
            $this->explainFailure($io, $result, $site, 'jxt:apply');

            return false;
        }

        $remarks = trim((string) preg_replace('/^Applied: .*$/m', '', $result->output));

        if ($remarks !== '') {
            $io->writeln($remarks);
        }

        return true;
    }

    /**
     * Reports a failed call of the Joomla CLI, with the sudoers rules if sudo refused or the command if it ran via
     * `cli_command`; the caller shows the output.
     *
     * @param string $subject What failed, e.g. `jxt:apply`
     */
    public function explainFailure(SymfonyStyle $io, ProcessResult $result, TestSite $site, string $subject): void
    {
        if (!$site->usesCliCommand() && $site->webUser !== null && $result->sudoRefused()) {
            SudoersHint::show(
                $io,
                $site->webUser,
                $this->bridge->sudoersRules($this->currentUser, $site->joomlaRoot, $site->webUser),
            );

            return;
        }

        $io->error(sprintf('%s failed (exit code %d).', $subject, $result->exitCode));
        CliCommandHint::show($io, $site);
    }
}
