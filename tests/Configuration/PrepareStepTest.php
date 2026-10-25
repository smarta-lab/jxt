<?php

/**
 * @copyright  (C) 2026 Carsten Volkwein
 * @license    MIT License; see LICENSE
 */

declare(strict_types=1);

namespace SmartA\Jxt\Tests\Configuration;

use PHPUnit\Framework\TestCase;
use SmartA\Jxt\Configuration\ConfigurationException;
use SmartA\Jxt\Configuration\PrepareStep;

/**
 * Tests for the command a prepare step runs.
 */
final class PrepareStepTest extends TestCase
{
    public function testRunsTheSameCommandEverywhereWithoutSync(): void
    {
        $step = new PrepareStep(['npm', 'run', 'css']);

        self::assertSame(['npm', 'run', 'css'], $step->command(false));
        self::assertSame(['npm', 'run', 'css'], $step->command(true));
    }

    public function testRunsTheReplacementForSync(): void
    {
        $step = new PrepareStep(['npm', 'run', 'minify'], ['npm', 'run', 'minify:copy']);

        self::assertSame(['npm', 'run', 'minify'], $step->command(false));
        self::assertSame(['npm', 'run', 'minify:copy'], $step->command(true));
    }

    public function testIsSkippedForSyncWithFalse(): void
    {
        $step = new PrepareStep(['npm', 'run', 'images'], false);

        self::assertSame(['npm', 'run', 'images'], $step->command(false));
        self::assertNull($step->command(true));
    }

    public function testCreatesStepsFromObjects(): void
    {
        self::assertEquals(new PrepareStep(['npm']), PrepareStep::fromValue(['run' => ['npm']], 'Step 1'));
        self::assertEquals(new PrepareStep(['npm'], ['cp']), PrepareStep::fromValue(['run' => ['npm'], 'sync' => ['cp']], 'Step 1'));
        self::assertEquals(new PrepareStep(['npm'], false), PrepareStep::fromValue(['run' => ['npm'], 'sync' => false], 'Step 1'));
    }

    public function testRejectsACommandWithoutRun(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('Step 1 must be an object with "run" and optionally "sync", e.g. {"run": ["npm", "run", "build"]}.');

        PrepareStep::fromValue(['npm', 'run', 'build'], 'Step 1');
    }
}
