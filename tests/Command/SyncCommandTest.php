<?php

declare(strict_types=1);

namespace SmartA\Jxt\Tests\Command;

use PHPUnit\Framework\TestCase;
use SmartA\Jxt\Command\SyncCommand;
use SmartA\Jxt\Configuration\ConfigurationLoader;
use SmartA\Jxt\Sync\SyncExecutor;
use SmartA\Jxt\Tests\TemporaryDirectoryTrait;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\ApplicationTester;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Tests for the `jxt sync` command.
 */
final class SyncCommandTest extends TestCase
{
    use CommandDisplayTrait;
    use TemporaryDirectoryTrait;

    #[\Override]
    protected function setUp(): void
    {
        $this->createTemporaryDirectory();
        $this->writeFile('project/mod_x.xml', $this->manifestXml('1.0.0'));
        $this->writeFile('project/tmpl/default.php', '<?php');
        $this->writeFile('project/.jxt.json', (string) json_encode(['joomla_root' => $this->joomla()]));
        $this->writeFile('joomla/configuration.php', '<?php');
        $this->writeFile('joomla/modules/mod_x/mod_x.xml', $this->manifestXml('1.0.0'));
        $this->writeFile('joomla/modules/mod_x/tmpl/old.php', '<?php');
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->removeTemporaryDirectory();
    }

    public function testSyncsProjectToJoomla(): void
    {
        $tester = $this->tester();

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertFileExists($this->joomla() . '/modules/mod_x/tmpl/default.php');
        self::assertFileDoesNotExist($this->joomla() . '/modules/mod_x/tmpl/old.php');
        self::assertStringContainsString('+ modules/mod_x/tmpl/default.php', self::display($tester));
        self::assertStringContainsString('- modules/mod_x/tmpl/old.php', self::display($tester));
        self::assertStringContainsString('1 copied, 1 deleted, 1 unchanged', self::display($tester));
    }

    public function testDryRunChangesNothing(): void
    {
        $command = new SyncCommand(
            $this->temporaryDirectory . '/project',
            new ConfigurationLoader(null),
            new SyncExecutor(),
        );
        $application = new Application();
        $application->setAutoExit(false);
        $application->addCommand($command);
        $application->setDefaultCommand((string) $command->getName(), true);
        $tester = new ApplicationTester($application);

        self::assertSame(Command::SUCCESS, $tester->run(['--dry-run' => true]));
        self::assertFileDoesNotExist($this->joomla() . '/modules/mod_x/tmpl/default.php');
        self::assertFileExists($this->joomla() . '/modules/mod_x/tmpl/old.php');
        self::assertStringContainsString('Dry run', self::display($tester));
        self::assertStringContainsString('+ modules/mod_x/tmpl/default.php', self::display($tester));
    }

    public function testReportsUpToDateExtension(): void
    {
        $this->tester()->execute([]);
        $tester = $this->tester();

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('0 copied, 0 deleted, 2 unchanged', self::display($tester));
    }

    public function testPointsToCacheRefreshWhenVersionChanged(): void
    {
        $this->writeFile('project/mod_x.xml', $this->manifestXml('1.1.0'));
        $tester = $this->tester();

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('1.0.0 → 1.1.0', self::display($tester));
        self::assertStringContainsString('Refresh Cache', self::display($tester));
    }

    public function testWarnsAboutInstallerChanges(): void
    {
        $this->writeFile(
            'project/mod_x.xml',
            $this->manifestXml('1.0.0', '<updateservers><server>https://example.org/u.xml</server></updateservers>'),
        );
        $tester = $this->tester();

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('updateservers', self::display($tester));
        self::assertStringContainsString('jxt install', self::display($tester));
    }

    public function testWarnsAboutUnlistedLanguageFile(): void
    {
        $languages = '<languages><language tag="de-DE">language/de-DE/mod_x.ini</language></languages>';
        $this->writeFile('project/mod_x.xml', $this->manifestXml('1.0.0', $languages));
        $this->writeFile('project/language/de-DE/mod_x.ini');
        $this->writeFile('project/language/de-DE/mod_x.sys.ini');
        $tester = $this->tester();

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('language/de-DE/mod_x.sys.ini', self::display($tester));
        self::assertStringContainsString('Not listed in the manifest', self::display($tester));
    }

    public function testSyncsFromConfiguredManifestInSubdirectory(): void
    {
        unlink($this->temporaryDirectory . '/project/mod_x.xml');
        unlink($this->temporaryDirectory . '/project/tmpl/default.php');
        rmdir($this->temporaryDirectory . '/project/tmpl');
        $this->writeFile('project/extension/mod_x.xml', $this->manifestXml('1.0.0'));
        $this->writeFile('project/extension/tmpl/default.php', '<?php // extension');
        $this->writeFile(
            'project/.jxt.json',
            (string) json_encode(['joomla_root' => $this->joomla(), 'manifest' => 'extension/mod_x.xml']),
        );
        $tester = $this->tester();

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringEqualsFile($this->joomla() . '/modules/mod_x/tmpl/default.php', '<?php // extension');
        self::assertFileDoesNotExist($this->joomla() . '/modules/mod_x/tmpl/old.php');
        self::assertStringContainsString('1 copied, 1 deleted, 1 unchanged', self::display($tester));
    }

    public function testFailsWithoutInstalledManifest(): void
    {
        unlink($this->joomla() . '/modules/mod_x/mod_x.xml');
        $tester = $this->tester();

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('Please install the extension once', self::display($tester));
    }

    public function testFailsWithoutJoomlaRoot(): void
    {
        $this->writeFile('project/.jxt.json', '{}');
        $tester = $this->tester();

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('joomla_root', self::display($tester));
    }

    public function testFailsForDirectoryWithoutJoomla(): void
    {
        unlink($this->joomla() . '/configuration.php');
        $tester = $this->tester();

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('configuration.php', self::display($tester));
    }

    /**
     * Creates the command without global configuration.
     */
    private function tester(): CommandTester
    {
        return new CommandTester(new SyncCommand(
            $this->temporaryDirectory . '/project',
            new ConfigurationLoader(null),
            new SyncExecutor(),
        ));
    }

    /**
     * Returns the manifest of `mod_x` in the given version.
     */
    private function manifestXml(string $version, string $extra = ''): string
    {
        return '<extension type="module"><name>mod_x</name><version>' . $version . '</version>' . $extra
            . '<files><folder module="mod_x">tmpl</folder></files></extension>';
    }

    /**
     * Returns the Joomla root directory.
     */
    private function joomla(): string
    {
        return $this->temporaryDirectory . '/joomla';
    }
}
