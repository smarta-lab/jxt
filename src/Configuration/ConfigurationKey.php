<?php

/**
 * @copyright  (C) 2026 Carsten Volkwein
 * @license    MIT License; see LICENSE
 */

declare(strict_types=1);

namespace SmartA\Jxt\Configuration;

/**
 * Allowed keys in the configuration files with default values and validation rules.
 */
enum ConfigurationKey: string
{
    case JoomlaRoot = 'joomla_root';
    case WebUser = 'web_user';
    case CliCommand = 'cli_command';
    case CliRoot = 'cli_root';
    case Manifest = 'manifest';
    case DistDirectory = 'dist_dir';
    case DirectoryMode = 'dir_mode';
    case FileMode = 'file_mode';
    case CheckExclude = 'check_exclude';
    case CheckInclude = 'check_include';

    private const string MODE_PATTERN = '/^0?[0-7]{3}$/';

    private const string CHECK_NAME_PATTERN = '#^[a-z][a-z0-9-]*(/[a-z][a-z0-9-]*)?$#';

    /**
     * Returns the default value, or null if the key is unset when not specified.
     */
    public function defaultValue(): ?string
    {
        return match ($this) {
            self::DistDirectory => 'dist',
            self::DirectoryMode => '0755',
            self::FileMode => '0644',
            default => null,
        };
    }

    /**
     * Checks whether the key may only be set in the project configuration `.jxt.json`.
     */
    public function isProjectOnly(): bool
    {
        return in_array($this, [self::Manifest, self::CheckExclude, self::CheckInclude], true);
    }

    /**
     * Checks whether the value is a list of strings instead of a single string.
     */
    public function isList(): bool
    {
        return in_array($this, [self::CliCommand, self::CheckExclude, self::CheckInclude], true);
    }

    /**
     * Checks whether the value is a command whose arguments are kept as they are, in order and with duplicates.
     */
    public function isCommand(): bool
    {
        return $this === self::CliCommand;
    }

    /**
     * Validates a value and returns it normalized; null is always allowed.
     *
     * Single values must be non-empty strings; modes are returned with four digits, e.g. `775` as
     * `0775`. Commands must be non-empty lists of non-empty arguments. Other lists must contain check
     * names (`group` or `group/check`) and are returned without duplicates.
     *
     * @param string $source Origin of the value for the error message, e.g. the file path
     *
     * @return string|list<string>|null
     *
     * @throws ConfigurationException for an invalid value
     */
    public function validate(mixed $value, string $source): string|array|null
    {
        if ($value === null) {
            return null;
        }

        return match (true) {
            $this->isCommand() => $this->validateCommand($value, $source),
            $this->isList() => $this->validateList($value, $source),
            default => $this->validateString($value, $source),
        };
    }

    /**
     * Returns the key for the name.
     *
     * @throws ConfigurationException for an unknown key
     */
    public static function fromName(string $name, string $source): self
    {
        return self::tryFrom($name) ?? throw new ConfigurationException(sprintf(
            'Unknown key "%s" in %s. Allowed: %s.',
            $name,
            $source,
            implode(', ', array_map(static fn (self $key): string => $key->value, self::cases())),
        ));
    }

    /**
     * Validates a command as list of arguments.
     *
     * @return non-empty-list<string>
     *
     * @throws ConfigurationException for an invalid value
     */
    private function validateCommand(mixed $value, string $source): array
    {
        if (
            !is_array($value)
            || $value === []
            || !array_is_list($value)
            || array_filter($value, static fn (mixed $argument): bool => is_string($argument) && $argument !== '') !== $value
        ) {
            throw new ConfigurationException(sprintf(
                'Value of "%s" in %s must be a non-empty list of arguments, e.g. ["docker", "exec", "web", "php"].',
                $this->value,
                $source,
            ));
        }

        return $value;
    }

    /**
     * Validates a list of check names.
     *
     * @return list<string>
     *
     * @throws ConfigurationException for an invalid value
     */
    private function validateList(mixed $value, string $source): array
    {
        if (!is_array($value) || !array_is_list($value) || array_filter($value, is_string(...)) !== $value) {
            throw new ConfigurationException(
                sprintf('Value of "%s" in %s must be a list of check names.', $this->value, $source),
            );
        }

        foreach ($value as $name) {
            if (preg_match(self::CHECK_NAME_PATTERN, $name) !== 1) {
                throw new ConfigurationException(
                    sprintf('Invalid check name "%s" for "%s" in %s.', $name, $this->value, $source),
                );
            }
        }

        return array_values(array_unique($value));
    }

    /**
     * Validates a single value.
     *
     * @throws ConfigurationException for an invalid value
     */
    private function validateString(mixed $value, string $source): string
    {
        if (!is_string($value) || trim($value) === '') {
            throw new ConfigurationException(
                sprintf('Value of "%s" in %s must be a non-empty string.', $this->value, $source),
            );
        }

        $isValid = match ($this) {
            self::JoomlaRoot, self::CliRoot => str_starts_with($value, '/'),
            self::Manifest => !str_starts_with($value, '/'),
            self::DirectoryMode, self::FileMode => preg_match(self::MODE_PATTERN, $value) === 1,
            default => true,
        };

        if (!$isValid) {
            throw new ConfigurationException(sprintf('Invalid value "%s" for "%s" in %s.', $value, $this->value, $source));
        }

        return match ($this) {
            self::DirectoryMode, self::FileMode => '0' . substr($value, -3),
            default => $value,
        };
    }
}
