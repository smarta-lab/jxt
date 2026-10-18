<?php

/**
 * @copyright  (C) 2026 Carsten Volkwein
 * @license    MIT License; see LICENSE
 */

declare(strict_types=1);

namespace SmartA\Jxt\Command;

use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Error shown when sudo refuses to run the Joomla CLI as web server user, with the sudoers rules to add.
 */
final class SudoersHint
{
    /**
     * Shows the error and the rules, raw so that the backslashes of the rules stay intact.
     *
     * @param list<string> $rules
     */
    public static function show(SymfonyStyle $io, string $webUser, array $rules): void
    {
        $io->error(sprintf('sudo did not allow running the Joomla CLI as %s without password.', $webUser));
        $io->writeln(count($rules) === 1 ? 'Create the rule with' : 'Create the rules with');
        $io->writeln('  sudo visudo -f /etc/sudoers.d/jxt');
        $io->writeln(count($rules) === 1 ? 'and add this line:' : 'and add these lines:');

        foreach ($rules as $rule) {
            $io->writeln('  ' . $rule, OutputInterface::OUTPUT_RAW);
        }
    }
}
