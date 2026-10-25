<?php

/**
 * @copyright  (C) 2026 Carsten Volkwein
 * @license    MIT License; see LICENSE
 */

declare(strict_types=1);

namespace SmartA\Jxt\Tests\Command;

use PHPUnit\Framework\TestCase;
use SmartA\Jxt\Command\ConfigCommand;
use SmartA\Jxt\Configuration\ConfigurationLoader;
use SmartA\Jxt\Tests\TemporaryDirectoryTrait;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Tests for the `jxt config` command.
 */
final class ConfigCommandTest extends TestCase
{
    use CommandDisplayTrait;
    use TemporaryDirectoryTrait;

    #[\Override]
    protected function setUp(): void
    {
        $this->createTemporaryDirectory();
        mkdir($this->project());
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->removeTemporaryDirectory();
    }

    public function testShowsValuesWithOriginTheSelectedSiteAndFiles(): void
    {
        $this->writeFile('home/config.json', (string) json_encode([
            'dist_dir' => '/srv/dist',
            'default_site' => 'local',
            'sites' => [['id' => 'local', 'joomla_root' => '/var/www/html'], ['id' => 'ddev', 'joomla_root' => '/srv/ddev']],
        ]));
        $this->writeFile('project/.jxt.json', '{"manifest": "mod_x.xml"}');
        $tester = $this->tester();

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        $display = $tester->getDisplay();
        self::assertMatchesRegularExpression('/manifest\s+mod_x\.xml\s+project/', $display);
        self::assertMatchesRegularExpression('/dist_dir\s+\/srv\/dist\s+global/', $display);
        self::assertMatchesRegularExpression('/default_site\s+local\s+global/', $display);
        self::assertMatchesRegularExpression('/check_exclude\s+–\s+not set/', $display);
        self::assertStringContainsString('Test site: site "local" (default_site)', $display);
        self::assertMatchesRegularExpression('/joomla_root\s+\/var\/www\/html/', $display);
        self::assertMatchesRegularExpression('/web_user\s+–/', $display);
        self::assertStringContainsString('sites:   local, ddev', $display);
        self::assertStringContainsString($this->globalFile(), $display);
        self::assertStringContainsString($this->project() . '/.jxt.json', $display);
        self::assertStringNotContainsString('[WARNING]', $display);
    }

    public function testShowsTheSiteOfTheEnvironmentVariable(): void
    {
        $this->writeSites('local', ['id' => 'local', 'joomla_root' => '/var/www/html'], ['id' => 'ddev', 'joomla_root' => '/srv/ddev']);
        $tester = $this->tester('ddev');

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('Test site: site "ddev" via JXT_SITE', $tester->getDisplay());
        self::assertMatchesRegularExpression('/joomla_root\s+\/srv\/ddev/', $tester->getDisplay());
    }

    public function testShowWarnsAboutMissingSite(): void
    {
        $tester = $this->tester();

        self::assertSame(Command::SUCCESS, $tester->execute(['--site' => 'ddev']));
        self::assertStringContainsString('Test site: site "ddev" via --site', $tester->getDisplay());
        self::assertStringContainsString('There is no site "ddev"', self::display($tester));
    }

    public function testShowWarnsIfNoSiteIsSelected(): void
    {
        $this->writeSites(null, ['id' => 'ddev', 'joomla_root' => '/srv/ddev']);
        $tester = $this->tester();

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('Test site: no site selected', $tester->getDisplay());
        self::assertStringContainsString('No test site is selected', self::display($tester));
    }

    public function testSetWritesProjectFileAndKeepsOtherValues(): void
    {
        $this->writeFile('project/.jxt.json', '{"dist_dir": "build"}');

        self::assertSame(Command::SUCCESS, $this->tester()->execute(['action' => 'set', 'key' => 'manifest', 'value' => ['mod_x.xml']]));
        self::assertJsonStringEqualsJsonFile(
            $this->project() . '/.jxt.json',
            '{"manifest": "mod_x.xml", "dist_dir": "build"}',
        );
    }

    public function testSetGlobalCreatesFileAndDirectory(): void
    {
        $tester = $this->tester();

        self::assertSame(Command::SUCCESS, $tester->execute([
            'action' => 'set',
            'key' => 'dist_dir',
            'value' => ['/srv/dist'],
            '--global' => true,
        ]));
        self::assertJsonStringEqualsJsonFile($this->globalFile(), '{"dist_dir": "/srv/dist"}');
        self::assertFileDoesNotExist($this->project() . '/.jxt.json');
    }

    public function testSetSiteKeyChangesTheDefaultSiteInTheGlobalFile(): void
    {
        $this->writeSites('local', ['id' => 'local']);
        $tester = $this->tester();

        self::assertSame(Command::SUCCESS, $tester->execute(['action' => 'set', 'key' => 'joomla_root', 'value' => ['/var/www/html']]));
        self::assertJsonStringEqualsJsonFile(
            $this->globalFile(),
            '{"default_site": "local", "sites": [{"id": "local", "joomla_root": "/var/www/html"}]}',
        );
        self::assertFileDoesNotExist($this->project() . '/.jxt.json');
        self::assertStringContainsString('Saved joomla_root = /var/www/html in site "local" (default_site) of', self::display($tester));
    }

    public function testSetSiteKeyChangesTheSiteOfTheOption(): void
    {
        $this->writeSites('local', ['id' => 'local'], ['id' => 'ddev']);
        $tester = $this->tester();

        self::assertSame(Command::SUCCESS, $tester->execute([
            'action' => 'set',
            'key' => 'joomla_root',
            'value' => ['/srv/ddev'],
            '--site' => 'ddev',
        ]));
        self::assertJsonStringEqualsJsonFile(
            $this->globalFile(),
            '{"default_site": "local", "sites": [{"id": "local"}, {"id": "ddev", "joomla_root": "/srv/ddev"}]}',
        );
        self::assertStringContainsString('in site "ddev" via --site of', self::display($tester));
    }

    public function testSetSiteKeyChangesTheSiteOfTheEnvironmentVariable(): void
    {
        $this->writeSites('local', ['id' => 'local'], ['id' => 'ddev', 'joomla_root' => '/srv/ddev']);

        self::assertSame(Command::SUCCESS, $this->tester('ddev')->execute(['action' => 'set', 'key' => 'joomla_root', 'value' => ['/srv/other']]));
        self::assertJsonStringEqualsJsonFile(
            $this->globalFile(),
            '{"default_site": "local", "sites": [{"id": "local"}, {"id": "ddev", "joomla_root": "/srv/other"}]}',
        );
    }

    public function testSetSiteKeyDoesNotAddASite(): void
    {
        $this->writeSites('local', ['id' => 'local']);
        $tester = $this->tester();

        self::assertSame(Command::FAILURE, $tester->execute([
            'action' => 'set',
            'key' => 'joomla_root',
            'value' => ['/srv/ddev'],
            '--site' => 'lefalet',
        ]));
        self::assertStringContainsString('There is no site "lefalet" (selected via --site)', self::display($tester));
        self::assertStringContainsString('Add it with jxt site add lefalet.', self::display($tester));
        self::assertJsonStringEqualsJsonFile($this->globalFile(), '{"default_site": "local", "sites": [{"id": "local"}]}');
    }

    public function testSetSiteKeyWithoutSitesPointsToJxtSiteAdd(): void
    {
        $tester = $this->tester();

        self::assertSame(Command::FAILURE, $tester->execute(['action' => 'set', 'key' => 'joomla_root', 'value' => ['/var/www/html']]));
        self::assertStringContainsString('No test site is configured', self::display($tester));
        self::assertStringContainsString('jxt site add <id> --default', self::display($tester));
        self::assertFileDoesNotExist($this->globalFile());
    }

    public function testSetDefaultSite(): void
    {
        $this->writeSites('local', ['id' => 'local'], ['id' => 'ddev']);

        self::assertSame(Command::SUCCESS, $this->tester()->execute(['action' => 'set', 'key' => 'default_site', 'value' => ['ddev']]));
        self::assertJsonStringEqualsJsonFile($this->globalFile(), '{"default_site": "ddev", "sites": [{"id": "local"}, {"id": "ddev"}]}');
    }

    public function testRejectsDefaultSiteThatDoesNotExist(): void
    {
        $this->writeSites('local', ['id' => 'local']);
        $tester = $this->tester();

        self::assertSame(Command::FAILURE, $tester->execute(['action' => 'set', 'key' => 'default_site', 'value' => ['ddev']]));
        self::assertStringContainsString('There is no site "ddev" in', self::display($tester));
        self::assertStringContainsString('Configured sites: local. Add it with jxt site add ddev.', self::display($tester));
    }

    public function testUnsetDefaultSite(): void
    {
        $this->writeSites('local', ['id' => 'local']);

        self::assertSame(Command::SUCCESS, $this->tester()->execute(['action' => 'unset', 'key' => 'default_site']));
        self::assertJsonStringEqualsJsonFile($this->globalFile(), '{"sites": [{"id": "local"}]}');
    }

    public function testSetNullStoresJsonNull(): void
    {
        self::assertSame(Command::SUCCESS, $this->tester()->execute(['action' => 'set', 'key' => 'manifest', 'value' => ['null']]));
        self::assertJsonStringEqualsJsonFile($this->project() . '/.jxt.json', '{"manifest": null}');
    }

    public function testSetListFromArgumentsAndCommas(): void
    {
        $this->writeFile('project/.jxt.json', '{"check_exclude": ["language"]}');
        $tester = $this->tester();

        self::assertSame(Command::SUCCESS, $tester->execute([
            'action' => 'set',
            'key' => 'check_exclude',
            'value' => ['manifest, editorconfig/max-line-length', 'assets/uri'],
        ]));
        self::assertJsonStringEqualsJsonFile(
            $this->project() . '/.jxt.json',
            '{"check_exclude": ["manifest", "editorconfig/max-line-length", "assets/uri"]}',
        );
        self::assertStringContainsString('check_exclude = manifest, editorconfig/max-line-length, assets/uri', self::display($tester));
    }

    public function testShowsListsCommaSeparated(): void
    {
        $this->writeFile('project/.jxt.json', '{"check_exclude": ["manifest", "assets/uri"]}');
        $tester = $this->tester();

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertMatchesRegularExpression('/check_exclude\s+manifest, assets\/uri\s+project/', $tester->getDisplay());
        self::assertMatchesRegularExpression('/check_include\s+–\s+not set/', $tester->getDisplay());
    }

    public function testSetCommandKeepsArgumentsAsTheyAre(): void
    {
        $this->writeSites('local', ['id' => 'local']);
        $tester = $this->tester();

        self::assertSame(Command::SUCCESS, $tester->execute([
            'action' => 'set',
            'key' => 'cli_command',
            'value' => ['docker', 'exec', '-u', 'www-data', 'web', 'php', '-d', 'error_log=a,b'],
        ]));
        self::assertJsonStringEqualsJsonFile(
            $this->globalFile(),
            '{"default_site": "local", "sites": [{"id": "local", "cli_command": '
            . '["docker", "exec", "-u", "www-data", "web", "php", "-d", "error_log=a,b"]}]}',
        );
        self::assertStringContainsString('cli_command = docker exec -u www-data web php -d error_log=a,b', self::display($tester));
    }

    public function testShowsCommandAsCommandLine(): void
    {
        $this->writeSites('local', ['id' => 'local', 'joomla_root' => '/srv/site', 'cli_command' => ['docker', 'exec', 'my web', 'php']]);
        $tester = $this->tester();

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertMatchesRegularExpression("/cli_command\\s+docker exec 'my web' php/", $tester->getDisplay());
        self::assertMatchesRegularExpression('/web_user\\s+–/', $tester->getDisplay());
        self::assertStringNotContainsString('[WARNING]', $tester->getDisplay());
    }

    public function testSetPrepareStoresOneStep(): void
    {
        $tester = $this->tester();

        self::assertSame(Command::SUCCESS, $tester->execute([
            'action' => 'set',
            'key' => 'prepare',
            'value' => ['npm', 'run', 'build', '--', '--mode=a,b'],
        ]));
        self::assertJsonStringEqualsJsonFile($this->project() . '/.jxt.json', '{"prepare": [{"run": ["npm", "run", "build", "--", "--mode=a,b"]}]}');
        self::assertStringContainsString('prepare = npm run build -- --mode=a,b', self::display($tester));
    }

    public function testShowsPrepareStepsAsCommandLines(): void
    {
        $this->writeFile('project/.jxt.json', (string) json_encode(['prepare' => [
            ['run' => ['npm', 'run', 'css']],
            ['run' => ['npm', 'run', 'minify'], 'sync' => ['npm', 'run', 'minify:copy']],
            ['run' => ['sh', '-c', 'echo images'], 'sync' => false],
        ]]));
        $tester = $this->tester();

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString(
            "npm run css; npm run minify (sync: npm run minify:copy); sh -c 'echo images' (sync: skipped)",
            $tester->getDisplay(),
        );
    }

    public function testUnsetRemovesKey(): void
    {
        $this->writeFile('project/.jxt.json', '{"manifest": "mod_x.xml", "dist_dir": "build"}');

        self::assertSame(Command::SUCCESS, $this->tester()->execute(['action' => 'unset', 'key' => 'manifest']));
        self::assertJsonStringEqualsJsonFile($this->project() . '/.jxt.json', '{"dist_dir": "build"}');
    }

    public function testUnsetDeletesTheFileWithoutKeys(): void
    {
        $this->writeFile('project/.jxt.json', '{"manifest": "mod_x.xml"}');
        $tester = $this->tester();

        self::assertSame(Command::SUCCESS, $tester->execute(['action' => 'unset', 'key' => 'manifest']));
        self::assertFileDoesNotExist($this->project() . '/.jxt.json');
        self::assertStringContainsString('Removed manifest and the now empty file', self::display($tester));
    }

    public function testUnsetSiteKeyKeepsTheSiteEvenWithoutKeys(): void
    {
        $this->writeSites('local', ['id' => 'local', 'joomla_root' => '/var/www/html'], ['id' => 'ddev', 'joomla_root' => '/srv/ddev']);
        $tester = $this->tester();

        self::assertSame(Command::SUCCESS, $tester->execute(['action' => 'unset', 'key' => 'joomla_root', '--site' => 'ddev']));
        self::assertJsonStringEqualsJsonFile(
            $this->globalFile(),
            '{"default_site": "local", "sites": [{"id": "local", "joomla_root": "/var/www/html"}, {"id": "ddev"}]}',
        );
        self::assertStringContainsString('Removed joomla_root from site "ddev" via --site of', self::display($tester));
    }

    public function testUnsetOfAKeyThatIsNotSetOnlyNotes(): void
    {
        $this->writeSites('local', ['id' => 'local']);
        $tester = $this->tester();

        self::assertSame(Command::SUCCESS, $tester->execute(['action' => 'unset', 'key' => 'web_user']));
        self::assertStringContainsString('web_user is not set in site "local" (default_site) of', self::display($tester));
    }

    public function testSetWarnsAboutWebUserNextToCliCommandButSaves(): void
    {
        $this->writeSites('local', ['id' => 'local', 'cli_command' => ['docker', 'exec', 'web', 'php']]);
        $tester = $this->tester();

        self::assertSame(Command::SUCCESS, $tester->execute(['action' => 'set', 'key' => 'web_user', 'value' => ['apache']]));
        self::assertJsonStringEqualsJsonFile(
            $this->globalFile(),
            '{"default_site": "local", "sites": [{"id": "local", "cli_command": ["docker", "exec", "web", "php"], "web_user": "apache"}]}',
        );
        self::assertStringContainsString('[WARNING]', $tester->getDisplay());
        self::assertStringContainsString('cannot be combined', self::display($tester));
        self::assertStringContainsString('jxt install, jxt bridge and jxt sync via the bridge fail until this is resolved.', self::display($tester));
    }

    public function testSetWarnsAboutCliCommandNextToWebUser(): void
    {
        $this->writeSites('local', ['id' => 'local', 'web_user' => 'apache']);
        $tester = $this->tester();

        self::assertSame(Command::SUCCESS, $tester->execute(['action' => 'set', 'key' => 'cli_command', 'value' => ['ddev', 'exec', 'php']]));
        self::assertStringContainsString('cannot be combined', self::display($tester));
    }

    public function testUnsetWarnsAboutCliRootWithoutCliCommand(): void
    {
        $this->writeSites('local', ['id' => 'local', 'cli_command' => ['ddev', 'exec', 'php'], 'cli_root' => '/var/www/html']);
        $tester = $this->tester();

        self::assertSame(Command::SUCCESS, $tester->execute(['action' => 'unset', 'key' => 'cli_command']));
        self::assertJsonStringEqualsJsonFile(
            $this->globalFile(),
            '{"default_site": "local", "sites": [{"id": "local", "cli_root": "/var/www/html"}]}',
        );
        self::assertStringContainsString('cli_root is only used together with cli_command', self::display($tester));
    }

    public function testShowWarnsAboutContradictions(): void
    {
        $this->writeSites('local', ['id' => 'local', 'cli_command' => ['ddev', 'exec', 'php'], 'web_user' => 'apache']);
        $tester = $this->tester();

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('cannot be combined', self::display($tester));
    }

    public function testSetWithoutContradictionDoesNotWarn(): void
    {
        $this->writeSites('local', ['id' => 'local', 'cli_command' => ['ddev', 'exec', 'php'], 'web_user' => 'apache']);
        $tester = $this->tester();

        self::assertSame(Command::SUCCESS, $tester->execute(['action' => 'unset', 'key' => 'web_user']));
        self::assertStringNotContainsString('[WARNING]', $tester->getDisplay());
    }

    /**
     * @param array<string, string|bool|list<string>> $input
     * @param non-empty-string                        $expectedMessage
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidInput')]
    public function testRejectsInvalidInput(array $input, string $expectedMessage): void
    {
        $tester = $this->tester();

        self::assertSame(Command::FAILURE, $tester->execute($input));
        self::assertStringContainsString($expectedMessage, self::display($tester));
        self::assertFileDoesNotExist($this->project() . '/.jxt.json');
        self::assertFileDoesNotExist($this->globalFile());
    }

    /**
     * @return iterable<string, array{array<string, string|bool|list<string>>, non-empty-string}>
     */
    public static function invalidInput(): iterable
    {
        yield 'unknown action' => [['action' => 'delete'], 'delete'];
        yield 'unknown key' => [['action' => 'set', 'key' => 'joomla_rot', 'value' => ['/x']], 'joomla_rot'];
        yield 'invalid value' => [['action' => 'set', 'key' => 'joomla_root', 'value' => ['html']], 'Invalid value "html" for "joomla_root"'];
        yield 'removed key' => [['action' => 'set', 'key' => 'dir_mode', 'value' => ['775']], 'dir_mode in the arguments is no longer supported'];
        yield 'id of a site' => [['action' => 'set', 'key' => 'id', 'value' => ['default']], 'The id of a site is changed with jxt site rename'];
        yield 'missing value' => [['action' => 'set', 'key' => 'web_user'], 'specify a value'];
        yield 'missing key' => [['action' => 'unset'], 'specify a key'];
        yield 'option without dashes for show' => [
            ['action' => 'show', 'key' => 'site=local'],
            'jxt config show takes no further arguments, but got "site=local". Did you mean --site=local?',
        ];
        yield 'value for unset' => [['action' => 'unset', 'key' => 'manifest', 'value' => ['mod_x.xml']], 'but got "mod_x.xml".'];
        yield 'global manifest' => [
            ['action' => 'set', 'key' => 'manifest', 'value' => ['mod_x.xml'], '--global' => true],
            'project configuration',
        ];
        yield 'several values for a string key' => [
            ['action' => 'set', 'key' => 'dist_dir', 'value' => ['build', 'dist']],
            'dist_dir takes exactly one value',
        ];
        yield 'empty list' => [['action' => 'set', 'key' => 'check_exclude', 'value' => [',']], 'specify a value'];
        yield 'invalid check name' => [['action' => 'set', 'key' => 'check_exclude', 'value' => ['Manifest']], 'Manifest'];
        yield 'global prepare' => [
            ['action' => 'set', 'key' => 'prepare', 'value' => ['npm', 'run', 'build'], '--global' => true],
            'project configuration',
        ];
        yield 'global check list' => [
            ['action' => 'set', 'key' => 'check_exclude', 'value' => ['manifest'], '--global' => true],
            'project configuration',
        ];
        yield 'site option for a project key' => [
            ['action' => 'set', 'key' => 'manifest', 'value' => ['mod_x.xml'], '--site' => 'ddev'],
            '--site only applies to the keys of a site, not to manifest.',
        ];
        yield 'sites itself' => [['action' => 'set', 'key' => 'sites', 'value' => ['x']], 'sites is changed with jxt site'];
        yield 'invalid default site' => [
            ['action' => 'set', 'key' => 'default_site', 'value' => ['my site']],
            'Invalid value "my site" for "default_site"',
        ];
        yield 'empty site option' => [
            ['action' => 'set', 'key' => 'joomla_root', 'value' => ['/srv/site'], '--site' => ''],
            '--site needs the id',
        ];
    }

    public function testRefusesToWriteUnderSudo(): void
    {
        $tester = new CommandTester(new ConfigCommand($this->project(), new ConfigurationLoader($this->globalFile()), true));

        self::assertSame(Command::FAILURE, $tester->execute(['action' => 'set', 'key' => 'manifest', 'value' => ['mod_x.xml']]));
        self::assertStringContainsString('sudo', self::display($tester));
        self::assertFileDoesNotExist($this->project() . '/.jxt.json');
    }

    /**
     * Creates the command with a global configuration in the temporary directory, without sudo.
     *
     * @param string|null $environmentSite Value of `JXT_SITE`
     */
    private function tester(?string $environmentSite = null): CommandTester
    {
        return new CommandTester(new ConfigCommand($this->project(), new ConfigurationLoader($this->globalFile(), $environmentSite), false));
    }

    /**
     * Writes the sites and `default_site` into the global configuration.
     *
     * @param string|null          $defaultSite Value of `default_site`; null to leave it out
     * @param array<string, mixed> ...$sites
     */
    private function writeSites(?string $defaultSite, array ...$sites): void
    {
        $this->writeFile('home/config.json', (string) json_encode([
            ...($defaultSite === null ? [] : ['default_site' => $defaultSite]),
            'sites' => array_values($sites),
        ]));
    }

    /**
     * Returns the path of the global configuration.
     */
    private function globalFile(): string
    {
        return $this->temporaryDirectory . '/home/config.json';
    }

    /**
     * Returns the project directory.
     */
    private function project(): string
    {
        return $this->temporaryDirectory . '/project';
    }
}
