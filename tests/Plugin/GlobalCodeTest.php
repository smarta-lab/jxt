<?php

/**
 * @copyright  (C) 2026 Carsten Volkwein
 * @license    MIT License; see LICENSE
 */

declare(strict_types=1);

namespace SmartA\Jxt\Tests\Plugin;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Guards the plugin files without namespace, such as `script.php` and `services/provider.php`: there, a `use`
 * statement for a global class has no effect, and PHP warns about it on every run of the Joomla CLI.
 */
final class GlobalCodeTest extends TestCase
{
    #[DataProvider('filesWithoutNamespace')]
    public function testHasNoUseStatementForGlobalClasses(string $path): void
    {
        self::assertDoesNotMatchRegularExpression('/^use \w+;/m', (string) file_get_contents($path));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function filesWithoutNamespace(): iterable
    {
        $directory = dirname(__DIR__, 2) . '/plugin';

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory)) as $file) {
            $path = $file->getPathname();

            if ($file->getExtension() === 'php' && preg_match('/^namespace /m', (string) file_get_contents($path)) !== 1) {
                yield substr($path, strlen($directory) + 1) => [$path];
            }
        }
    }
}
