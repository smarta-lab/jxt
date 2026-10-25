<?php

/**
 * @copyright  (C) 2026 Carsten Volkwein
 * @license    MIT License; see LICENSE
 */

declare(strict_types=1);

namespace SmartA\Jxt\Tests\Command;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SmartA\Jxt\Command\SiteCommand;
use SmartA\Jxt\Configuration\ConfigurationLoader;
use SmartA\Jxt\Tests\TemporaryDirectoryTrait;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Tests for the `jxt site` command.
 */
final class SiteCommandTest extends TestCase
{
    use CommandDisplayTrait;
    use TemporaryDirectoryTrait;

    #[\Override]
    protected function setUp(): void
    {
        $this->createTemporaryDirectory();
        mkdir($this->temporaryDirectory . '/project');
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->removeTemporaryDirectory();
    }

    public function testListsTheSitesWithDefaultAndSelection(): void
    {
        $this->writeConfiguration('{"default_site": "local", "sites": [{"id": "local", "joomla_root": "/var/www/html"}, {"id": "ddev"}]}');
        $tester = $this->tester('ddev');

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertMatchesRegularExpression('/local\s+\/var\/www\/html\s+default_site/', $tester->getDisplay());
        self::assertMatchesRegularExpression('/ddev\s+–\s+selected via JXT_SITE/', $tester->getDisplay());
        self::assertStringNotContainsString('[WARNING]', $tester->getDisplay());
    }

    public function testListWarnsAboutAnUnknownDefaultSite(): void
    {
        $this->writeConfiguration('{"default_site": "j5", "sites": [{"id": "local"}]}');
        $tester = $this->tester();

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('There is no site "j5" (selected via default_site)', self::display($tester));
    }

    public function testListWithoutSitesPointsToAdd(): void
    {
        $tester = $this->tester();

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('No test site is configured. Add one with jxt site add <id>', self::display($tester));
    }

    public function testAddsASiteAsDefaultSite(): void
    {
        $this->writeConfiguration('{"dist_dir": "/srv/dist"}');
        $tester = $this->tester();

        self::assertSame(Command::SUCCESS, $tester->execute(['action' => 'add', 'id' => 'local', '--default' => true]));
        self::assertJsonStringEqualsJsonFile($this->globalFile(), '{"dist_dir": "/srv/dist", "default_site": "local", "sites": [{"id": "local"}]}');
        self::assertStringContainsString('Added site "local" as default site to', self::display($tester));
        self::assertStringContainsString('jxt config set <key> <value> --site=local', self::display($tester));
    }

    public function testAddsASiteWithoutChangingTheDefaultSite(): void
    {
        $this->writeConfiguration('{"default_site": "local", "sites": [{"id": "local"}]}');

        self::assertSame(Command::SUCCESS, $this->tester()->execute(['action' => 'add', 'id' => 'ddev']));
        self::assertJsonStringEqualsJsonFile($this->globalFile(), '{"default_site": "local", "sites": [{"id": "local"}, {"id": "ddev"}]}');
    }

    public function testRemovesASiteAndTheDefaultSiteThatReferredToIt(): void
    {
        $this->writeConfiguration('{"default_site": "local", "sites": [{"id": "local", "joomla_root": "/var/www/html"}, {"id": "ddev"}]}');
        $tester = $this->tester();

        self::assertSame(Command::SUCCESS, $tester->execute(['action' => 'remove', 'id' => 'local']));
        self::assertJsonStringEqualsJsonFile($this->globalFile(), '{"sites": [{"id": "ddev"}]}');
        self::assertStringContainsString('and default_site, which referred to it', self::display($tester));
        self::assertStringContainsString('JXT_SITE=local in an .envrc or --site=local', self::display($tester));
    }

    public function testRemovingTheLastSiteDeletesTheEmptyFile(): void
    {
        $this->writeConfiguration('{"sites": [{"id": "local"}]}');

        self::assertSame(Command::SUCCESS, $this->tester()->execute(['action' => 'remove', 'id' => 'local']));
        self::assertFileDoesNotExist($this->globalFile());
    }

    public function testRenamesASiteAndTheDefaultSite(): void
    {
        $this->writeConfiguration('{"default_site": "local", "sites": [{"id": "local", "joomla_root": "/var/www/html"}]}');
        $tester = $this->tester();

        self::assertSame(Command::SUCCESS, $tester->execute(['action' => 'rename', 'id' => 'local', 'new-id' => 'default']));
        self::assertJsonStringEqualsJsonFile(
            $this->globalFile(),
            '{"default_site": "default", "sites": [{"id": "default", "joomla_root": "/var/www/html"}]}',
        );
        self::assertStringContainsString('Renamed site "local" to "default"', self::display($tester));
        self::assertStringContainsString('default_site now refers to "default"', self::display($tester));
        self::assertStringContainsString('are not changed', self::display($tester));
    }

    public function testRenamesASiteWithoutTouchingAnotherDefaultSite(): void
    {
        $this->writeConfiguration('{"default_site": "local", "sites": [{"id": "local"}, {"id": "ddev"}]}');

        self::assertSame(Command::SUCCESS, $this->tester()->execute(['action' => 'rename', 'id' => 'ddev', 'new-id' => 'leaflet']));
        self::assertJsonStringEqualsJsonFile($this->globalFile(), '{"default_site": "local", "sites": [{"id": "local"}, {"id": "leaflet"}]}');
    }

    /**
     * @param array<string, string|bool> $input
     * @param non-empty-string           $expectedMessage
     */
    #[DataProvider('invalidInput')]
    public function testRejectsInvalidInputWithoutWriting(array $input, string $expectedMessage): void
    {
        $configuration = '{"default_site": "local", "sites": [{"id": "local"}, {"id": "ddev"}]}';
        $this->writeConfiguration($configuration);
        $tester = $this->tester();

        self::assertNotSame(Command::SUCCESS, $tester->execute($input));
        self::assertStringContainsString($expectedMessage, self::display($tester));
        self::assertJsonStringEqualsJsonFile($this->globalFile(), $configuration);
    }

    /**
     * @return iterable<string, array{array<string, string|bool>, non-empty-string}>
     */
    public static function invalidInput(): iterable
    {
        yield 'unknown action' => [['action' => 'delete'], 'Unknown action "delete"'];
        yield 'add without id' => [['action' => 'add'], 'Please specify the id of the site'];
        yield 'add of an existing site' => [['action' => 'add', 'id' => 'ddev'], 'There is already a site "ddev"'];
        yield 'add with an invalid id' => [['action' => 'add', 'id' => 'my site'], 'Invalid site id "my site"'];
        yield 'remove of an unknown site' => [['action' => 'remove', 'id' => 'j5'], 'There is no site "j5" in'];
        yield 'rename of an unknown site' => [['action' => 'rename', 'id' => 'j5', 'new-id' => 'x'], 'There is no site "j5" in'];
        yield 'rename without new id' => [['action' => 'rename', 'id' => 'local'], 'Please specify the new id'];
        yield 'rename to an existing site' => [['action' => 'rename', 'id' => 'local', 'new-id' => 'ddev'], 'There is already a site "ddev"'];
    }

    public function testRefusesToWriteUnderSudoButLists(): void
    {
        $this->writeConfiguration('{"sites": [{"id": "local"}]}');
        $tester = new CommandTester(new SiteCommand($this->temporaryDirectory . '/project', new ConfigurationLoader($this->globalFile()), true));

        self::assertSame(Command::FAILURE, $tester->execute(['action' => 'add', 'id' => 'ddev']));
        self::assertStringContainsString('sudo', self::display($tester));
        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('local', $tester->getDisplay());
    }

    /**
     * Creates the command with a global configuration in the temporary directory, without sudo.
     *
     * @param string|null $environmentSite Value of `JXT_SITE`
     */
    private function tester(?string $environmentSite = null): CommandTester
    {
        return new CommandTester(new SiteCommand(
            $this->temporaryDirectory . '/project',
            new ConfigurationLoader($this->globalFile(), $environmentSite),
            false,
        ));
    }

    /**
     * Writes the global configuration.
     */
    private function writeConfiguration(string $json): void
    {
        $this->writeFile('home/config.json', $json);
    }

    /**
     * Returns the path of the global configuration.
     */
    private function globalFile(): string
    {
        return $this->temporaryDirectory . '/home/config.json';
    }
}
