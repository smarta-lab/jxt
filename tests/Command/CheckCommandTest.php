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
     */
    private function tester(): CommandTester
    {
        return new CommandTester(new CheckCommand(
            $this->temporaryDirectory,
            new ConfigurationLoader(null),
            new CheckContextFactory($this->processRunner()),
        ));
    }
}
