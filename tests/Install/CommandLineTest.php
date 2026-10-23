<?php

/**
 * @copyright  (C) 2026 Carsten Volkwein
 * @license    MIT License; see LICENSE
 */

declare(strict_types=1);

namespace SmartA\Jxt\Tests\Install;

use PHPUnit\Framework\TestCase;
use SmartA\Jxt\Install\CommandLine;

/**
 * Tests for formatting commands for the output.
 */
final class CommandLineTest extends TestCase
{
    public function testQuotesOnlyArgumentsThatNeedIt(): void
    {
        self::assertSame(
            "docker exec -u www-data 'my web' php -d error_log=a,b 'it'\\''s' ''",
            CommandLine::format(['docker', 'exec', '-u', 'www-data', 'my web', 'php', '-d', 'error_log=a,b', "it's", '']),
        );
    }
}
