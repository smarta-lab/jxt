<?php

/**
 * @copyright  (C) 2026 Carsten Volkwein
 * @license    MIT License; see LICENSE
 */

declare(strict_types=1);

namespace SmartA\Jxt\Tests\Command;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SmartA\Jxt\Bridge\Bridge;
use SmartA\Jxt\Command\BridgeAction;
use SmartA\Jxt\Command\BridgeCommand;
use SmartA\Jxt\Command\BuildCommand;
use SmartA\Jxt\Command\CheckCommand;
use SmartA\Jxt\Command\ConfigAction;
use SmartA\Jxt\Command\ConfigCommand;
use SmartA\Jxt\Command\InstallCommand;
use SmartA\Jxt\Command\SiteAction;
use SmartA\Jxt\Command\SiteCommand;
use SmartA\Jxt\Command\SyncCommand;
use SmartA\Jxt\Configuration\ConfigurationLoader;
use SmartA\Jxt\Install\JoomlaInstaller;
use SmartA\Jxt\Sync\SyncExecutor;
use Symfony\Component\Console\Command\Command;

/**
 * Tests that the command overview of the usage documentation names every command, action and option.
 */
final class UsageOverviewTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function documents(): array
    {
        return [
            'English' => ['docs/USAGE.md', 'Commands at a glance'],
            'German' => ['docs/USAGE.de.md', 'Befehle im Überblick'],
        ];
    }

    #[DataProvider('documents')]
    public function testOverviewNamesEveryCommandActionAndOption(string $document, string $heading): void
    {
        $overview = $this->overview($document, $heading);
        $commands = $this->commands();

        self::assertSame(array_keys($commands), array_keys($overview), 'Commands of the overview');

        foreach ($commands as $name => [$command, $actions]) {
            foreach ($command->getDefinition()->getOptions() as $option) {
                self::assertStringContainsString('--' . $option->getName(), $overview[$name], "Option of jxt $name");
            }

            foreach ($actions as $action) {
                self::assertMatchesRegularExpression(
                    '/\b' . preg_quote($action, '/') . '\b/',
                    $overview[$name],
                    "Action of jxt $name",
                );
            }
        }
    }

    /**
     * Returns the commands with the values of their action argument, in the order of the overview.
     *
     * @return array<string, array{Command, list<string>}>
     */
    private function commands(): array
    {
        $loader = new ConfigurationLoader(null);

        return [
            'install' => [new InstallCommand('.', $loader, new JoomlaInstaller(), 'user'), []],
            'sync' => [new SyncCommand('.', $loader, new SyncExecutor(), new Bridge(), 'user'), []],
            'bridge' => [new BridgeCommand('.', $loader, new Bridge(), 'user'), self::values(BridgeAction::cases())],
            'build' => [new BuildCommand('.', $loader), []],
            'check' => [new CheckCommand('.', $loader), []],
            'site' => [new SiteCommand('.', $loader, false), self::values(SiteAction::cases())],
            'config' => [new ConfigCommand('.', $loader, false), self::values(ConfigAction::cases())],
        ];
    }

    /**
     * Returns the values of the action cases.
     *
     * @param list<BridgeAction|SiteAction|ConfigAction> $cases
     *
     * @return list<string>
     */
    private static function values(array $cases): array
    {
        return array_map(static fn(BridgeAction|SiteAction|ConfigAction $case): string => $case->value, $cases);
    }

    /**
     * Returns the lines of the first code block below the heading, joined per command.
     *
     * A line starting with `jxt <command>` begins an entry, indented lines continue it.
     *
     * @return array<string, string> Lines by command, in the order of the overview
     */
    private function overview(string $document, string $heading): array
    {
        $content = (string) file_get_contents(dirname(__DIR__, 2) . '/' . $document);
        $pattern = '/^## ' . preg_quote($heading, '/') . '\n.*?^```\w*\n(.*?)^```/ms';
        self::assertSame(1, preg_match($pattern, $content, $match), "Overview in $document");

        $overview = [];
        $name = null;

        foreach (explode("\n", rtrim($match[1])) as $line) {
            if (preg_match('/^jxt ([a-z]+)/', $line, $command) === 1) {
                $name = $command[1];
                $overview[$name] = ($overview[$name] ?? '') . $line . "\n";
            } elseif ($name !== null && str_starts_with($line, ' ')) {
                $overview[$name] .= $line . "\n";
            }
        }

        return $overview;
    }
}
