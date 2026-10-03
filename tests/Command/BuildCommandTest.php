<?php

declare(strict_types=1);

namespace SmartA\Jxt\Tests\Command;

use PHPUnit\Framework\TestCase;
use SmartA\Jxt\Command\BuildCommand;
use SmartA\Jxt\Configuration\ConfigurationLoader;
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
