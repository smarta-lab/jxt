<?php

declare(strict_types=1);

namespace SmartA\Jxt\Tests\Command;

use PHPUnit\Framework\TestCase;
use SmartA\Jxt\Command\CleanCommand;
use SmartA\Jxt\Configuration\ConfigurationLoader;
use SmartA\Jxt\Tests\TemporaryDirectoryTrait;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Tests for the `jxt clean` command.
 */
final class CleanCommandTest extends TestCase
{
    use CommandDisplayTrait;
    use TemporaryDirectoryTrait;

    private const string MANIFEST = '<extension type="module"><name>mod_x</name><version>1.0.0</version>'
        . '<files><folder module="mod_x">tmpl</folder></files></extension>';

    #[\Override]
    protected function setUp(): void
    {
        $this->createTemporaryDirectory();
        $this->writeFile('project/mod_x.xml', self::MANIFEST);
        $this->writeFile('project/tmpl/default.php', '<?php');
        $this->writeFile('project/.jxt.json', (string) json_encode(['joomla_root' => $this->joomla()]));
        $this->writeFile('joomla/configuration.php', '<?php');
        $this->writeFile('joomla/modules/mod_x/mod_x.xml', self::MANIFEST);
        $this->writeFile('joomla/modules/mod_x/tmpl/default.php', '<?php');
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->removeTemporaryDirectory();
    }

    public function testFailsWithoutDryRunAndDeletesNothing(): void
    {
        $this->writeFile('joomla/modules/mod_x/tmpl/old.php');
        $tester = $this->tester();

        self::assertSame(Command::INVALID, $tester->execute([]));
        self::assertStringContainsString('not supported yet', self::display($tester));
        self::assertFileExists($this->joomla() . '/modules/mod_x/tmpl/old.php');
    }

    public function testListsLeftoversWithoutDeleting(): void
    {
        $this->writeFile('joomla/modules/mod_x/tmpl/old.php');
        $this->writeFile('joomla/modules/mod_x/helper/a.php');
        $this->writeFile('joomla/modules/mod_x/helper/b.php');
        $tester = $this->tester();

        self::assertSame(Command::SUCCESS, $tester->execute(['--dry-run' => true]));
        self::assertStringContainsString(
            '- modules/mod_x/helper/ - modules/mod_x/tmpl/old.php',
            self::display($tester),
        );
        self::assertStringNotContainsString('helper/a.php', self::display($tester));
        self::assertStringContainsString('sudo -u www-data rm -r', self::display($tester));
        self::assertStringContainsString('2 leftovers found', self::display($tester));
        self::assertFileExists($this->joomla() . '/modules/mod_x/tmpl/old.php');
        self::assertFileExists($this->joomla() . '/modules/mod_x/helper/a.php');
    }

    public function testReportsCleanServer(): void
    {
        $tester = $this->tester();

        self::assertSame(Command::SUCCESS, $tester->execute(['--dry-run' => true]));
        self::assertStringContainsString('no leftovers found', self::display($tester));
        self::assertStringNotContainsString('rm -r', self::display($tester));
    }

    public function testSuggestsPlainRmWithoutWebUser(): void
    {
        $this->writeFile(
            'project/.jxt.json',
            (string) json_encode(['joomla_root' => $this->joomla(), 'web_user' => null]),
        );
        $this->writeFile('joomla/modules/mod_x/tmpl/old.php');
        $tester = $this->tester();

        self::assertSame(Command::SUCCESS, $tester->execute(['--dry-run' => true]));
        self::assertStringContainsString('Remove the leftovers by hand', self::display($tester));
        self::assertStringContainsString('1 leftover found', self::display($tester));
        self::assertStringNotContainsString('sudo', self::display($tester));
    }

    public function testFailsWithoutInstalledManifest(): void
    {
        unlink($this->joomla() . '/modules/mod_x/mod_x.xml');
        $tester = $this->tester();

        self::assertSame(Command::FAILURE, $tester->execute(['--dry-run' => true]));
        self::assertStringContainsString('The extension is not installed', self::display($tester));
    }

    public function testFailsForFileMissingFromProject(): void
    {
        unlink($this->temporaryDirectory . '/project/tmpl/default.php');
        rmdir($this->temporaryDirectory . '/project/tmpl');
        $tester = $this->tester();

        self::assertSame(Command::FAILURE, $tester->execute(['--dry-run' => true]));
        self::assertStringContainsString('Directory tmpl listed in the manifest is missing', self::display($tester));
    }

    /**
     * Creates the command without global configuration.
     */
    private function tester(): CommandTester
    {
        return new CommandTester(new CleanCommand($this->temporaryDirectory . '/project', new ConfigurationLoader(null)));
    }

    /**
     * Returns the Joomla root directory.
     */
    private function joomla(): string
    {
        return $this->temporaryDirectory . '/joomla';
    }
}
