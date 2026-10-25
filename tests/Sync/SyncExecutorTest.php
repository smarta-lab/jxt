<?php

/**
 * @copyright  (C) 2026 Carsten Volkwein
 * @license    MIT License; see LICENSE
 */

declare(strict_types=1);

namespace SmartA\Jxt\Tests\Sync;

use PHPUnit\Framework\TestCase;
use SmartA\Jxt\Deployment\DeploymentException;
use SmartA\Jxt\Deployment\EntryKind;
use SmartA\Jxt\Deployment\PlanEntry;
use SmartA\Jxt\Sync\Deletion;
use SmartA\Jxt\Sync\FileStatus;
use SmartA\Jxt\Sync\ManifestComparison;
use SmartA\Jxt\Sync\SyncExecutor;
use SmartA\Jxt\Sync\SyncFile;
use SmartA\Jxt\Sync\SyncPlan;
use SmartA\Jxt\Tests\TemporaryDirectoryTrait;

/**
 * Tests for executing a sync plan in the file system.
 */
final class SyncExecutorTest extends TestCase
{
    use TemporaryDirectoryTrait;

    #[\Override]
    protected function setUp(): void
    {
        $this->createTemporaryDirectory();
        mkdir($this->project());
        mkdir($this->joomla());
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->removeTemporaryDirectory();
    }

    public function testCopiesChangedFilesAndDeletesStaleEntries(): void
    {
        $this->writeFile('project/tmpl/new.php', 'neu');
        $this->writeFile('project/tmpl/changed.php', 'changed');
        $this->writeFile('project/tmpl/same.php', 'project');
        $this->writeFile('joomla/modules/mod_x/tmpl/changed.php', 'alt');
        $this->writeFile('joomla/modules/mod_x/tmpl/same.php', 'Server');
        $this->writeFile('joomla/modules/mod_x/old/stale.php');

        $this->execute(new SyncPlan(
            [
                $this->file('tmpl/new.php', 'modules/mod_x/tmpl/new.php', FileStatus::New),
                $this->file('tmpl/changed.php', 'modules/mod_x/tmpl/changed.php', FileStatus::Changed),
                $this->file('tmpl/same.php', 'modules/mod_x/tmpl/same.php', FileStatus::Unchanged),
            ],
            [
                new Deletion('modules/mod_x/old/stale.php', EntryKind::File),
                new Deletion('modules/mod_x/old', EntryKind::Directory),
            ],
            ['modules/mod_x', 'modules/mod_x/tmpl'],
            $this->comparison(),
            [],
        ));

        self::assertStringEqualsFile($this->joomla() . '/modules/mod_x/tmpl/new.php', 'neu');
        self::assertStringEqualsFile($this->joomla() . '/modules/mod_x/tmpl/changed.php', 'changed');
        self::assertStringEqualsFile($this->joomla() . '/modules/mod_x/tmpl/same.php', 'Server');
        self::assertDirectoryDoesNotExist($this->joomla() . '/modules/mod_x/old');
    }

    public function testAppliesTheModesOfTheJoomlaInstaller(): void
    {
        $this->writeFile('project/tmpl/a.php');
        $this->writeFile('project/tmpl/b.php');
        $this->writeFile('joomla/modules/mod_x/tmpl/b.php');
        chmod($this->joomla() . '/modules/mod_x/tmpl/b.php', 0600);
        chmod($this->joomla() . '/modules/mod_x', 0700);

        $this->execute(new SyncPlan(
            [
                $this->file('tmpl/a.php', 'modules/mod_x/tmpl/a.php', FileStatus::New),
                $this->file('tmpl/b.php', 'modules/mod_x/tmpl/b.php', FileStatus::Unchanged),
            ],
            [],
            ['modules/mod_x', 'modules/mod_x/tmpl'],
            $this->comparison(),
            [],
        ));

        self::assertSame(0755, $this->mode('modules/mod_x'));
        self::assertSame(0755, $this->mode('modules/mod_x/tmpl'));
        self::assertSame(0644, $this->mode('modules/mod_x/tmpl/a.php'));
        self::assertSame(0644, $this->mode('modules/mod_x/tmpl/b.php'));
    }

    public function testCreatesMissingSharedDirectoriesWithoutTouchingExistingOnes(): void
    {
        $this->writeFile('project/language/de-DE/mod_x.ini');
        mkdir($this->joomla() . '/language');
        chmod($this->joomla() . '/language', 0711);

        $this->execute(new SyncPlan(
            [$this->file('language/de-DE/mod_x.ini', 'language/de-DE/mod_x.ini', FileStatus::New)],
            [],
            [],
            $this->comparison(),
            [],
        ));

        self::assertFileExists($this->joomla() . '/language/de-DE/mod_x.ini');
        self::assertSame(0711, $this->mode('language'));
        self::assertSame(0755, $this->mode('language/de-DE'));
    }

    public function testFailsWhenSourceIsMissing(): void
    {
        $this->expectException(DeploymentException::class);
        $this->expectExceptionMessageIsOrContains('tmpl/missing.php');

        $this->execute(new SyncPlan(
            [$this->file('tmpl/missing.php', 'modules/mod_x/tmpl/missing.php', FileStatus::New)],
            [],
            [],
            $this->comparison(),
            [],
        ));
    }

    /**
     * Executes the plan.
     */
    private function execute(SyncPlan $plan): void
    {
        (new SyncExecutor())->execute($plan, $this->project(), $this->joomla());
    }

    /**
     * Creates a file entry for the plan.
     */
    private function file(string $source, string $target, FileStatus $status): SyncFile
    {
        return new SyncFile(new PlanEntry($source, $target, EntryKind::File), $status);
    }

    /**
     * Returns a comparison without differences.
     */
    private function comparison(): ManifestComparison
    {
        return new ManifestComparison('1.0.0', '1.0.0', false, []);
    }

    /**
     * Returns the mode of a path relative to the Joomla root directory.
     */
    private function mode(string $path): int
    {
        clearstatcache();

        return (int) fileperms($this->joomla() . '/' . $path) & 0777;
    }

    /**
     * Returns the project directory.
     */
    private function project(): string
    {
        return $this->temporaryDirectory . '/project';
    }

    /**
     * Returns the Joomla root directory.
     */
    private function joomla(): string
    {
        return $this->temporaryDirectory . '/joomla';
    }
}
