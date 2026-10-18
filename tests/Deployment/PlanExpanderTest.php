<?php

/**
 * @copyright  (C) 2026 Carsten Volkwein
 * @license    MIT License; see LICENSE
 */

declare(strict_types=1);

namespace SmartA\Jxt\Tests\Deployment;

use PHPUnit\Framework\TestCase;
use SmartA\Jxt\Deployment\DeploymentException;
use SmartA\Jxt\Deployment\DeploymentPlan;
use SmartA\Jxt\Deployment\EntryKind;
use SmartA\Jxt\Deployment\PlanEntry;
use SmartA\Jxt\Deployment\PlanExpander;
use SmartA\Jxt\Tests\TemporaryDirectoryTrait;

/**
 * Tests for expanding directory entries into individual files.
 */
final class PlanExpanderTest extends TestCase
{
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

    public function testExpandsDirectoriesRecursivelyAndSorted(): void
    {
        $this->writeFile('mod_x.xml');
        $this->writeFile('tmpl/default.php');
        $this->writeFile('tmpl/sub/b.php');
        $this->writeFile('tmpl/a.php');

        $files = (new PlanExpander())->expand(new DeploymentPlan([
            new PlanEntry('mod_x.xml', 'modules/mod_x/mod_x.xml', EntryKind::File),
            new PlanEntry('tmpl', 'modules/mod_x/tmpl', EntryKind::Directory),
        ], ['modules/mod_x']), $this->project);

        self::assertEquals([
            new PlanEntry('mod_x.xml', 'modules/mod_x/mod_x.xml', EntryKind::File),
            new PlanEntry('tmpl/a.php', 'modules/mod_x/tmpl/a.php', EntryKind::File),
            new PlanEntry('tmpl/default.php', 'modules/mod_x/tmpl/default.php', EntryKind::File),
            new PlanEntry('tmpl/sub/b.php', 'modules/mod_x/tmpl/sub/b.php', EntryKind::File),
        ], $files);
    }

    public function testSkipsGitFiles(): void
    {
        $this->writeFile('media/css/site.css');
        $this->writeFile('media/css/.gitkeep');
        $this->writeFile('media/css/.gitignore');

        $files = (new PlanExpander())->expand(new DeploymentPlan([
            new PlanEntry('media/css', 'media/mod_x/css', EntryKind::Directory),
        ], []), $this->project);

        self::assertEquals([
            new PlanEntry('media/css/site.css', 'media/mod_x/css/site.css', EntryKind::File),
        ], $files);
    }

    public function testFailsForMissingFile(): void
    {
        $this->expectException(DeploymentException::class);
        $this->expectExceptionMessageIsOrContains('missing.php');

        (new PlanExpander())->expand(new DeploymentPlan([
            new PlanEntry('missing.php', 'modules/mod_x/missing.php', EntryKind::File),
        ], []), $this->project);
    }

    public function testFailsForMissingDirectory(): void
    {
        $this->writeFile('tmpl');

        $this->expectException(DeploymentException::class);
        $this->expectExceptionMessageIsOrContains('tmpl');

        (new PlanExpander())->expand(new DeploymentPlan([
            new PlanEntry('tmpl', 'modules/mod_x/tmpl', EntryKind::Directory),
        ], []), $this->project);
    }
}
