<?php

declare(strict_types=1);

namespace SmartA\Jxt\Tests\Command;

use Symfony\Component\Console\Tester\ApplicationTester;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Reads command output independently of the line wrapping of SymfonyStyle, which depends on path lengths.
 */
trait CommandDisplayTrait
{
    /**
     * Returns the output with all whitespace collapsed into single spaces.
     */
    private static function display(CommandTester|ApplicationTester $tester): string
    {
        return (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
    }
}
