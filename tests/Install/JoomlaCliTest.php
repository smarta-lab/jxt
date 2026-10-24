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
use SmartA\Jxt\Site\SiteException;
use SmartA\Jxt\Site\TestSite;

/**
 * Tests for running commands of the Joomla CLI.
 */
final class JoomlaCliTest extends TestCase
{
    public function testRunsViaSudoAsWebUser(): void
    {
        $runner = new ScriptedProcessRunner(new ProcessResult(0, ''));

        (new JoomlaCli($runner, '/usr/bin/php8.5'))->run(new TestSite('/var/www/html', 'www-data'), ['jxt:apply', '/tmp/x']);

        self::assertSame(
            [['sudo', '-n', '-u', 'www-data', '/usr/bin/php8.5', '/var/www/html/cli/joomla.php', 'jxt:apply', '/tmp/x']],
            $runner->commands,
        );
    }

    public function testRunsWithoutSudoWithoutWebUser(): void
    {
        $runner = new ScriptedProcessRunner(new ProcessResult(0, ''));

        (new JoomlaCli($runner, '/usr/bin/php8.5'))->run(new TestSite(sys_get_temp_dir()), ['jxt:status']);

        self::assertSame([['/usr/bin/php8.5', sys_get_temp_dir() . '/cli/joomla.php', 'jxt:status']], $runner->commands);
    }

    public function testRejectsASiteWithoutWebUserThatTheUserCannotWrite(): void
    {
        $runner = new ScriptedProcessRunner(new ProcessResult(0, ''));

        try {
            (new JoomlaCli($runner, '/usr/bin/php8.5'))->run(new TestSite('/nonexistent/site'), ['jxt:status']);
            self::fail('SiteException expected');
        } catch (SiteException $exception) {
            self::assertStringContainsString('You cannot write to /nonexistent/site', $exception->getMessage());
            self::assertStringContainsString('jxt config set --global web_user www-data', $exception->getMessage());
        }

        self::assertSame([], $runner->commands);
    }

    public function testRunsViaCliCommandWithTheCliRoot(): void
    {
        $runner = new ScriptedProcessRunner(new ProcessResult(0, ''));
        $site = new TestSite('/home/anna/site', null, ['docker', 'exec', '-u', 'www-data', 'web', 'php'], '/var/www/html');

        (new JoomlaCli($runner, '/usr/bin/php8.5'))->run($site, ['jxt:status']);

        self::assertSame(
            [['docker', 'exec', '-u', 'www-data', 'web', 'php', '/var/www/html/cli/joomla.php', 'jxt:status']],
            $runner->commands,
        );
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
