<?php

/**
 * @copyright  (C) 2026 Carsten Volkwein
 * @license    MIT License; see LICENSE
 */

declare(strict_types=1);

namespace SmartA\Jxt\Tests\Command;

use PHPUnit\Framework\TestCase;
use SmartA\Jxt\Bridge\Bridge;
use SmartA\Jxt\Bridge\PlanStager;
use SmartA\Jxt\Command\BridgeCommand;
use SmartA\Jxt\Configuration\ConfigurationLoader;
use SmartA\Jxt\Install\JoomlaCli;
use SmartA\Jxt\Install\JoomlaInstaller;
use SmartA\Jxt\Install\ProcessResult;
use SmartA\Jxt\Tests\Install\ScriptedProcessRunner;
use SmartA\Jxt\Tests\TemporaryDirectoryTrait;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Tests for the command `jxt bridge`.
 */
final class BridgeCommandTest extends TestCase
{
    use CommandDisplayTrait;
    use TestSiteConfigurationTrait;
    use TemporaryDirectoryTrait;

    private ScriptedProcessRunner $runner;

    #[\Override]
    protected function setUp(): void
    {
        $this->createTemporaryDirectory();
        mkdir($this->temporaryDirectory . '/tmp');
        $this->writeSites(['joomla_root' => $this->joomla(), 'web_user' => 'www-data']);
        $this->writeFile('joomla/configuration.php', '<?php');
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->removeTemporaryDirectory();
    }

    public function testStatusWithoutBridge(): void
    {
        $tester = $this->tester(new ProcessResult(0, ''));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('Installed: no', self::display($tester));
        self::assertStringContainsString('Runs via: sudo -u www-data', self::display($tester));
        self::assertStringContainsString('jxt bridge add', self::display($tester));
        self::assertSame([], $this->runner->commands);
    }

    public function testFailsForUnknownSite(): void
    {
        $tester = $this->tester(new ProcessResult(0, ''));

        self::assertSame(Command::FAILURE, $tester->execute(['--site' => 'j5']));
        self::assertStringContainsString('There is no site "j5" (selected via --site)', self::display($tester));
        self::assertSame([], $this->runner->commands);
    }

    public function testNamesTheSiteOfTheOption(): void
    {
        $tester = $this->tester(new ProcessResult(0, ''));

        self::assertSame(Command::SUCCESS, $tester->execute(['--site' => 'local']));
        self::assertStringContainsString(sprintf('jxt bridge on %s (site "local" via --site)', $this->joomla()), self::display($tester));
    }

    public function testStatusOfInstalledBridge(): void
    {
        $this->installBridge('0.0.1');
        $tester = $this->tester(new ProcessResult(0, '{"extension_id":270}'));

        self::assertSame(Command::SUCCESS, $tester->execute(['action' => 'status']));
        self::assertStringContainsString('Installed: 0.0.1', self::display($tester));
        self::assertStringContainsString('extension ID 270', self::display($tester));
        self::assertStringContainsString('jxt sync updates it', self::display($tester));
    }

    public function testStatusOfNewerBridge(): void
    {
        $this->installBridge('99.0.0');
        $tester = $this->tester(new ProcessResult(0, '{"extension_id":270}'));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('jxt sync uses it', self::display($tester));
    }

    public function testAddInstallsAndChecksTheBridge(): void
    {
        $tester = $this->tester(new ProcessResult(0, 'Extension installed successfully.'), new ProcessResult(0, '{"extension_id":270}'));

        self::assertSame(Command::SUCCESS, $tester->execute(['action' => 'add']));
        self::assertSame(['extension:install'], [$this->runner->cliArguments()[0][0]]);
        self::assertSame(['jxt:status'], $this->runner->cliArguments()[1]);
        self::assertStringContainsString('jxt bridge installed', self::display($tester));
    }

    public function testAddShowsSudoersRulesWhenSudoRefuses(): void
    {
        $tester = $this->tester(new ProcessResult(0, 'Extension installed successfully.'), new ProcessResult(1, 'sudo: a password is required'));

        self::assertSame(Command::FAILURE, $tester->execute(['action' => 'add']));
        self::assertStringContainsString('and add these lines:', $tester->getDisplay());
        self::assertStringContainsString('/cli/joomla.php jxt\:status', $tester->getDisplay());
    }

    public function testStatusViaCliCommand(): void
    {
        $this->writeSites([
            'joomla_root' => $this->joomla(),
            'cli_command' => ['docker', 'exec', 'web', 'php'],
            'cli_root' => '/var/www/html',
        ]);
        $this->installBridge('0.0.1');
        $tester = $this->tester(new ProcessResult(1, 'Error response from daemon: container web is not running'));

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('Runs via: docker exec web php /var/www/html/cli/joomla.php', self::display($tester));
        self::assertStringContainsString('jxt:status failed (exit code 1).', self::display($tester));
        self::assertStringContainsString('ran via cli_command', self::display($tester));
        self::assertSame([['docker', 'exec', 'web', 'php', '/var/www/html/cli/joomla.php', 'jxt:status']], $this->runner->commands);
    }

    public function testRemoveWithoutBridge(): void
    {
        $tester = $this->tester(new ProcessResult(0, ''));

        self::assertSame(Command::SUCCESS, $tester->execute(['action' => 'remove']));
        self::assertStringContainsString('not installed', self::display($tester));
        self::assertSame([], $this->runner->commands);
    }

    public function testRemoveUninstallsTheBridge(): void
    {
        $this->installBridge('0.1.0');
        $tester = $this->tester(new ProcessResult(0, '{"extension_id":270}'), new ProcessResult(0, 'Extension removed!'));

        self::assertSame(Command::SUCCESS, $tester->execute(['action' => 'remove']));
        self::assertSame(['extension:remove', '-n', '270'], $this->runner->cliArguments()[1]);
        self::assertStringContainsString('jxt bridge removed', self::display($tester));
    }

    public function testRejectsUnknownAction(): void
    {
        self::assertSame(Command::INVALID, $this->tester(new ProcessResult(0, ''))->execute(['action' => 'install']));
    }

    /**
     * Creates the command whose processes answer with the given results.
     */
    private function tester(ProcessResult $result, ProcessResult ...$results): CommandTester
    {
        $this->runner = new ScriptedProcessRunner($result, ...$results);
        $temporaryDirectory = $this->temporaryDirectory . '/tmp';

        return new CommandTester(new BridgeCommand(
            $this->temporaryDirectory . '/project',
            new ConfigurationLoader($this->globalConfigFile()),
            new Bridge(
                new JoomlaCli($this->runner, '/usr/bin/php8.5'),
                new JoomlaInstaller($this->runner, '/usr/bin/php8.5', $temporaryDirectory),
                new PlanStager($temporaryDirectory),
                temporaryDirectory: $temporaryDirectory,
            ),
            'anna',
        ));
    }

    /**
     * Writes the installed manifest of the bridge in the given version to the Joomla directory.
     */
    private function installBridge(string $version): void
    {
        $this->writeFile('joomla/' . Bridge::INSTALLED_MANIFEST, '<extension type="plugin" group="console"><name>x</name>'
            . '<version>' . $version . '</version><files><folder plugin="jxt">services</folder></files></extension>');
    }

    /**
     * Returns the Joomla root directory.
     */
    private function joomla(): string
    {
        return $this->temporaryDirectory . '/joomla';
    }
}
