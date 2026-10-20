<?php

/**
 * @copyright  (C) 2026 Carsten Volkwein
 * @license    MIT License; see LICENSE
 */

declare(strict_types=1);

namespace SmartA\Jxt\Tests\Bridge;

use PHPUnit\Framework\TestCase;
use SmartA\Jxt\Bridge\Bridge;
use SmartA\Jxt\Bridge\BridgeException;
use SmartA\Jxt\Bridge\PlanStager;
use SmartA\Jxt\Configuration\Configuration;
use SmartA\Jxt\Install\JoomlaCli;
use SmartA\Jxt\Install\JoomlaInstaller;
use SmartA\Jxt\Install\ProcessResult;
use SmartA\Jxt\Manifest\ManifestParser;
use SmartA\Jxt\Sync\ManifestComparison;
use SmartA\Jxt\Sync\SyncPlan;
use SmartA\Jxt\Tests\Install\ScriptedProcessRunner;
use SmartA\Jxt\Tests\TemporaryDirectoryTrait;
use ZipArchive;

/**
 * Tests for building, installing, removing and using the bridge plugin.
 */
final class BridgeTest extends TestCase
{
    use TemporaryDirectoryTrait;

    #[\Override]
    protected function setUp(): void
    {
        $this->createTemporaryDirectory();
        mkdir($this->temporaryDirectory . '/tmp');
        mkdir($this->temporaryDirectory . '/joomla');
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->removeTemporaryDirectory();
    }

    public function testBundledVersionComesFromThePluginManifest(): void
    {
        $manifest = (new ManifestParser())->parseFile(Bridge::BUNDLED_DIRECTORY . '/jxt.xml');

        self::assertSame($manifest->version, $this->bridge(new ScriptedProcessRunner(new ProcessResult(0, '')))->bundledVersion());
        self::assertSame('plg_console_jxt', $manifest->name());
        self::assertSame(Bridge::INSTALLED_MANIFEST, $manifest->installedManifest());
    }

    public function testInstalledVersionIsNullWithoutPlugin(): void
    {
        $bridge = $this->bridge(new ScriptedProcessRunner(new ProcessResult(0, '')));

        self::assertNull($bridge->installedVersion($this->joomla()));

        $this->writeFile('joomla/' . Bridge::INSTALLED_MANIFEST, <<<'XML'
            <extension type="plugin" group="console"><name>x</name><version>0.0.9</version>
            <files><folder plugin="jxt">services</folder></files></extension>
            XML);

        self::assertSame('0.0.9', $bridge->installedVersion($this->joomla()));
    }

    public function testInstallsBundledPlugin(): void
    {
        $runner = new ScriptedProcessRunner(new ProcessResult(0, 'installed'));
        $entries = [];
                $runner->inspector = static function (string $argument) use (&$entries): void {
                    $zip = new ZipArchive();
                    $zip->open(substr($argument, strlen('--path=')));

                    for ($index = 0; $index < $zip->numFiles; $index++) {
                        $entries[] = (string) $zip->getNameIndex($index);
                    }
                };

        $result = $this->bridge($runner)->install($this->joomla(), 'www-data');

        self::assertSame('installed', $result->output);
        self::assertContains('jxt.xml', $entries);
        self::assertContains('script.php', $entries);
        self::assertContains('services/provider.php', $entries);
        self::assertContains('src/Plan/PlanReader.php', $entries);
        self::assertSame(['sudo', '-n', '-u', 'www-data'], array_slice($runner->commands[0], 0, 4));
        self::assertSame([], glob($this->temporaryDirectory . '/tmp/*'));
    }

    public function testRemovesPluginByItsExtensionId(): void
    {
        $runner = new ScriptedProcessRunner(new ProcessResult(0, "{\"extension_id\":270}\n"), new ProcessResult(0, 'removed'));

        $result = $this->bridge($runner)->remove($this->joomla(), null);

        self::assertSame('removed', $result->output);
        self::assertSame([['jxt:status'], ['extension:remove', '-n', '270']], $runner->cliArguments());
    }

    public function testRemoveStopsWhenStatusFails(): void
    {
        $runner = new ScriptedProcessRunner(new ProcessResult(1, 'Command "jxt:status" is not defined.'));

        self::assertSame(1, $this->bridge($runner)->remove($this->joomla(), null)->exitCode);
        self::assertCount(1, $runner->commands);
    }

    public function testFailsWithoutExtensionId(): void
    {
        $this->expectException(BridgeException::class);
        $this->expectExceptionMessage('extension ID');

        $this->bridge(new ScriptedProcessRunner(new ProcessResult(0, '')))->extensionId(new ProcessResult(0, '{"extension_id":null}'));
    }

    public function testAppliesStagedPlanAndRemovesStagingDirectory(): void
    {
        $runner = new ScriptedProcessRunner(new ProcessResult(0, 'Applied: 0 copied, 0 deleted.'));
        $planFile = null;
                $runner->inspector = static function (string $argument) use (&$planFile): void {
                    $planFile = (string) file_get_contents($argument . '/plan.json');
                };

        $this->bridge($runner)->apply(
            new SyncPlan([], [], [], new ManifestComparison(null, null, false, []), []),
            (new ManifestParser())->parse('<extension type="module"><name>mod_x</name><element>mod_x</element></extension>', 'mod_x.xml'),
            $this->temporaryDirectory,
            $this->joomla(),
            new Configuration($this->joomla(), 'www-data', '/dist', 0755, 0644),
        );

        self::assertSame('jxt:apply', $runner->cliArguments()[0][0]);
        self::assertStringContainsString('"element": "mod_x"', (string) $planFile);
        self::assertSame([], glob($this->temporaryDirectory . '/tmp/*'));
    }

    public function testListsSudoersRules(): void
    {
        $rules = $this->bridge(new ScriptedProcessRunner(new ProcessResult(0, '')))->sudoersRules('anna', '/var/www/html', 'www-data');

        self::assertSame([
            'anna ALL=(www-data) NOPASSWD: /usr/bin/php8.5 /var/www/html/cli/joomla.php extension\:install *',
            'anna ALL=(www-data) NOPASSWD: /usr/bin/php8.5 /var/www/html/cli/joomla.php extension\:remove -n *',
            'anna ALL=(www-data) NOPASSWD: /usr/bin/php8.5 /var/www/html/cli/joomla.php jxt\:apply *',
            'anna ALL=(www-data) NOPASSWD: /usr/bin/php8.5 /var/www/html/cli/joomla.php jxt\:status',
        ], $rules);
    }

    /**
     * Returns a bridge whose processes, ZIP files and staging directories go to the test directories.
     */
    private function bridge(ScriptedProcessRunner $runner): Bridge
    {
        $temporaryDirectory = $this->temporaryDirectory . '/tmp';

        return new Bridge(
            new JoomlaCli($runner, '/usr/bin/php8.5'),
            new JoomlaInstaller($runner, '/usr/bin/php8.5', $temporaryDirectory),
            new PlanStager($temporaryDirectory),
            temporaryDirectory: $temporaryDirectory,
        );
    }

    /**
     * Returns the Joomla root directory of the test.
     */
    private function joomla(): string
    {
        return $this->temporaryDirectory . '/joomla';
    }
}
