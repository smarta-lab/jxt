<?php

declare(strict_types=1);

namespace SmartA\Jxt\Tests\Command;

use PHPUnit\Framework\TestCase;
use SmartA\Jxt\Check\CheckContextFactory;
use SmartA\Jxt\Command\CheckCommand;
use SmartA\Jxt\Configuration\ConfigurationLoader;
use SmartA\Jxt\Tests\Check\CheckProjectTrait;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Tests for the `jxt check` command: output, options and exit codes.
 */
final class CheckCommandTest extends TestCase
{
    use CheckProjectTrait;
    use CommandDisplayTrait;

    #[\Override]
    protected function setUp(): void
    {
        $this->createProject();
        $this->writeFile('.editorconfig', "root = true\n\n[*]\ninsert_final_newline = true\n");
        $this->writeFile('tmpl/default.php', "<?php\n");
        $this->writeFile('language/en-GB/mod_example.ini', "MOD_EXAMPLE=\"Example\"\n");
        $this->writeFile('language/de-DE/mod_example.ini', "MOD_EXAMPLE=\"Beispiel\"\n");
        $this->writeFile('media/joomla.asset.json', "{}\n");
        $this->writeFile('media/css/site.css', "a {}\n");
        $this->writeFile('media/js/site.js', "a();\n");
        $this->writeFile('CHANGELOG.md', "# Changelog\n\n## [Unreleased]\n\n## [1.1.0] - 2026-10-16\n\n- First\n");
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->removeTemporaryDirectory();
    }

    public function testPassesAndShowsTheMode(): void
    {
        $tester = $this->tester();

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        $display = self::display($tester);
        self::assertStringContainsString(
            'Checking mod_example 1.1.0: pre-release check, 1.1.0 is released according to CHANGELOG.md (no Git repository).',
            $display,
        );
        self::assertStringContainsString('INFO [Unreleased] has 0 entries [changelog/unreleased]', $display);
        self::assertStringContainsString('0 error(s), 0 warning(s), 2 notice(s)', $display);
    }

    public function testErrorsFail(): void
    {
        $this->writeFile('tmpl/default.php', '<?php');
        $tester = $this->tester();

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString(
            'ERROR tmpl/default.php: no newline at end of file [editorconfig/final-newline]',
            self::display($tester),
        );
    }

    public function testWarningsFailOnlyWithStrict(): void
    {
        $this->writeFile('media/css/.DS_Store');

        self::assertSame(Command::SUCCESS, $this->tester()->execute([]));

        $tester = $this->tester();
        self::assertSame(Command::FAILURE, $tester->execute(['--strict' => true]));
        self::assertStringContainsString('warnings count as errors with --strict', self::display($tester));
    }

    public function testReleaseOnlyProblemsFailOnlyWithRelease(): void
    {
        $this->writeFile('CHANGELOG.md', "## [Unreleased]\n\n- New\n\n## [1.0.0] - 2026-09-01\n");

        self::assertSame(Command::SUCCESS, $this->tester()->execute(['--strict' => true]));
        self::assertSame(Command::FAILURE, $this->tester()->execute(['--release' => true]));
    }

    public function testReleaseInAPreCheckIsPointedOut(): void
    {
        $tester = $this->tester();

        self::assertSame(Command::SUCCESS, $tester->execute(['--release' => true]));
        self::assertStringContainsString(
            '--release has no effect in a pre-release check (1.1.0 is released according to CHANGELOG.md (no Git repository)).',
            self::display($tester),
        );
        self::assertStringNotContainsString('--release has no effect', self::display($this->executed([])));
    }

    public function testExcludeAndIncludeFromProjectAndCommandLine(): void
    {
        $this->writeFile('tmpl/default.php', '<?php');
        $this->writeFile('.jxt.json', '{"check_exclude": ["editorconfig"]}');
        $tester = $this->tester();

        self::assertSame(Command::SUCCESS, $tester->execute(['--exclude' => ['changelog,assets']]));
        $display = self::display($tester);
        self::assertStringContainsString('editorconfig ------------ excluded', $display);
        self::assertStringContainsString('changelog --------- excluded', $display);

        self::assertSame(Command::FAILURE, $this->tester()->execute(['--include' => ['editorconfig/final-newline']]));
    }

    public function testExcludeAllRunsOnlyTheIncludedChecks(): void
    {
        $this->writeFile('tmpl/default.php', '<?php');
        $this->writeFile('.jxt.json', '{"check_exclude": ["editorconfig/final-newline"]}');
        $tester = $this->tester();

        self::assertSame(Command::SUCCESS, $tester->execute(['--exclude' => ['all'], '--include' => ['editorconfig/charset']]));
        self::assertStringContainsString('manifest -------- excluded', self::display($tester));
        self::assertStringContainsString('editorconfig ------------ OK', self::display($tester));

        self::assertSame(Command::FAILURE, $this->tester()->execute(['--include' => ['all']]));
    }

    public function testUnknownCheckIsAnInvalidCall(): void
    {
        $tester = $this->tester();

        self::assertSame(Command::INVALID, $tester->execute(['--exclude' => ['manifest/foo']]));
        self::assertStringContainsString('Unknown check "manifest/foo"', self::display($tester));
    }

    public function testMissingManifestIsAnInvalidCall(): void
    {
        unlink($this->temporaryDirectory . '/mod_example.xml');
        $tester = $this->tester();

        self::assertSame(Command::INVALID, $tester->execute([]));
        self::assertStringContainsString('No Joomla manifest found', self::display($tester));
    }

    public function testChecksTheFilesOfATagInsteadOfTheWorkingCopy(): void
    {
        $this->initGit();
        $this->git('tag', 'v1.1.0');
        $this->writeFile('tmpl/default.php', '<?php');
        $tester = $this->tester();

        self::assertSame(Command::SUCCESS, $tester->execute(['--tag' => 'v1.1.0', '--strict' => true]));
        self::assertStringContainsString(
            'Checking mod_example 1.1.0: release check, files of tag v1.1.0.',
            self::display($tester),
        );
        self::assertSame(Command::FAILURE, $this->tester()->execute([]));
    }

    public function testReportsProblemsInTheFilesOfATag(): void
    {
        $this->writeFile('tmpl/default.php', '<?php');
        $this->initGit();
        $this->git('tag', 'v1.1.0');
        $this->writeFile('tmpl/default.php', "<?php\n");
        $tester = $this->tester();

        self::assertSame(Command::FAILURE, $tester->execute(['--tag' => 'v1.1.0']));
        self::assertStringContainsString(
            'ERROR tmpl/default.php: no newline at end of file [editorconfig/final-newline]',
            self::display($tester),
        );
    }

    public function testTagThatDoesNotNameTheManifestVersionIsAnError(): void
    {
        $this->initGit();
        $this->git('tag', 'v1.0.0');
        $tester = $this->tester();

        self::assertSame(Command::FAILURE, $tester->execute(['--tag' => 'v1.0.0']));
        self::assertStringContainsString(
            'ERROR mod_example.xml: tag v1.0.0 does not name the manifest version 1.1.0 [manifest/tag-version]',
            self::display($tester),
        );
    }

    public function testTakesTheConfigurationFromTheTag(): void
    {
        $this->writeFile('tmpl/default.php', '<?php');
        $this->writeFile('.jxt.json', '{"check_exclude": ["editorconfig/final-newline"]}');
        $this->initGit();
        $this->git('tag', 'v1.1.0');
        $this->writeFile('.jxt.json', '{}');

        self::assertSame(Command::SUCCESS, $this->tester()->execute(['--tag' => 'v1.1.0']));
    }

    public function testUnknownTagIsAnInvalidCall(): void
    {
        $this->initGit();
        $tester = $this->tester();

        self::assertSame(Command::INVALID, $tester->execute(['--tag' => 'v9.9.9']));
        self::assertStringContainsString('Tag v9.9.9 not found', self::display($tester));
    }

    public function testTagWithoutGitIsAnInvalidCall(): void
    {
        $tester = $this->tester();

        self::assertSame(Command::INVALID, $tester->execute(['--tag' => 'v1.1.0']));
        self::assertStringContainsString('--tag needs Git', self::display($tester));
    }

    public function testGithubFormatPrintsAnnotations(): void
    {
        $this->writeFile('.editorconfig', "root = true\n\n[*]\ninsert_final_newline = true\ntrim_trailing_whitespace = true\n");
        $this->writeFile('tmpl/default.php', "<?php\necho 1; ");
        $this->writeFile('media/css/.DS_Store');
        $tester = $this->tester();

        self::assertSame(Command::FAILURE, $tester->execute(['--format' => 'github', '--release' => true]));
        self::assertSame(
            [
                'Checking mod_example 1.1.0: pre-release check, 1.1.0 is released according to CHANGELOG.md (no Git repository).',
                '::notice::--release has no effect in a pre-release check '
                    . '(1.1.0 is released according to CHANGELOG.md (no Git repository)).',
                '::warning file=media/css/.DS_Store,title=manifest/stray-files::would be part of the extension; '
                    . 'delete it or move it out of the listed directory',
                '::error file=tmpl/default.php,title=editorconfig/final-newline::no newline at end of file',
                '::error file=tmpl/default.php,line=2,title=editorconfig/trailing-whitespace::'
                    . 'trailing whitespace in 1 line(s), first: line 2',
                '::notice title=changelog/unreleased::[Unreleased] has 0 entries',
                '::notice title=assets/baseline::asset versions not compared: no Git repository',
                '2 error(s), 1 warning(s), 2 notice(s).',
            ],
            explode("\n", trim($tester->getDisplay())),
        );
    }

    public function testGithubFormatGivesPathsRelativeToTheRepositoryRoot(): void
    {
        $repository = $this->temporaryDirectory;
        rename($repository, $repository . '-project');
        mkdir($repository);
        rename($repository . '-project', $repository . '/extension');
        $this->writeFile('extension/tmpl/default.php', '<?php');
        $this->initGit();
        $tester = $this->tester($repository . '/extension');

        self::assertSame(Command::FAILURE, $tester->execute(['--format' => 'github']));
        self::assertStringContainsString(
            '::error file=extension/tmpl/default.php,title=editorconfig/final-newline::no newline at end of file',
            $tester->getDisplay(),
        );
    }

    public function testGithubFormatReportsAnInvalidCallAsAnnotation(): void
    {
        $tester = $this->tester();

        self::assertSame(Command::INVALID, $tester->execute(['--format' => 'github', '--exclude' => ['manifest/foo']]));
        self::assertStringStartsWith('::error::Unknown check "manifest/foo"', $tester->getDisplay());
    }

    public function testUnknownFormatIsAnInvalidCall(): void
    {
        $tester = $this->tester();

        self::assertSame(Command::INVALID, $tester->execute(['--format' => 'json']));
        self::assertStringContainsString('Unknown format, expected one of: text, github.', self::display($tester));
    }

    /**
     * Runs the command with the given input and returns the tester.
     *
     * @param array<string, mixed> $input
     */
    private function executed(array $input): CommandTester
    {
        $tester = $this->tester();
        $tester->execute($input);

        return $tester;
    }

    /**
     * Creates the command for the project without global configuration.
     *
     * @param string|null $projectDirectory Project directory if it is not the temporary directory
     */
    private function tester(?string $projectDirectory = null): CommandTester
    {
        return new CommandTester(new CheckCommand(
            $projectDirectory ?? $this->temporaryDirectory,
            new ConfigurationLoader(null),
            new CheckContextFactory($this->processRunner()),
        ));
    }
}
