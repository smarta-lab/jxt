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
use SmartA\Jxt\Command\InstallCommand;
use SmartA\Jxt\Configuration\ConfigurationLoader;
use SmartA\Jxt\Install\JoomlaCli;
use SmartA\Jxt\Install\JoomlaInstaller;
use SmartA\Jxt\Install\ProcessResult;
use SmartA\Jxt\Tests\GitTrait;
use SmartA\Jxt\Tests\Install\RecordingProcessRunner;
use SmartA\Jxt\Tests\Install\ScriptedProcessRunner;
use SmartA\Jxt\Tests\TemporaryDirectoryTrait;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use ZipArchive;

/**
 * Tests for the `jxt install` command.
 */
final class InstallCommandTest extends TestCase
{
    use CommandDisplayTrait;
    use GitTrait;
    use TemporaryDirectoryTrait;

    private const string MANIFEST = '<extension type="module" method="upgrade"><name>mod_x</name>'
        . '<version>1.0.0</version><files><folder module="mod_x">tmpl</folder></files></extension>';

    #[\Override]
    protected function setUp(): void
    {
        $this->createTemporaryDirectory();
        $this->writeFile('project/mod_x.xml', self::MANIFEST);
        $this->writeFile('project/tmpl/default.php', '<?php');
        $this->writeFile('project/.jxt.json', (string) json_encode(['joomla_root' => $this->joomla()]));
        $this->writeFile('joomla/configuration.php', '<?php');
        mkdir($this->temporaryDirectory . '/tmp');
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->removeTemporaryDirectory();
    }

    public function testBuildsTheZipAndInstallsItAsWebUser(): void
    {
        $runner = new RecordingProcessRunner(new ProcessResult(0, '[OK] Extension installed successfully.'));
        $tester = $this->tester($runner);

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertFileExists($this->temporaryDirectory . '/project/dist/mod_x-1.0.0.zip');
        self::assertSame(['sudo', '-n', '-u', 'www-data'], array_slice($runner->command, 0, 4));
        self::assertSame($this->joomla() . '/cli/joomla.php', $runner->command[5]);
        self::assertNotNull($runner->packageContent);
        self::assertStringContainsString('Extension installed successfully', self::display($tester));
        self::assertStringContainsString('mod_x 1.0.0 installed', self::display($tester));
    }

    public function testRunsWithoutSudoIfWebUserIsNull(): void
    {
        $this->writeFile(
            'project/.jxt.json',
            (string) json_encode(['joomla_root' => $this->joomla(), 'web_user' => null]),
        );
        $runner = new RecordingProcessRunner(new ProcessResult(0, ''));

        self::assertSame(Command::SUCCESS, $this->tester($runner)->execute([]));
        self::assertSame('/usr/bin/php8.5', $runner->command[0]);
    }

    public function testShowsTheSudoersRuleIfSudoAsksForAPassword(): void
    {
        $tester = $this->tester(new RecordingProcessRunner(new ProcessResult(1, 'sudo: a password is required')));

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('sudo visudo -f /etc/sudoers.d/jxt', self::display($tester));
        self::assertStringContainsString('anna ALL=(www-data) NOPASSWD: /usr/bin/php8.5', self::display($tester));
        self::assertStringContainsString('extension\:install *', self::display($tester));
    }

    public function testPointsToTheUpgradeMethodIfTheInstallationFails(): void
    {
        $this->writeFile('project/mod_x.xml', str_replace(' method="upgrade"', '', self::MANIFEST));
        $tester = $this->tester(new RecordingProcessRunner(new ProcessResult(1, '[ERROR] Unable to install extension')));

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('Unable to install extension', self::display($tester));
        self::assertStringContainsString('method="upgrade"', self::display($tester));
    }

    public function testDoesNotPointToTheUpgradeMethodIfItIsSet(): void
    {
        $tester = $this->tester(new RecordingProcessRunner(new ProcessResult(1, '[ERROR] Unable to install extension')));

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringNotContainsString('method="upgrade"', self::display($tester));
    }

    public function testFailsForDirectoryWithoutJoomla(): void
    {
        unlink($this->joomla() . '/configuration.php');
        $runner = new RecordingProcessRunner(new ProcessResult(0, ''));
        $tester = $this->tester($runner);

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('configuration.php', self::display($tester));
        self::assertSame([], $runner->command);
    }

    public function testInstallsViaCliCommandWithACopyInTheSite(): void
    {
        $this->useCliCommand();
        mkdir($this->joomla() . '/tmp');
        $runner = new RecordingProcessRunner(new ProcessResult(0, '[OK] Extension installed successfully.'));

        self::assertSame(Command::SUCCESS, $this->tester($runner)->execute([]));
        self::assertSame(
            ['docker', 'exec', 'web', 'php', '/var/www/html/cli/joomla.php', 'extension:install'],
            array_slice($runner->command, 0, 6),
        );
        self::assertMatchesRegularExpression('#^--path=/var/www/html/tmp/jxt-[0-9a-f]+-mod_x-1\.0\.0\.zip$#', $runner->command[6]);
    }

    public function testShowsTheCliCommandIfTheInstallationFails(): void
    {
        $this->useCliCommand();
        mkdir($this->joomla() . '/tmp');
        $tester = $this->tester(new RecordingProcessRunner(new ProcessResult(1, 'Error response from daemon: No such container: web')));

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('No such container: web', self::display($tester));
        self::assertStringContainsString('The Joomla installer failed (exit code 1).', self::display($tester));
        self::assertStringContainsString('docker exec web php /var/www/html/cli/joomla.php list', self::display($tester));
        self::assertStringNotContainsString('sudo', self::display($tester));
    }

    public function testExplainsAMissingTmpDirectoryWithCliCommand(): void
    {
        $this->useCliCommand();
        $runner = new RecordingProcessRunner(new ProcessResult(0, ''));
        $tester = $this->tester($runner);

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('chmod 1777 /var/www/html/tmp as root in the container', self::display($tester));
        self::assertSame([], $runner->command);
    }

    public function testPointsToTheBindMountIfJoomlaIsMissingWithCliCommand(): void
    {
        $this->useCliCommand();
        unlink($this->joomla() . '/configuration.php');
        $tester = $this->tester(new RecordingProcessRunner(new ProcessResult(0, '')));

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('a named volume is not supported', self::display($tester));
    }

    public function testInstallsAnExistingZipWithoutBuilding(): void
    {
        $this->writeZip('project/old/mod_x-0.9.0.zip', str_replace('1.0.0', '0.9.0', self::MANIFEST));
        $runner = new RecordingProcessRunner(new ProcessResult(0, '[OK] Extension installed successfully.'));
        $tester = $this->tester($runner);

        self::assertSame(Command::SUCCESS, $tester->execute(['--zip' => 'old/mod_x-0.9.0.zip']));
        self::assertDirectoryDoesNotExist($this->temporaryDirectory . '/project/dist');
        self::assertSame(file_get_contents($this->temporaryDirectory . '/project/old/mod_x-0.9.0.zip'), $runner->packageContent);
        self::assertStringContainsString('mod_x-0.9.0.zip →', self::display($tester));
        self::assertStringContainsString('mod_x 0.9.0 installed', self::display($tester));
    }

    public function testInstallsAZipWithAnAbsolutePathWithoutProjectManifest(): void
    {
        unlink($this->temporaryDirectory . '/project/mod_x.xml');
        $this->writeZip('elsewhere/mod_x.zip', self::MANIFEST);
        $runner = new RecordingProcessRunner(new ProcessResult(0, ''));

        $exitCode = $this->tester($runner)->execute(['--zip' => $this->temporaryDirectory . '/elsewhere/mod_x.zip']);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertNotNull($runner->packageContent);
    }

    public function testTakesTheUpgradeHintFromTheManifestInTheZip(): void
    {
        $this->writeZip('project/old.zip', str_replace(' method="upgrade"', '', self::MANIFEST));
        $tester = $this->tester(new RecordingProcessRunner(new ProcessResult(1, '[ERROR] Unable to install extension')));

        self::assertSame(Command::FAILURE, $tester->execute(['--zip' => 'old.zip']));
        self::assertStringContainsString('method="upgrade"', self::display($tester));
    }

    public function testFailsForAMissingZip(): void
    {
        $runner = new RecordingProcessRunner(new ProcessResult(0, ''));
        $tester = $this->tester($runner);

        self::assertSame(Command::FAILURE, $tester->execute(['--zip' => 'missing.zip']));
        self::assertStringContainsString('ZIP file missing.zip not found.', self::display($tester));
        self::assertSame([], $runner->command);
    }

    public function testFailsForAFileWithoutZipExtension(): void
    {
        $this->writeZip('project/mod_x.tar', self::MANIFEST);
        $runner = new RecordingProcessRunner(new ProcessResult(0, ''));
        $tester = $this->tester($runner);

        self::assertSame(Command::FAILURE, $tester->execute(['--zip' => 'mod_x.tar']));
        self::assertStringContainsString('mod_x.tar has no .zip extension', self::display($tester));
        self::assertSame([], $runner->command);
    }

    public function testBuildsTheZipFromATagAndInstallsIt(): void
    {
        $this->initGit();
        $this->git('tag', 'v1.0.0');
        $this->writeFile('project/mod_x.xml', str_replace('1.0.0', '1.1.0', self::MANIFEST));
        $runner = new RecordingProcessRunner(new ProcessResult(0, '[OK] Extension installed successfully.'));
        $tester = $this->tester($runner);

        self::assertSame(Command::SUCCESS, $tester->execute(['--tag' => 'v1.0.0']));
        self::assertFileExists($this->temporaryDirectory . '/project/dist/mod_x-1.0.0.zip');
        self::assertSame(file_get_contents($this->temporaryDirectory . '/project/dist/mod_x-1.0.0.zip'), $runner->packageContent);
        self::assertStringContainsString('mod_x 1.0.0 installed from tag v1.0.0', self::display($tester));
        self::assertStringNotContainsString('does not match', self::display($tester));
    }

    public function testWarnsWhenTheTagDoesNotMatchTheManifestVersion(): void
    {
        $this->initGit();
        $this->git('tag', 'v0.9.0');
        $tester = $this->tester(new RecordingProcessRunner(new ProcessResult(0, '')));

        self::assertSame(Command::SUCCESS, $tester->execute(['--tag' => 'v0.9.0']));
        self::assertStringContainsString('Tag v0.9.0 does not match the manifest version 1.0.0', self::display($tester));
    }

    public function testFailsForAnUnknownTag(): void
    {
        $this->initGit();
        $runner = new RecordingProcessRunner(new ProcessResult(0, ''));
        $tester = $this->tester($runner);

        self::assertSame(Command::FAILURE, $tester->execute(['--tag' => 'v1.0.0']));
        self::assertStringContainsString('Tag v1.0.0 not found', self::display($tester));
        self::assertSame([], $runner->command);
    }

    public function testRejectsTagTogetherWithZip(): void
    {
        $runner = new RecordingProcessRunner(new ProcessResult(0, ''));
        $tester = $this->tester($runner);

        self::assertSame(Command::INVALID, $tester->execute(['--tag' => 'v1.0.0', '--zip' => 'old.zip']));
        self::assertStringContainsString('--tag and --zip cannot be combined', self::display($tester));
        self::assertSame([], $runner->command);
    }

    public function testReportsLeftoversAfterInstallingWithoutDeleting(): void
    {
        $this->writeInstalledExtension(self::MANIFEST);
        $this->writeFile('joomla/modules/mod_x/tmpl/old.php');
        $this->writeFile('joomla/modules/mod_x/helper/a.php');
        $this->writeFile('joomla/modules/mod_x/helper/b.php');
        $tester = $this->tester(new RecordingProcessRunner(new ProcessResult(0, '')));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString(
            'Not removed by the Joomla installer: modules/mod_x/helper/ modules/mod_x/tmpl/old.php',
            self::display($tester),
        );
        self::assertStringNotContainsString('helper/a.php', self::display($tester));
        self::assertStringContainsString('jxt install --clean removes', self::display($tester));
        self::assertStringContainsString('mod_x 1.0.0 installed', self::display($tester));
        self::assertFileExists($this->joomla() . '/modules/mod_x/tmpl/old.php');
        self::assertFileExists($this->joomla() . '/modules/mod_x/helper/a.php');
    }

    public function testReportsNoLeftoversIfTheSiteMatchesThePackage(): void
    {
        $this->writeInstalledExtension(self::MANIFEST);
        $tester = $this->tester(new RecordingProcessRunner(new ProcessResult(0, '')));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringNotContainsString('Not removed', self::display($tester));
    }

    public function testCleanDeletesLeftoversDirectlyWithoutBridge(): void
    {
        $this->writeInstalledExtension(self::MANIFEST);
        $this->writeFile('joomla/modules/mod_x/tmpl/old.php');
        $this->writeFile('joomla/modules/mod_x/helper/a.php');
        $tester = $this->tester(new RecordingProcessRunner(new ProcessResult(0, '')));

        self::assertSame(Command::SUCCESS, $tester->execute(['--clean' => true]));
        self::assertStringContainsString('Removing leftovers of the Joomla installer:', self::display($tester));
        self::assertStringContainsString('- modules/mod_x/helper/', self::display($tester));
        self::assertStringContainsString('mod_x 1.0.0 installed, 2 leftovers removed', self::display($tester));
        self::assertFileDoesNotExist($this->joomla() . '/modules/mod_x/tmpl/old.php');
        self::assertDirectoryDoesNotExist($this->joomla() . '/modules/mod_x/helper');
        self::assertFileExists($this->joomla() . '/modules/mod_x/tmpl/default.php');
    }

    public function testCleanDeletesLeftoversViaBridge(): void
    {
        $this->writeInstalledExtension(self::MANIFEST);
        $this->writeFile('joomla/modules/mod_x/helper/a.php');
        $this->writeFile('joomla/' . Bridge::INSTALLED_MANIFEST, '<extension type="plugin" group="console"><name>x</name><version>'
            . (new Bridge())->bundledVersion() . '</version><files><folder plugin="jxt">services</folder></files></extension>');
        $runner = new ScriptedProcessRunner(new ProcessResult(0, 'installed'), new ProcessResult(0, 'Applied: 0 copied, 2 deleted.'));
        $plan = '';
        $runner->inspector = static function (string $argument) use (&$plan): void {
            $plan = is_file($argument . '/plan.json') ? (string) file_get_contents($argument . '/plan.json') : $plan;
        };
        $tester = $this->bridgeTester($runner);

        self::assertSame(Command::SUCCESS, $tester->execute(['--clean' => true]));
        self::assertSame(
            ['extension:install', 'jxt:apply'],
            array_map(static fn (array $arguments): string => $arguments[0], $runner->cliArguments()),
        );
        self::assertStringContainsString('"target": "modules/mod_x/helper/a.php"', $plan);
        self::assertStringContainsString('"target": "modules/mod_x/helper"', $plan);
        self::assertStringContainsString('Removing leftovers of the Joomla installer via jxt bridge:', self::display($tester));
        self::assertStringContainsString('1 leftover removed', self::display($tester));
    }

    public function testCleanWithoutLeftoversDoesNothing(): void
    {
        $this->writeInstalledExtension(self::MANIFEST);
        $tester = $this->tester(new RecordingProcessRunner(new ProcessResult(0, '')));

        self::assertSame(Command::SUCCESS, $tester->execute(['--clean' => true]));
        self::assertStringNotContainsString('Removing', self::display($tester));
        self::assertStringContainsString('mod_x 1.0.0 installed.', self::display($tester));
    }

    public function testCleanPointsToBridgeWhenDeletingFails(): void
    {
        $this->writeInstalledExtension(self::MANIFEST);
        $this->writeFile('joomla/modules/mod_x/helper/a.php');
        chmod($this->joomla() . '/modules/mod_x/helper', 0555);
        $tester = $this->tester(new RecordingProcessRunner(new ProcessResult(0, '')));

        try {
            self::assertSame(Command::FAILURE, $tester->execute(['--clean' => true]));
        } finally {
            chmod($this->joomla() . '/modules/mod_x/helper', 0755);
        }

        self::assertStringContainsString('Cannot delete', self::display($tester));
        self::assertStringContainsString('jxt bridge add', self::display($tester));
    }

    public function testComparesWithTheTagInsteadOfTheWorkingCopy(): void
    {
        $this->writeFile('project/tmpl/old.php');
        $this->initGit();
        $this->git('tag', 'v1.0.0');
        unlink($this->temporaryDirectory . '/project/tmpl/old.php');
        $this->writeInstalledExtension(self::MANIFEST);
        $this->writeFile('joomla/modules/mod_x/tmpl/old.php');

        $fromTag = $this->tester(new RecordingProcessRunner(new ProcessResult(0, '')));
        self::assertSame(Command::SUCCESS, $fromTag->execute(['--tag' => 'v1.0.0']));
        self::assertStringNotContainsString('Not removed', self::display($fromTag));

        $fromWorkingCopy = $this->tester(new RecordingProcessRunner(new ProcessResult(0, '')));
        self::assertSame(Command::SUCCESS, $fromWorkingCopy->execute([]));
        self::assertStringContainsString('modules/mod_x/tmpl/old.php', self::display($fromWorkingCopy));
    }

    public function testComparesWithTheContentOfAnExistingZip(): void
    {
        $this->writeZip('project/old.zip', self::MANIFEST);
        $this->writeInstalledExtension(self::MANIFEST);
        $this->writeFile('joomla/modules/mod_x/tmpl/extra.php');
        $tester = $this->tester(new RecordingProcessRunner(new ProcessResult(0, '')));

        self::assertSame(Command::SUCCESS, $tester->execute(['--zip' => 'old.zip']));
        self::assertStringContainsString('Not removed by the Joomla installer: modules/mod_x/tmpl/extra.php', self::display($tester));
    }

    public function testReportsTheMediaDirectoryOfThePreviouslyInstalledManifest(): void
    {
        $this->writeInstalledExtension(
            str_replace('</extension>', '<media destination="mod_x" folder="media"/></extension>', self::MANIFEST),
        );
        $this->writeFile('joomla/media/mod_x/css/x.css');
        $tester = $this->tester(new RecordingProcessRunner(new ProcessResult(0, '')));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('Not removed by the Joomla installer: media/mod_x/', self::display($tester));
    }

    public function testDoesNotReportLeftoversIfTheInstallationFails(): void
    {
        $this->writeInstalledExtension(self::MANIFEST);
        $this->writeFile('joomla/modules/mod_x/tmpl/old.php');
        $tester = $this->tester(new RecordingProcessRunner(new ProcessResult(1, '[ERROR] Unable to install extension')));

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringNotContainsString('Not removed', self::display($tester));
    }

    /**
     * Writes the installed manifest and the template file of `mod_x` to the Joomla directory.
     */
    private function writeInstalledExtension(string $manifest): void
    {
        $this->writeFile('joomla/modules/mod_x/mod_x.xml', $manifest);
        $this->writeFile('joomla/modules/mod_x/tmpl/default.php', '<?php');
    }

    /**
     * Writes a ZIP file with the manifest and a layout relative to the temporary directory.
     */
    private function writeZip(string $relativePath, string $manifest): void
    {
        $this->writeFile($relativePath);
        $zip = new ZipArchive();
        $zip->open($this->temporaryDirectory . '/' . $relativePath, ZipArchive::OVERWRITE);
        $zip->addFromString('mod_x.xml', $manifest);
        $zip->addFromString('tmpl/default.php', '<?php');
        $zip->close();
    }

    /**
     * Creates the command without global configuration for the user `anna`.
     */
    private function tester(RecordingProcessRunner $runner): CommandTester
    {
        return new CommandTester(new InstallCommand(
            $this->temporaryDirectory . '/project',
            new ConfigurationLoader(null),
            new JoomlaInstaller($runner, '/usr/bin/php8.5', $this->temporaryDirectory . '/tmp'),
            'anna',
        ));
    }

    /**
     * Creates the command whose installer and bridge run their processes with the runner.
     */
    private function bridgeTester(ScriptedProcessRunner $runner): CommandTester
    {
        $temporaryDirectory = $this->temporaryDirectory . '/tmp';

        return new CommandTester(new InstallCommand(
            $this->temporaryDirectory . '/project',
            new ConfigurationLoader(null),
            new JoomlaInstaller($runner, '/usr/bin/php8.5', $temporaryDirectory),
            'anna',
            bridge: new Bridge(
                new JoomlaCli($runner, '/usr/bin/php8.5'),
                new JoomlaInstaller($runner, '/usr/bin/php8.5', $temporaryDirectory),
                new PlanStager($temporaryDirectory),
                temporaryDirectory: $temporaryDirectory,
            ),
        ));
    }

    /**
     * Configures the project to run the Joomla CLI in a container that sees the site at `/var/www/html`.
     */
    private function useCliCommand(): void
    {
        $this->writeFile('project/.jxt.json', (string) json_encode([
            'joomla_root' => $this->joomla(),
            'cli_command' => ['docker', 'exec', 'web', 'php'],
            'cli_root' => '/var/www/html',
        ]));
    }

    /**
     * Returns the Joomla root directory.
     */
    private function joomla(): string
    {
        return $this->temporaryDirectory . '/joomla';
    }
}
