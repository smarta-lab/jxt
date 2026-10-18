<?php

/**
 * @copyright  (C) 2026 Carsten Volkwein
 * @license    MIT License; see LICENSE
 */

declare(strict_types=1);

namespace SmartA\Jxt\Command;

use SmartA\Jxt\Bridge\Bridge;
use SmartA\Jxt\Configuration\Configuration;
use SmartA\Jxt\Configuration\ConfigurationLoader;
use SmartA\Jxt\Install\ProcessResult;
use SmartA\Jxt\JxtException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Command `jxt bridge`: installs, removes or shows the jxt bridge, the console plugin through which `jxt sync`
 * writes the files as web server user.
 *
 * The first installation is explicit, since it adds an extension to the site and needs sudoers rules; later
 * version changes are installed by `jxt sync` itself.
 */
#[AsCommand(name: 'bridge', description: 'Installs, removes or shows the jxt bridge on the local Joomla test site.')]
final class BridgeCommand extends Command
{
    /**
     * @param string $projectDirectory Project directory, usually the current directory
     * @param string $currentUser      Name of the calling user, for the sudoers rules in the error hint
     */
    public function __construct(
        private readonly string $projectDirectory,
        private readonly ConfigurationLoader $configurationLoader,
        private readonly Bridge $bridge,
        private readonly string $currentUser,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this->addArgument('action', InputArgument::OPTIONAL, 'status, add or remove', BridgeAction::Status->value);
    }

    /**
     * Runs the selected action and reports domain errors without a stack trace.
     */
    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $value = $input->getArgument('action');
        $action = BridgeAction::tryFrom(is_string($value) ? $value : '');

        if ($action === null) {
            $io->error(sprintf('Unknown action "%s". Use status, add or remove.', is_string($value) ? $value : ''));

            return Command::INVALID;
        }

        try {
            $configuration = $this->configurationLoader->load($this->projectDirectory);
            $joomlaRoot = $configuration->validJoomlaRoot();

            return match ($action) {
                BridgeAction::Status => $this->status($io, $configuration, $joomlaRoot),
                BridgeAction::Add => $this->add($io, $configuration, $joomlaRoot),
                BridgeAction::Remove => $this->remove($io, $configuration, $joomlaRoot),
            };
        } catch (JxtException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }
    }

    /**
     * Shows the bundled and the installed version and whether the Joomla CLI runs the plugin's commands.
     */
    private function status(SymfonyStyle $io, Configuration $configuration, string $joomlaRoot): int
    {
        $bundled = $this->bridge->bundledVersion();
        $installed = $this->bridge->installedVersion($joomlaRoot);

        $io->writeln(sprintf('<info>jxt bridge</info> on %s', $joomlaRoot));
        $io->writeln(sprintf('  Bundled:   %s', $bundled));
        $io->writeln(sprintf('  Installed: %s', $installed ?? 'no'));

        if ($installed === null) {
            $io->note('Install it with jxt bridge add, so that jxt sync writes the files as web server user.');

            return Command::SUCCESS;
        }

        $result = $this->bridge->status($joomlaRoot, $configuration->webUser);

        if ($result->exitCode !== 0) {
            $io->writeln(trim($result->output));
            $this->explainFailure($io, $result, $configuration, $joomlaRoot, 'jxt:status');

            return Command::FAILURE;
        }

        $io->writeln(sprintf('  Joomla CLI: jxt:status answers (extension ID %d)', $this->bridge->extensionId($result)));

        if ($installed !== $bundled) {
            $io->note('The versions differ. jxt sync installs the bundled version, or run jxt bridge add.');
        }

        return Command::SUCCESS;
    }

    /**
     * Installs the bundled plugin and checks that the Joomla CLI runs its commands.
     */
    private function add(SymfonyStyle $io, Configuration $configuration, string $joomlaRoot): int
    {
        $io->writeln(sprintf('<info>jxt bridge %s</info> → %s', $this->bridge->bundledVersion(), $joomlaRoot));
        $result = $this->bridge->install($joomlaRoot, $configuration->webUser);
        $io->writeln(trim($result->output));

        if ($result->exitCode !== 0) {
            $this->explainFailure($io, $result, $configuration, $joomlaRoot, 'The Joomla installer');

            return Command::FAILURE;
        }

        $status = $this->bridge->status($joomlaRoot, $configuration->webUser);

        if ($status->exitCode !== 0) {
            $io->writeln(trim($status->output));
            $this->explainFailure($io, $status, $configuration, $joomlaRoot, 'The bridge is installed, but jxt:status');

            return Command::FAILURE;
        }

        $io->success('jxt bridge installed. jxt sync now writes the files via the Joomla CLI.');

        return Command::SUCCESS;
    }

    /**
     * Uninstalls the plugin if it is installed.
     */
    private function remove(SymfonyStyle $io, Configuration $configuration, string $joomlaRoot): int
    {
        if ($this->bridge->installedVersion($joomlaRoot) === null) {
            $io->success(sprintf('The jxt bridge is not installed on %s.', $joomlaRoot));

            return Command::SUCCESS;
        }

        $result = $this->bridge->remove($joomlaRoot, $configuration->webUser);

        if ($result->exitCode !== 0) {
            $io->writeln(trim($result->output));
            $this->explainFailure($io, $result, $configuration, $joomlaRoot, 'Removing the bridge');

            return Command::FAILURE;
        }

        $io->success(sprintf('jxt bridge removed from %s.', $joomlaRoot));

        return Command::SUCCESS;
    }

    /**
     * Reports a failed call of the Joomla CLI, with the sudoers rules if sudo refused.
     *
     * @param string $subject What failed, e.g. `jxt:status`
     */
    private function explainFailure(
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
