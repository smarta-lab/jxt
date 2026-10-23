<?php

/**
 * @copyright  (C) 2026 Carsten Volkwein
 * @license    MIT License; see LICENSE
 */

declare(strict_types=1);

namespace SmartA\Jxt\Tests\Plugin;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SmartA\Jxt\Tests\TemporaryDirectoryTrait;
use SmartA\Plugin\Console\Jxt\Plan\Deletion;
use SmartA\Plugin\Console\Jxt\Plan\ExtensionIdentity;
use SmartA\Plugin\Console\Jxt\Plan\PlanException;
use SmartA\Plugin\Console\Jxt\Plan\PlanFile;
use SmartA\Plugin\Console\Jxt\Plan\PlanReader;

/**
 * Tests for reading and validating the plan staged by jxt.
 */
final class PlanReaderTest extends TestCase
{
    use TemporaryDirectoryTrait;

    #[\Override]
    protected function setUp(): void
    {
        $this->createTemporaryDirectory();
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->removeTemporaryDirectory();
    }

    public function testReadsPlan(): void
    {
        $this->writeFile('files/0', 'content');
        $this->writePlan([
            'format' => PlanReader::FORMAT,
            'directory_mode' => 0755,
            'file_mode' => 0644,
            'deletions' => [
                ['target' => 'modules/mod_x/old/file.php', 'directory' => false],
                ['target' => 'modules/mod_x/old', 'directory' => true],
            ],
            'directories' => ['modules/mod_x', 'modules/mod_x/tmpl'],
            'files' => [
                ['target' => 'modules/mod_x/tmpl/default.php', 'source' => 'files/0'],
                ['target' => 'modules/mod_x/mod_x.xml', 'source' => null],
            ],
            'extension' => ['type' => 'module', 'element' => 'mod_x', 'folder' => '', 'client_id' => 0],
        ]);

        $plan = (new PlanReader())->read($this->temporaryDirectory);

        self::assertSame(0755, $plan->directoryMode);
        self::assertSame(0644, $plan->fileMode);
        self::assertEquals([
            new Deletion('modules/mod_x/old/file.php', false),
            new Deletion('modules/mod_x/old', true),
        ], $plan->deletions);
        self::assertSame(['modules/mod_x', 'modules/mod_x/tmpl'], $plan->directories);
        self::assertEquals([
            new PlanFile('modules/mod_x/tmpl/default.php', 'files/0'),
            new PlanFile('modules/mod_x/mod_x.xml', null),
        ], $plan->files);
        self::assertEquals(new ExtensionIdentity('module', 'mod_x', '', 0), $plan->extension);
        self::assertSame(1, $plan->copyCount());
    }

    public function testReadsLibraryWithVendorInTheElement(): void
    {
        $this->writePlan([
            'format' => PlanReader::FORMAT,
            'directory_mode' => 0755,
            'file_mode' => 0644,
            'deletions' => [],
            'directories' => ['libraries/acme/example'],
            'files' => [['target' => 'administrator/manifests/libraries/acme/example.xml', 'source' => null]],
            'extension' => ['type' => 'library', 'element' => 'acme/example', 'folder' => '', 'client_id' => 0],
        ]);

        $plan = (new PlanReader())->read($this->temporaryDirectory);

        self::assertEquals(new ExtensionIdentity('library', 'acme/example', '', 0), $plan->extension);
    }

    public function testExtensionIsOptional(): void
    {
        $this->writePlan([
            'format' => PlanReader::FORMAT,
            'directory_mode' => 0755,
            'file_mode' => 0644,
            'deletions' => [],
            'directories' => [],
            'files' => [],
        ]);

        self::assertNull((new PlanReader())->read($this->temporaryDirectory)->extension);
    }

    public function testFailsWithoutPlanFile(): void
    {
        $this->expectException(PlanException::class);
        $this->expectExceptionMessage('plan.json');

        (new PlanReader())->read($this->temporaryDirectory);
    }

    public function testFailsForInvalidJson(): void
    {
        $this->writeFile('plan.json', '{');

        $this->expectException(PlanException::class);
        $this->expectExceptionMessage('not valid JSON');

        (new PlanReader())->read($this->temporaryDirectory);
    }

    /**
     * @param array<string, mixed> $changes
     * @param non-empty-string     $expectedMessage
     */
    #[DataProvider('invalidPlans')]
    public function testRejectsInvalidPlan(array $changes, string $expectedMessage): void
    {
        $this->writeFile('files/0', 'content');
        $this->writePlan([
            'format' => PlanReader::FORMAT,
            'directory_mode' => 0755,
            'file_mode' => 0644,
            'deletions' => [],
            'directories' => [],
            'files' => [],
            ...$changes,
        ]);

        $this->expectException(PlanException::class);
        $this->expectExceptionMessage($expectedMessage);

        (new PlanReader())->read($this->temporaryDirectory);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, non-empty-string}>
     */
    public static function invalidPlans(): iterable
    {
        yield 'missing format' => [['format' => null], 'Plan format (none) is not supported'];
        yield 'newer format' => [['format' => PlanReader::FORMAT + 1], 'Update jxt in this project'];
        yield 'older format' => [['format' => PlanReader::MIN_FORMAT - 1], 'jxt bridge add'];
        yield 'missing key' => [['files' => null], 'files'];
        yield 'mode out of range' => [['file_mode' => 01000], 'file_mode'];
        yield 'deletion outside' => [
            ['deletions' => [['target' => 'configuration.php', 'directory' => false]]],
            'configuration.php',
        ];
        yield 'directory outside' => [['directories' => ['libraries']], 'libraries'];
        yield 'file outside' => [[
            'files' => [['target' => 'cli/joomla.php', 'source' => 'files/0']],
        ], 'cli/joomla.php'];
        yield 'source outside the staging directory' => [[
            'files' => [['target' => 'modules/mod_x/x.php', 'source' => '../plan.json']],
        ], '../plan.json'];
        yield 'missing source' => [[
            'files' => [['target' => 'modules/mod_x/x.php', 'source' => 'files/1']],
        ], 'files/1'];
        yield 'unknown extension type' => [
            ['extension' => ['type' => 'file', 'element' => 'x', 'folder' => '', 'client_id' => 0]],
            'file',
        ];
        yield 'slash in the element of a module' => [
            ['extension' => ['type' => 'module', 'element' => 'acme/x', 'folder' => '', 'client_id' => 0]],
            'acme/x',
        ];
        yield 'invalid library name' => [
            ['extension' => ['type' => 'library', 'element' => 'acme/../x', 'folder' => '', 'client_id' => 0]],
            'acme/../x',
        ];
        yield 'invalid element' => [
            ['extension' => ['type' => 'module', 'element' => '../x', 'folder' => '', 'client_id' => 0]],
            '../x',
        ];
        yield 'invalid client' => [
            ['extension' => ['type' => 'module', 'element' => 'mod_x', 'folder' => '', 'client_id' => 3]],
            'client_id',
        ];
    }

    /**
     * Writes `plan.json` into the staging directory.
     *
     * @param array<string, mixed> $plan
     */
    private function writePlan(array $plan): void
    {
        $this->writeFile('plan.json', json_encode($plan, JSON_THROW_ON_ERROR));
    }
}
