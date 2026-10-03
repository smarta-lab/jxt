<?php

declare(strict_types=1);

namespace SmartA\Jxt\Tests\Command;

use PHPUnit\Framework\TestCase;
use SmartA\Jxt\Command\BuildCommand;
use SmartA\Jxt\Configuration\ConfigurationLoader;
use SmartA\Jxt\Tests\TemporaryDirectoryTrait;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Tests for the `jxt build` command.
 */
final class BuildCommandTest extends TestCase
{
    use CommandDisplayTrait;
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
        self::assertStringContainsString('2 Dateien', self::display($tester));
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
        self::assertStringContainsString('Nicht im Manifest', self::display($tester));
    }

    public function testReportsErrorsWithoutStackTrace(): void
    {
        $tester = new CommandTester(new BuildCommand($this->project, new ConfigurationLoader(null)));
        $exitCode = $tester->execute([]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('Kein Joomla-Manifest', self::display($tester));
    }
}
