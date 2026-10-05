<?php

declare(strict_types=1);

namespace SmartA\Jxt\Tests\Install;

use PHPUnit\Framework\TestCase;
use SmartA\Jxt\Install\InstallException;
use SmartA\Jxt\Install\JoomlaInstaller;
use SmartA\Jxt\Install\ProcessResult;
use SmartA\Jxt\Tests\TemporaryDirectoryTrait;

/**
 * Tests for installing a ZIP file via the Joomla CLI.
 */
final class JoomlaInstallerTest extends TestCase
{
    use TemporaryDirectoryTrait;

    private string $directory;

    #[\Override]
    protected function setUp(): void
    {
        $this->directory = $this->createTemporaryDirectory();
        $this->writeFile('dist/mod_x-1.0.0.zip', 'zip content');
        mkdir($this->directory . '/tmp');
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->removeTemporaryDirectory();
    }

    public function testRunsJoomlaCliAsWebUserWithReadableCopyOfThePackage(): void
    {
        $runner = new RecordingProcessRunner(new ProcessResult(0, 'Extension installed successfully.'));
        $installer = new JoomlaInstaller($runner, '/usr/bin/php8.5', $this->directory . '/tmp');

        $result = $installer->install($this->directory . '/dist/mod_x-1.0.0.zip', '/var/www/html', 'www-data');

        self::assertSame(0, $result->exitCode);
        self::assertSame(
            ['sudo', '-n', '-u', 'www-data', '/usr/bin/php8.5', '/var/www/html/cli/joomla.php', 'extension:install'],
            array_slice($runner->command, 0, 7),
        );
        self::assertMatchesRegularExpression(
            '#^--path=' . preg_quote($this->directory, '#') . '/tmp/jxt-[0-9a-f]+-mod_x-1\.0\.0\.zip$#',
            $runner->command[7],
        );
        self::assertSame('zip content', $runner->packageContent);
        self::assertSame(0644, $runner->packageMode);
    }

    public function testRunsJoomlaCliDirectlyWithoutWebUser(): void
    {
        $runner = new RecordingProcessRunner(new ProcessResult(0, ''));
        $installer = new JoomlaInstaller($runner, '/usr/bin/php8.5', $this->directory . '/tmp');

        $installer->install($this->directory . '/dist/mod_x-1.0.0.zip', '/var/www/html', null);

        self::assertSame(['/usr/bin/php8.5', '/var/www/html/cli/joomla.php', 'extension:install'], array_slice($runner->command, 0, 3));
    }

    public function testDeletesTheCopyAfterTheInstallation(): void
    {
        $runner = new RecordingProcessRunner(new ProcessResult(1, 'Unable to install extension'));
        $installer = new JoomlaInstaller($runner, '/usr/bin/php8.5', $this->directory . '/tmp');

        $installer->install($this->directory . '/dist/mod_x-1.0.0.zip', '/var/www/html', 'www-data');

        self::assertSame([], array_values(array_diff((array) scandir($this->directory . '/tmp'), ['.', '..'])));
    }

    public function testFailsIfTheCopyCannotBeCreated(): void
    {
        $installer = new JoomlaInstaller(
            new RecordingProcessRunner(new ProcessResult(0, '')),
            '/usr/bin/php8.5',
            $this->directory . '/missing',
        );

        $this->expectException(InstallException::class);
        $this->expectExceptionMessage('Cannot copy');

        $installer->install($this->directory . '/dist/mod_x-1.0.0.zip', '/var/www/html', 'www-data');
    }

    public function testReturnsTheMatchingSudoersRule(): void
    {
        $installer = new JoomlaInstaller(new RecordingProcessRunner(new ProcessResult(0, '')), '/usr/bin/php8.5');

        self::assertSame(
            'anna ALL=(www-data) NOPASSWD: /usr/bin/php8.5 /var/www/html/cli/joomla.php extension\:install *',
            $installer->sudoersRule('anna', '/var/www/html', 'www-data'),
        );
    }
}
