<?php

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

    public function testShowsValuesWithOriginAndFiles(): void
    {
        $this->writeFile('home/config.json', '{"joomla_root": "/var/www/html"}');
        $this->writeFile('project/.jxt.json', '{"dir_mode": "0775"}');
        $tester = $this->tester();

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        $display = $tester->getDisplay();
        self::assertMatchesRegularExpression('/joomla_root\s+\/var\/www\/html\s+global/', $display);
        self::assertMatchesRegularExpression('/dir_mode\s+0775\s+project/', $display);
        self::assertMatchesRegularExpression('/dist_dir\s+dist\s+default/', $display);
        self::assertMatchesRegularExpression('/manifest\s+–\s+not set/', $display);
        self::assertStringContainsString($this->globalFile(), $display);
        self::assertStringContainsString($this->project() . '/.jxt.json', $display);
    }

    public function testSetWritesProjectFileAndKeepsOtherValues(): void
    {
        $this->writeFile('project/.jxt.json', '{"dist_dir": "build"}');

        self::assertSame(Command::SUCCESS, $this->tester()->execute(['action' => 'set', 'key' => 'web_user', 'value' => 'apache']));
        self::assertJsonStringEqualsJsonFile(
            $this->project() . '/.jxt.json',
            '{"web_user": "apache", "dist_dir": "build"}',
        );
    }

    public function testSetGlobalCreatesFileAndDirectory(): void
    {
        $tester = $this->tester();

        self::assertSame(Command::SUCCESS, $tester->execute([
            'action' => 'set',
            'key' => 'joomla_root',
            'value' => '/var/www/html',
            '--global' => true,
        ]));
        self::assertJsonStringEqualsJsonFile($this->globalFile(), '{"joomla_root": "/var/www/html"}');
        self::assertFileDoesNotExist($this->project() . '/.jxt.json');
    }

    public function testSetNullStoresJsonNull(): void
    {
        self::assertSame(Command::SUCCESS, $this->tester()->execute(['action' => 'set', 'key' => 'web_user', 'value' => 'null']));
        self::assertJsonStringEqualsJsonFile($this->project() . '/.jxt.json', '{"web_user": null}');
    }

    public function testSetWritesModesWithFourDigits(): void
    {
        self::assertSame(Command::SUCCESS, $this->tester()->execute(['action' => 'set', 'key' => 'dir_mode', 'value' => '775']));
        self::assertJsonStringEqualsJsonFile($this->project() . '/.jxt.json', '{"dir_mode": "0775"}');
    }

    public function testUnsetRemovesKey(): void
    {
        $this->writeFile('project/.jxt.json', '{"web_user": "apache", "dist_dir": "build"}');

        self::assertSame(Command::SUCCESS, $this->tester()->execute(['action' => 'unset', 'key' => 'web_user']));
        self::assertJsonStringEqualsJsonFile($this->project() . '/.jxt.json', '{"dist_dir": "build"}');
    }

    /**
     * @param array<string, string|bool> $input
     * @param non-empty-string           $expectedMessage
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidInput')]
    public function testRejectsInvalidInput(array $input, string $expectedMessage): void
    {
        $tester = $this->tester();

        self::assertSame(Command::FAILURE, $tester->execute($input));
        self::assertStringContainsString($expectedMessage, self::display($tester));
        self::assertFileDoesNotExist($this->project() . '/.jxt.json');
    }

    /**
     * @return iterable<string, array{array<string, string|bool>, non-empty-string}>
     */
    public static function invalidInput(): iterable
    {
        yield 'unknown action' => [['action' => 'delete'], 'delete'];
        yield 'unknown key' => [['action' => 'set', 'key' => 'joomla_rot', 'value' => '/x'], 'joomla_rot'];
        yield 'invalid value' => [['action' => 'set', 'key' => 'file_mode', 'value' => '999'], 'file_mode'];
        yield 'missing value' => [['action' => 'set', 'key' => 'web_user'], 'specify a value'];
        yield 'missing key' => [['action' => 'unset'], 'specify a key'];
        yield 'global manifest' => [
            ['action' => 'set', 'key' => 'manifest', 'value' => 'mod_x.xml', '--global' => true],
            'project configuration',
        ];
    }

    public function testRefusesToWriteUnderSudo(): void
    {
        $tester = new CommandTester(new ConfigCommand($this->project(), new ConfigurationLoader($this->globalFile()), true));

        self::assertSame(Command::FAILURE, $tester->execute(['action' => 'set', 'key' => 'web_user', 'value' => 'apache']));
        self::assertStringContainsString('sudo', self::display($tester));
        self::assertFileDoesNotExist($this->project() . '/.jxt.json');
    }

    /**
     * Creates the command with a global configuration in the temporary directory, without sudo.
     */
    private function tester(): CommandTester
    {
        return new CommandTester(new ConfigCommand($this->project(), new ConfigurationLoader($this->globalFile()), false));
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
