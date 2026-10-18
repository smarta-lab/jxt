<?php

/**
 * @copyright  (C) 2026 Carsten Volkwein
 * @license    MIT License; see LICENSE
 */

declare(strict_types=1);

namespace SmartA\Jxt\Tests\Bridge;

use PHPUnit\Framework\TestCase;
use SmartA\Jxt\Bridge\PlanStager;
use SmartA\Jxt\Configuration\Configuration;
use SmartA\Jxt\Deployment\EntryKind;
use SmartA\Jxt\Deployment\PlanEntry;
use SmartA\Jxt\Manifest\ManifestParser;
use SmartA\Jxt\Sync\Deletion;
use SmartA\Jxt\Sync\FileStatus;
use SmartA\Jxt\Sync\ManifestComparison;
use SmartA\Jxt\Sync\SyncFile;
use SmartA\Jxt\Sync\SyncPlan;
use SmartA\Jxt\Tests\TemporaryDirectoryTrait;
use SmartA\Plugin\Console\Jxt\Plan\Deletion as PluginDeletion;
use SmartA\Plugin\Console\Jxt\Plan\ExtensionIdentity;
use SmartA\Plugin\Console\Jxt\Plan\PlanFile;
use SmartA\Plugin\Console\Jxt\Plan\PlanReader;

/**
 * Tests for staging a sync plan, read back with the reader of the bridge plugin.
 */
final class PlanStagerTest extends TestCase
{
    use TemporaryDirectoryTrait;

    #[\Override]
    protected function setUp(): void
    {
        $this->createTemporaryDirectory();
        mkdir($this->temporaryDirectory . '/tmp');
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->removeTemporaryDirectory();
    }

    public function testStagedPlanIsReadByThePlugin(): void
    {
        $this->writeFile('project/tmpl/new.php', 'new');
        $this->writeFile('project/tmpl/same.php', 'same');
        $stager = new PlanStager($this->temporaryDirectory . '/tmp');

        $directory = $stager->stage(
            $this->syncPlan(),
            (new ManifestParser())->parse(
                '<extension type="module" client="administrator"><name>mod_x</name></extension>',
                'mod_x.xml',
            ),
            $this->temporaryDirectory . '/project',
            new Configuration(null, 'www-data', '/dist', 0750, 0640),
        );
        $plan = (new PlanReader())->read($directory);

        self::assertSame(PlanReader::FORMAT, PlanStager::FORMAT);

        self::assertSame(0750, $plan->directoryMode);
        self::assertSame(0640, $plan->fileMode);
        self::assertEquals([new PluginDeletion('modules/mod_x/old.php', false), new PluginDeletion('modules/mod_x/old', true)], $plan->deletions);
        self::assertSame(['administrator/modules/mod_x', 'administrator/modules/mod_x/tmpl'], $plan->directories);
        self::assertEquals([
            new PlanFile('administrator/modules/mod_x/tmpl/new.php', 'files/0'),
            new PlanFile('administrator/modules/mod_x/tmpl/same.php', null),
        ], $plan->files);
        self::assertEquals(new ExtensionIdentity('module', 'mod_x', '', 1), $plan->extension);
        self::assertSame('new', file_get_contents($directory . '/files/0'));
        self::assertSame(0644, fileperms($directory . '/files/0') & 0777);
        self::assertSame(0755, fileperms($directory) & 0777);

        $stager->remove($directory);

        self::assertDirectoryDoesNotExist($directory);
    }

    public function testStagesPluginIdentity(): void
    {
        $directory = (new PlanStager($this->temporaryDirectory . '/tmp'))->stage(
            new SyncPlan([], [], [], new ManifestComparison(null, null, false, []), []),
            (new ManifestParser())->parse(
                '<extension type="plugin" group="system"><name>x</name><files><filename plugin="example">x.php</filename></files></extension>',
                'example.xml',
            ),
            $this->temporaryDirectory,
            new Configuration(null, null, '/dist', 0755, 0644),
        );

        self::assertEquals(new ExtensionIdentity('plugin', 'example', 'system', 0), (new PlanReader())->read($directory)->extension);
    }

    /**
     * Returns a plan with a new and an unchanged file and two deletions.
     */
    private function syncPlan(): SyncPlan
    {
        return new SyncPlan(
            [
                new SyncFile(new PlanEntry('tmpl/new.php', 'administrator/modules/mod_x/tmpl/new.php', EntryKind::File), FileStatus::New),
                new SyncFile(new PlanEntry('tmpl/same.php', 'administrator/modules/mod_x/tmpl/same.php', EntryKind::File), FileStatus::Unchanged),
            ],
            [new Deletion('modules/mod_x/old.php', EntryKind::File), new Deletion('modules/mod_x/old', EntryKind::Directory)],
            ['administrator/modules/mod_x', 'administrator/modules/mod_x/tmpl'],
            new ManifestComparison(null, null, false, []),
            [],
        );
    }
}
