<?php

declare(strict_types=1);

namespace SmartA\Jxt\Check;

/**
 * Formats findings as GitHub Actions workflow commands, which GitHub shows as annotations.
 *
 * See https://docs.github.com/en/actions/reference/workflows-and-actions/workflow-commands
 */
final readonly class GithubAnnotation
{
    /**
     * Returns the workflow command of a finding, titled with the check name.
     *
     * @param string $pathPrefix Path of the project directory relative to the repository root, empty or ending in `/`
     */
    public static function forFinding(Finding $finding, string $pathPrefix = ''): string
    {
        return self::command($finding->severity, $finding->message, array_filter([
            'file' => $finding->file !== null ? $pathPrefix . $finding->file : null,
            'line' => $finding->line,
            'title' => $finding->check->value,
        ], static fn (string|int|null $value): bool => $value !== null));
    }

    /**
     * Returns a workflow command such as `::error file=a.php,line=3::message`.
     *
     * @param array<string, string|int> $properties
     */
    public static function command(Severity $severity, string $message, array $properties = []): string
    {
        $pairs = array_map(
            static fn (string $name, string|int $value): string => $name . '=' . self::escapeProperty((string) $value),
            array_keys($properties),
            array_values($properties),
        );

        return sprintf(
            '::%s%s::%s',
            match ($severity) {
                Severity::Error => 'error',
                Severity::Warning => 'warning',
                Severity::Notice => 'notice',
            },
            $pairs === [] ? '' : ' ' . implode(',', $pairs),
            self::escapeData($message),
        );
    }

    /**
     * Escapes the message as the GitHub Actions toolkit does.
     */
    private static function escapeData(string $value): string
    {
        return strtr($value, ['%' => '%25', "\r" => '%0D', "\n" => '%0A']);
    }

    /**
     * Escapes a property value, which additionally must not contain `:` and `,`.
     */
    private static function escapeProperty(string $value): string
    {
        return strtr(self::escapeData($value), [':' => '%3A', ',' => '%2C']);
    }
}
