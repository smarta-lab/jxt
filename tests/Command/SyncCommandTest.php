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
use SmartA\Jxt\Build\Preparation;
use SmartA\Jxt\Command\SyncCommand;
use SmartA\Jxt\Configuration\ConfigurationLoader;
use SmartA\Jxt\Install\JoomlaCli;
use SmartA\Jxt\Install\JoomlaInstaller;
use SmartA\Jxt\Install\ProcessResult;
use SmartA\Jxt\Sync\SyncExecutor;
use SmartA\Jxt\Tests\Build\ScriptedStepRunner;
use SmartA\Jxt\Tests\Install\ScriptedProcessRunner;
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
    use TestSiteConfigurationTrait;
    use TemporaryDirectoryTrait;

    #[\Override]
    protected function setUp(): void
    {
        $this->createTemporaryDirectory();
        $this->writeFile('project/mod_x.xml', $this->manifestXml('1.0.0'));
        $this->writeFile('project/tmpl/default.php', '<?php');
        $this->writeSites(['joomla_root' => $this->joomla()]);
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
        $command = $this->command(new ScriptedProcessRunner(new ProcessResult(0, '')));
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

    public function testRunsThePrepareStepsWithTheirReplacementsForSync(): void
    {
        $this->writeFile('project/.jxt.json', (string) json_encode([
            'prepare' => [
                ['run' => ['npm', 'run', 'minify'], 'sync' => ['npm', 'run', 'minify:copy']],
                ['run' => ['npm', 'run', 'images'], 'sync' => false],
            ],
        ]));
        $stepRunner = new ScriptedStepRunner(function (array $command, string $directory): ProcessResult {
            file_put_contents($directory . '/tmpl/generated.php', '<?php');

            return new ProcessResult(0, '');
        });
        $tester = new CommandTester($this->command(new ScriptedProcessRunner(new ProcessResult(0, '')), $stepRunner));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertSame([[['npm', 'run', 'minify:copy'], $this->temporaryDirectory . '/project', true]], $stepRunner->runs);
        self::assertFileExists($this->joomla() . '/modules/mod_x/tmpl/generated.php');
        self::assertStringContainsString('> npm run minify:copy (sync, instead of npm run minify)', self::display($tester));
        self::assertStringContainsString('Skipped for sync: npm run images', self::display($tester));
    }

    public function testRunsThePrepareStepsForADryRun(): void
    {
        $this->writeFile('project/.jxt.json', (string) json_encode(['prepare' => [['run' => ['npm', 'run', 'css']]]]));
        $stepRunner = new ScriptedStepRunner();
        $tester = new CommandTester($this->command(new ScriptedProcessRunner(new ProcessResult(0, '')), $stepRunner));

        self::assertSame(Command::SUCCESS, $tester->execute(['--dry-run' => true]));
        self::assertSame([['npm', 'run', 'css']], $stepRunner->commands());
        self::assertFileDoesNotExist($this->joomla() . '/modules/mod_x/tmpl/default.php');
    }

    public function testFailsWithoutSyncingIfAPrepareStepFails(): void
    {
        $this->writeFile('project/.jxt.json', (string) json_encode(['prepare' => [['run' => ['npm', 'run', 'css']]]]));
        $stepRunner = new ScriptedStepRunner(static fn (): ProcessResult => new ProcessResult(1, ''));
        $tester = new CommandTester($this->command(new ScriptedProcessRunner(new ProcessResult(0, '')), $stepRunner));

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('Prepare step failed (exit code 1): npm run css', self::display($tester));
        self::assertFileExists($this->joomla() . '/modules/mod_x/tmpl/old.php');
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

    public function testSkipsLanguageNotInstalledOnTheServer(): void
    {
        $languages = '<languages><language tag="pt-BR">language/pt-BR/mod_x.ini</language></languages>';
        $this->writeFile('project/mod_x.xml', $this->manifestXml('1.0.0', $languages));
        $this->writeFile('project/language/pt-BR/mod_x.ini');
        $tester = $this->tester();

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertDirectoryDoesNotExist($this->joomla() . '/language/pt-BR');
        self::assertStringContainsString('Skipped, language not installed', self::display($tester));
        self::assertStringContainsString('language/pt-BR/mod_x.ini', self::display($tester));
    }

    public function testSyncsLibraryWithItsManifestOutsideTheLibraryDirectory(): void
    {
        $manifest = '<extension type="library"><name>Example</name><libraryname>acme/example</libraryname>'
            . '<version>1.0.0</version><files folder="lib"><folder>src</folder></files></extension>';
        $this->writeFile('project/.jxt.json', (string) json_encode(['manifest' => 'lib_example.xml']));
        $this->writeFile('project/lib_example.xml', $manifest);
        $this->writeFile('project/lib/src/Example.php', '<?php');
        $this->writeFile('joomla/administrator/manifests/libraries/acme/example.xml', $manifest);
        $this->writeFile('joomla/libraries/acme/example/src/Old.php', '<?php');
        $this->writeFile('joomla/libraries/acme/other/Other.php', '<?php');
        $tester = $this->tester();

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertFileExists($this->joomla() . '/libraries/acme/example/src/Example.php');
        self::assertFileDoesNotExist($this->joomla() . '/libraries/acme/example/src/Old.php');
        self::assertFileExists($this->joomla() . '/libraries/acme/other/Other.php');
        self::assertStringContainsString('lib_acme_example → ' . $this->joomla(), self::display($tester));
        self::assertStringContainsString('1 copied, 1 deleted, 1 unchanged', self::display($tester));
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
            (string) json_encode(['manifest' => 'extension/mod_x.xml']),
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
        self::assertStringNotContainsString('jxt bridge add', self::display($tester));
    }

    public function testFailsForFileMissingFromProjectWithoutPointingToBridge(): void
    {
        unlink($this->temporaryDirectory . '/project/tmpl/default.php');
        rmdir($this->temporaryDirectory . '/project/tmpl');
        $tester = $this->tester();

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('Directory tmpl listed in the manifest is missing', self::display($tester));
        self::assertStringNotContainsString('jxt bridge add', self::display($tester));
    }

    public function testPointsToBridgeWhenWritingFails(): void
    {
        chmod($this->joomla() . '/modules/mod_x/tmpl', 0555);
        $tester = $this->tester();

        try {
            self::assertSame(Command::FAILURE, $tester->execute([]));
            self::assertStringContainsString('jxt bridge add', self::display($tester));
        } finally {
            chmod($this->joomla() . '/modules/mod_x/tmpl', 0755);
        }
    }

    public function testFailsWithoutTestSite(): void
    {
        unlink($this->globalConfigFile());
        $tester = $this->tester();

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('No test site is configured in', self::display($tester));
    }

    public function testUsesTheSiteOfTheOptionAndNamesIt(): void
    {
        $this->writeSites(['joomla_root' => $this->temporaryDirectory . '/other'], ['id' => 'ddev', 'joomla_root' => $this->joomla()]);
        $tester = $this->tester();

        self::assertSame(Command::SUCCESS, $tester->execute(['--site' => 'ddev']));
        self::assertStringContainsString(sprintf('mod_x → %s (site "ddev" via --site)', $this->joomla()), self::display($tester));
        self::assertFileDoesNotExist($this->joomla() . '/modules/mod_x/tmpl/old.php');
    }

    public function testFailsForUnknownSite(): void
    {
        $tester = $this->tester();

        self::assertSame(Command::FAILURE, $tester->execute(['--site' => 'j5']));
        self::assertStringContainsString('There is no site "j5" (selected via --site)', self::display($tester));
    }

    public function testFailsForDirectoryWithoutJoomla(): void
    {
        unlink($this->joomla() . '/configuration.php');
        $tester = $this->tester();

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('configuration.php', self::display($tester));
    }

    public function testAppliesPlanViaInstalledBridge(): void
    {
        $this->installBridge($this->bundledVersion());
        $runner = new ScriptedProcessRunner(new ProcessResult(0, "Applied: 1 copied, 1 deleted.\n"));
        $staged = '';
                $runner->inspector = static function (string $argument) use (&$staged): void {
                    $staged = (string) file_get_contents($argument . '/plan.json');
                };
        $tester = $this->tester($runner);

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertSame([['jxt:apply']], array_map(static fn (array $arguments): array => [$arguments[0]], $runner->cliArguments()));
        self::assertStringContainsString('modules/mod_x/tmpl/old.php', $staged);
        self::assertFileExists($this->joomla() . '/modules/mod_x/tmpl/old.php');
        self::assertStringContainsString('mod_x → ' . $this->joomla() . ' via jxt bridge', self::display($tester));
        self::assertStringContainsString('1 copied, 1 deleted, 1 unchanged', self::display($tester));
        self::assertStringNotContainsString('Applied:', self::display($tester));
    }

    public function testUpdatesBridgeOfAnotherVersionFirst(): void
    {
        $this->installBridge('0.0.1');
        $runner = new ScriptedProcessRunner(new ProcessResult(0, 'installed'), new ProcessResult(0, 'Applied: 1 copied, 1 deleted.'));
        $tester = $this->tester($runner);

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertSame('extension:install', $runner->cliArguments()[0][0]);
        self::assertSame('jxt:apply', $runner->cliArguments()[1][0]);
        self::assertStringContainsString('Updating the jxt bridge on the site: 0.0.1 → ' . $this->bundledVersion(), self::display($tester));
    }

    public function testUsesNewerBridgeInsteadOfDowngrading(): void
    {
        $this->installBridge('99.0.0');
        $runner = new ScriptedProcessRunner(new ProcessResult(0, 'Applied: 1 copied, 1 deleted.'));
        $tester = $this->tester($runner);

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertSame(['jxt:apply'], [$runner->cliArguments()[0][0]]);
        self::assertCount(1, $runner->commands);
        self::assertStringContainsString('jxt bridge 99.0.0 on the site is newer than the bundled', self::display($tester));
    }

    public function testShowsSudoersRulesWhenSudoRefusesTheBridge(): void
    {
        $this->installBridge($this->bundledVersion());
        $this->writeSites(['joomla_root' => $this->joomla(), 'web_user' => 'www-data']);
        $tester = $this->tester(new ScriptedProcessRunner(new ProcessResult(1, "sudo: a password is required\n")));

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('jxt\:apply *', $tester->getDisplay());
        self::assertStringContainsString('extension\:remove -n *', $tester->getDisplay());
    }

    public function testDoesNotPointToCacheRefreshViaBridge(): void
    {
        $this->installBridge($this->bundledVersion());
        $this->writeFile('project/mod_x.xml', $this->manifestXml('1.1.0'));
        $tester = $this->tester(new ScriptedProcessRunner(new ProcessResult(0, 'Applied: 1 copied, 1 deleted.')));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringNotContainsString('Refresh Cache', self::display($tester));
    }

    /**
     * Creates the command without global configuration; the bridge runs its processes with the runner.
     */
    private function command(ScriptedProcessRunner $runner, ?ScriptedStepRunner $stepRunner = null): SyncCommand
    {
        $temporaryDirectory = $this->temporaryDirectory . '/tmp';

        if (!is_dir($temporaryDirectory)) {
            mkdir($temporaryDirectory);
        }

        return new SyncCommand(
            $this->temporaryDirectory . '/project',
            new ConfigurationLoader($this->globalConfigFile()),
            new SyncExecutor(),
            new Bridge(
                new JoomlaCli($runner, '/usr/bin/php8.5'),
                new JoomlaInstaller($runner, '/usr/bin/php8.5', $temporaryDirectory),
                new PlanStager($temporaryDirectory),
                temporaryDirectory: $temporaryDirectory,
            ),
            'anna',
            preparation: new Preparation($stepRunner ?? new ScriptedStepRunner()),
        );
    }

    /**
     * Creates a tester for the command.
     */
    private function tester(?ScriptedProcessRunner $runner = null): CommandTester
    {
        return new CommandTester($this->command($runner ?? new ScriptedProcessRunner(new ProcessResult(0, ''))));
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
     * Returns the version of the bundled bridge.
     */
    private function bundledVersion(): string
    {
        return (new Bridge())->bundledVersion();
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
