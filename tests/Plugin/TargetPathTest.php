<?php

/**
 * @copyright  (C) 2026 Carsten Volkwein
 * @license    MIT License; see LICENSE
 */

declare(strict_types=1);

namespace SmartA\Jxt\Tests\Plugin;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SmartA\Plugin\Console\Jxt\Plan\PlanException;
use SmartA\Plugin\Console\Jxt\Plan\TargetPath;

/**
 * Tests for the targets the bridge plugin accepts in a plan.
 */
final class TargetPathTest extends TestCase
{
    #[DataProvider('allowedTargets')]
    public function testAcceptsTargetsOfExtensions(string $target): void
    {
        self::assertSame($target, TargetPath::check($target));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function allowedTargets(): iterable
    {
        yield 'module directory' => ['modules/mod_x'];
        yield 'module file' => ['modules/mod_x/tmpl/default.php'];
        yield 'administrator module' => ['administrator/modules/mod_x/mod_x.xml'];
        yield 'template' => ['templates/cassiopeia/index.php'];
        yield 'administrator template' => ['administrator/templates/atum/index.php'];
        yield 'plugin' => ['plugins/system/example/example.xml'];
        yield 'site component' => ['components/com_x/src/Controller/DisplayController.php'];
        yield 'administrator component' => ['administrator/components/com_x/x.xml'];
        yield 'api component' => ['api/components/com_x'];
        yield 'media' => ['media/templates/site/cassiopeia/css/template.css'];
        yield 'language file' => ['language/de-DE/mod_x.ini'];
        yield 'administrator language file' => ['administrator/language/en-GB/plg_system_example.ini'];
        yield 'autoload cache' => ['administrator/cache/autoload_psr4.php'];
    }

    /**
     * @param non-empty-string $expectedMessage
     */
    #[DataProvider('rejectedTargets')]
    public function testRejectsOtherTargets(string $target, string $expectedMessage): void
    {
        $this->expectException(PlanException::class);
        $this->expectExceptionMessage($expectedMessage);

        TargetPath::check($target);
    }

    /**
     * @return iterable<string, array{string, non-empty-string}>
     */
    public static function rejectedTargets(): iterable
    {
        yield 'empty' => ['', 'Invalid target ""'];
        yield 'absolute' => ['/etc/passwd', 'Invalid target "/etc/passwd"'];
        yield 'parent segment' => ['modules/mod_x/../../configuration.php', 'Invalid target'];
        yield 'dot segment' => ['modules/./mod_x', 'Invalid target'];
        yield 'empty segment' => ['modules//mod_x', 'Invalid target'];
        yield 'trailing slash' => ['modules/mod_x/', 'Invalid target'];
        yield 'backslash' => ['modules\\mod_x', 'Invalid target'];
        yield 'configuration' => ['configuration.php', 'outside the directories of extensions'];
        yield 'all modules' => ['modules', 'outside the directories of extensions'];
        yield 'plugin group' => ['plugins/system', 'outside the directories of extensions'];
        yield 'all components' => ['components', 'outside the directories of extensions'];
        yield 'all administrator components' => ['administrator/components', 'outside the directories of extensions'];
        yield 'all api components' => ['api/components', 'outside the directories of extensions'];
        yield 'api entry point' => ['api/index.php', 'outside the directories of extensions'];
        yield 'all media' => ['media', 'outside the directories of extensions'];
        yield 'language directory' => ['language/de-DE', 'outside the directories of extensions'];
        yield 'nested language file' => ['language/de-DE/sub/x.ini', 'outside the directories of extensions'];
        yield 'other cache file' => ['administrator/cache/other.php', 'outside the directories of extensions'];
        yield 'core library' => ['libraries/src/Factory.php', 'outside the directories of extensions'];
    }
}
