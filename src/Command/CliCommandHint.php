<?php

/**
 * @copyright  (C) 2026 Carsten Volkwein
 * @license    MIT License; see LICENSE
 */

declare(strict_types=1);

namespace SmartA\Jxt\Command;

use SmartA\Jxt\Site\TestSite;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Note shown after a failed call of the Joomla CLI via `cli_command`, so that the command can be checked by hand.
 */
final class CliCommandHint
{
    /**
     * Shows how the Joomla CLI ran, raw so that quotes stay intact; nothing without `cli_command`.
     */
    public static function show(SymfonyStyle $io, TestSite $site): void
    {
        if (!$site->usesCliCommand()) {
            return;
        }

        $io->writeln('The Joomla CLI ran via cli_command. Check that it works, e.g. with');
        $io->writeln('  ' . $site->describeCli() . ' list', OutputInterface::OUTPUT_RAW);
    }
}
