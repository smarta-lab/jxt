<?php

declare(strict_types=1);

namespace SmartA\Jxt\Tests\Command;

use PHPUnit\Framework\TestCase;
use SmartA\Jxt\Command\InstallCommand;
use SmartA\Jxt\Configuration\ConfigurationLoader;
use SmartA\Jxt\Install\JoomlaInstaller;
use SmartA\Jxt\Install\ProcessResult;
use SmartA\Jxt\Tests\GitTrait;
use SmartA\Jxt\Tests\Install\RecordingProcessRunner;
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
     * Returns the Joomla root directory.
     */
    private function joomla(): string
    {
        return $this->temporaryDirectory . '/joomla';
    }
}
