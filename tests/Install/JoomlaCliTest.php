<?php

/**
 * @copyright  (C) 2026 Carsten Volkwein
 * @license    MIT License; see LICENSE
 */

declare(strict_types=1);

namespace SmartA\Jxt\Tests\Install;

use PHPUnit\Framework\TestCase;
use SmartA\Jxt\Install\JoomlaCli;
use SmartA\Jxt\Install\ProcessResult;

/**
 * Tests for running commands of the Joomla CLI.
 */
final class JoomlaCliTest extends TestCase
{
    public function testRunsViaSudoAsWebUser(): void
    {
        $runner = new ScriptedProcessRunner(new ProcessResult(0, ''));

        (new JoomlaCli($runner, '/usr/bin/php8.5'))->run('/var/www/html', 'www-data', ['jxt:apply', '/tmp/x']);

        self::assertSame(
            [['sudo', '-n', '-u', 'www-data', '/usr/bin/php8.5', '/var/www/html/cli/joomla.php', 'jxt:apply', '/tmp/x']],
            $runner->commands,
        );
    }

    public function testRunsWithoutSudoWithoutWebUser(): void
    {
        $runner = new ScriptedProcessRunner(new ProcessResult(0, ''));

        (new JoomlaCli($runner, '/usr/bin/php8.5'))->run('/var/www/html', null, ['jxt:status']);

        self::assertSame([['/usr/bin/php8.5', '/var/www/html/cli/joomla.php', 'jxt:status']], $runner->commands);
    }

    public function testCreatesSudoersRule(): void
    {
        self::assertSame(
            'anna ALL=(www-data) NOPASSWD: /usr/bin/php8.5 /var/www/html/cli/joomla.php jxt\:apply *',
            (new JoomlaCli(new ScriptedProcessRunner(new ProcessResult(0, '')), '/usr/bin/php8.5'))
                ->sudoersRule('anna', '/var/www/html', 'www-data', 'jxt\:apply *'),
        );
    }

    public function testDetectsRefusedSudo(): void
    {
        self::assertTrue((new ProcessResult(1, "sudo: a password is required\n"))->sudoRefused());
        self::assertFalse((new ProcessResult(1, 'Error: sudo: in a message'))->sudoRefused());
    }
}
