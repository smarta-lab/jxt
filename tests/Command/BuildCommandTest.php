<?php

/**
 * @copyright  (C) 2026 Carsten Volkwein
 * @license    MIT License; see LICENSE
 */

declare(strict_types=1);

namespace SmartA\Jxt\Tests\Command;

use PHPUnit\Framework\TestCase;
use SmartA\Jxt\Build\Preparation;
use SmartA\Jxt\Command\BuildCommand;
use SmartA\Jxt\Configuration\ConfigurationLoader;
use SmartA\Jxt\Install\ProcessResult;
use SmartA\Jxt\Tests\Build\ScriptedStepRunner;
use SmartA\Jxt\Tests\GitTrait;
use SmartA\Jxt\Tests\TemporaryDirectoryTrait;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use ZipArchive;

/**
 * Tests for the `jxt build` command.
 */
final class BuildCommandTest extends TestCase
{
    use CommandDisplayTrait;
    use GitTrait;
    use TemporaryDirectoryTrait;

    private string $project;

    #[\Override]
    protected function setUp(): void
    {
        $this->project = $this->createTemporaryDirectory();
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->removeTemporaryDirectory();
    }

    public function testBuildsZipIntoConfiguredDistDirectory(): void
    {
        $this->writeFile('mod_x.xml', <<<'XML'
            <extension type="module"><name>mod_x</name><version>1.0.0</version>
                <files><folder module="mod_x">tmpl</folder></files>
            </extension>
            XML);
        $this->writeFile('tmpl/default.php', '<?php');
        $this->writeFile('.jxt.json', '{"dist_dir": "build"}');

        $tester = new CommandTester(new BuildCommand($this->project, new ConfigurationLoader(null)));
        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertFileExists($this->project . '/build/mod_x-1.0.0.zip');
        self::assertStringContainsString('mod_x-1.0.0.zip', self::display($tester));
        self::assertStringContainsString('2 files', self::display($tester));
    }

    public function testRunsThePrepareStepsBeforeBuilding(): void
    {
        $this->writeFile('mod_x.xml', '<extension type="module"><name>mod_x</name><version>1.0.0</version>'
            . '<files><folder module="mod_x">tmpl</folder></files></extension>');
        $this->writeFile('.jxt.json', '{"prepare": [{"run": ["npm", "run", "build"]}]}');
        $runner = new ScriptedStepRunner(function (array $command, string $directory): ProcessResult {
            $this->writeFile('tmpl/default.php', '<?php');

            return new ProcessResult(0, '');
        });
        $tester = new CommandTester(new BuildCommand($this->project, new ConfigurationLoader(null), preparation: new Preparation($runner)));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertSame([[['npm', 'run', 'build'], $this->project, true]], $runner->runs);
        self::assertStringContainsString('> npm run build', self::display($tester));
        self::assertStringContainsString('2 files', self::display($tester));
    }

    public function testFailsIfAPrepareStepFails(): void
    {
        $this->writeFile('.jxt.json', '{"prepare": [{"run": ["npm", "run", "build"]}]}');
        $runner = new ScriptedStepRunner(static fn (): ProcessResult => new ProcessResult(2, ''));
        $tester = new CommandTester(new BuildCommand($this->project, new ConfigurationLoader(null), preparation: new Preparation($runner)));

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('Prepare step failed (exit code 2): npm run build', self::display($tester));
        self::assertDirectoryDoesNotExist($this->project . '/dist');
    }

    public function testRefusesPrepareStepsViaSudo(): void
    {
        $this->writeFile('.jxt.json', '{"prepare": [{"run": ["npm", "run", "build"]}]}');
        $runner = new ScriptedStepRunner();
        $tester = new CommandTester(new BuildCommand($this->project, new ConfigurationLoader(null), preparation: new Preparation($runner, true)));

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('Please run jxt without sudo', self::display($tester));
        self::assertSame([], $runner->runs);
    }

    public function testIgnoresContradictingKeysOfTheTestSite(): void
    {
        $this->writeFile('mod_x.xml', <<<'XML'
            <extension type="module"><name>mod_x</name><version>1.0.0</version>
                <files><folder module="mod_x">tmpl</folder></files>
            </extension>
            XML);
        $this->writeFile('tmpl/default.php', '<?php');
        $this->writeFile('.jxt.json', '{"cli_command": ["ddev", "exec", "php"], "web_user": "www-data"}');

        $tester = new CommandTester(new BuildCommand($this->project, new ConfigurationLoader(null)));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertFileExists($this->project . '/dist/mod_x-1.0.0.zip');
    }

    public function testWarnsAboutUnlistedMediaFolder(): void
    {
        $this->writeFile('mod_x.xml', <<<'XML'
            <extension type="module"><name>mod_x</name>
                <files><folder module="mod_x">tmpl</folder></files>
                <media destination="mod_x" folder="media"><folder>css</folder></media>
            </extension>
            XML);
        $this->writeFile('tmpl/default.php', '<?php');
        $this->writeFile('media/css/site.css');
        $this->writeFile('media/svg/icon.svg');

        $tester = new CommandTester(new BuildCommand($this->project, new ConfigurationLoader(null)));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('media/svg/', self::display($tester));
        self::assertStringContainsString('Not listed in the manifest', self::display($tester));
    }

    public function testBuildsFromConfiguredManifestInSubdirectory(): void
    {
        $this->writeFile('extension/mod_x.xml', <<<'XML'
            <extension type="module"><name>mod_x</name><version>1.0.0</version>
                <files><folder module="mod_x">tmpl</folder></files>
                <media destination="mod_x" folder="media"><folder>css</folder></media>
            </extension>
            XML);
        $this->writeFile('extension/tmpl/default.php', '<?php');
        $this->writeFile('extension/media/css/site.css');
        $this->writeFile('extension/media/svg/icon.svg');
        $this->writeFile('.jxt.json', '{"manifest": "extension/mod_x.xml"}');

        $tester = new CommandTester(new BuildCommand($this->project, new ConfigurationLoader(null)));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertSame(
            ['media/css/site.css', 'mod_x.xml', 'tmpl/default.php'],
            $this->zipEntries($this->project . '/dist/mod_x-1.0.0.zip'),
        );
        self::assertStringContainsString('media/svg/', self::display($tester));
    }

    public function testReportsErrorsWithoutStackTrace(): void
    {
        $tester = new CommandTester(new BuildCommand($this->project, new ConfigurationLoader(null)));
        $exitCode = $tester->execute([]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('No Joomla manifest', self::display($tester));
    }

    public function testBuildsFromTagWithoutUncommittedChanges(): void
    {
        $this->writeFile('mod_x.xml', self::manifest('1.0.0'));
        $this->writeFile('tmpl/default.php', 'tagged');
        $this->initGit();
        $this->git('tag', 'v1.0.0');
        $this->writeFile('mod_x.xml', self::manifest('1.1.0'));
        $this->writeFile('tmpl/default.php', 'changed');
        $this->writeFile('tmpl/new.php', 'untracked');

        $tester = new CommandTester(new BuildCommand($this->project, new ConfigurationLoader(null)));

        self::assertSame(Command::SUCCESS, $tester->execute(['--tag' => 'v1.0.0']));
        self::assertSame(['mod_x.xml', 'tmpl/default.php'], $this->zipEntries($this->project . '/dist/mod_x-1.0.0.zip'));
        self::assertSame('tagged', $this->zipContent($this->project . '/dist/mod_x-1.0.0.zip', 'tmpl/default.php'));
        self::assertStringContainsString('dist/mod_x-1.0.0.zip from tag v1.0.0', self::display($tester));
        self::assertStringNotContainsString('does not match', self::display($tester));
    }

    public function testWarnsWhenTagDoesNotMatchManifestVersion(): void
    {
        $this->writeFile('mod_x.xml', self::manifest('1.1.0'));
        $this->writeFile('tmpl/default.php');
        $this->initGit();
        $this->git('tag', 'v1.0.0');

        $tester = new CommandTester(new BuildCommand($this->project, new ConfigurationLoader(null)));

        self::assertSame(Command::SUCCESS, $tester->execute(['--tag' => 'v1.0.0']));
        self::assertFileExists($this->project . '/dist/mod_x-1.1.0.zip');
        self::assertStringContainsString('Tag v1.0.0 does not match the manifest version 1.1.0', self::display($tester));
    }

    public function testFailsForUnknownTag(): void
    {
        $this->writeFile('mod_x.xml', self::manifest('1.0.0'));
        $this->writeFile('tmpl/default.php');
        $this->initGit();

        $tester = new CommandTester(new BuildCommand($this->project, new ConfigurationLoader(null)));

        self::assertSame(Command::FAILURE, $tester->execute(['--tag' => 'v1.0.0']));
        self::assertStringContainsString('Tag v1.0.0 not found', self::display($tester));
        self::assertDirectoryDoesNotExist($this->project . '/dist');
    }

    /**
     * Returns a module manifest with the version and a `tmpl` folder.
     */
    private static function manifest(string $version): string
    {
        return <<<XML
            <extension type="module"><name>mod_x</name><version>{$version}</version>
                <files><folder module="mod_x">tmpl</folder></files>
            </extension>
            XML;
    }

    /**
     * Returns the content of an entry of a ZIP file.
     */
    private function zipContent(string $path, string $entry): string
    {
        $zip = new ZipArchive();
        self::assertTrue($zip->open($path));
        $content = $zip->getFromName($entry);
        $zip->close();
        self::assertIsString($content);

        return $content;
    }

    /**
     * Returns the sorted entry names of a ZIP file.
     *
     * @return list<string>
     */
    private function zipEntries(string $path): array
    {
        $zip = new ZipArchive();
        self::assertTrue($zip->open($path));
        $entries = [];

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $entries[] = (string) $zip->getNameIndex($index);
        }

        $zip->close();
        sort($entries);

        return $entries;
    }
}
