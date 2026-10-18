<?php

/**
 * @copyright  (C) 2026 Carsten Volkwein
 * @license    MIT License; see LICENSE
 */

declare(strict_types=1);

namespace SmartA\Jxt\Tests\Plugin;

use PHPUnit\Framework\TestCase;
use SmartA\Jxt\Tests\TemporaryDirectoryTrait;
use SmartA\Plugin\Console\Jxt\Plan\Deletion;
use SmartA\Plugin\Console\Jxt\Plan\Plan;
use SmartA\Plugin\Console\Jxt\Plan\PlanApplier;
use SmartA\Plugin\Console\Jxt\Plan\PlanException;
use SmartA\Plugin\Console\Jxt\Plan\PlanFile;

/**
 * Tests for applying a plan in the Joomla root directory.
 */
final class PlanApplierTest extends TestCase
{
    use TemporaryDirectoryTrait;

    #[\Override]
    protected function setUp(): void
    {
        $this->createTemporaryDirectory();
        mkdir($this->temporaryDirectory . '/staging');
        mkdir($this->temporaryDirectory . '/joomla');
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->removeTemporaryDirectory();
    }

    public function testDeletesCreatesAndCopies(): void
    {
        $this->writeFile('staging/files/0', 'new');
        $this->writeFile('staging/files/1', 'changed');
        $this->writeFile('joomla/modules/mod_x/tmpl/changed.php', 'old');
        $this->writeFile('joomla/modules/mod_x/tmpl/same.php', 'same');
        $this->writeFile('joomla/modules/mod_x/old/stale.php');
        chmod($this->joomla() . '/modules/mod_x/tmpl/same.php', 0600);

        $this->apply(new Plan(
            0750,
            0640,
            [new Deletion('modules/mod_x/old/stale.php', false), new Deletion('modules/mod_x/old', true)],
            ['modules/mod_x', 'modules/mod_x/tmpl', 'modules/mod_x/src'],
            [
                new PlanFile('modules/mod_x/tmpl/new.php', 'files/0'),
                new PlanFile('modules/mod_x/tmpl/changed.php', 'files/1'),
                new PlanFile('modules/mod_x/tmpl/same.php', null),
                new PlanFile('language/de-DE/mod_x.ini', 'files/0'),
            ],
            null,
        ));

        self::assertFileDoesNotExist($this->joomla() . '/modules/mod_x/old');
        self::assertDirectoryExists($this->joomla() . '/modules/mod_x/src');
        self::assertSame('new', file_get_contents($this->joomla() . '/modules/mod_x/tmpl/new.php'));
        self::assertSame('changed', file_get_contents($this->joomla() . '/modules/mod_x/tmpl/changed.php'));
        self::assertSame('same', file_get_contents($this->joomla() . '/modules/mod_x/tmpl/same.php'));
        self::assertSame('new', file_get_contents($this->joomla() . '/language/de-DE/mod_x.ini'));
        self::assertSame(0640, fileperms($this->joomla() . '/modules/mod_x/tmpl/same.php') & 0777);
        self::assertSame(0750, fileperms($this->joomla() . '/modules/mod_x/src') & 0777);
        self::assertSame(0750, fileperms($this->joomla() . '/language') & 0777);
    }

    public function testReportsFailedOperation(): void
    {
        $this->expectException(PlanException::class);
        $this->expectExceptionMessage('Cannot delete');

        $this->apply(new Plan(0755, 0644, [new Deletion('modules/mod_x/missing.php', false)], [], [], null));
    }

    /**
     * Applies the plan with the staging and Joomla directories of the test.
     */
    private function apply(Plan $plan): void
    {
        (new PlanApplier())->apply($plan, $this->temporaryDirectory . '/staging', $this->joomla());
    }

    /**
     * Returns the Joomla root directory of the test.
     */
    private function joomla(): string
    {
        return $this->temporaryDirectory . '/joomla';
    }
}
