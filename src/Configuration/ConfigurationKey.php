<?php

declare(strict_types=1);

namespace SmartA\Jxt\Configuration;

/**
 * Allowed keys in the configuration files with default values and validation rules.
 */
enum ConfigurationKey: string
{
    case JoomlaRoot = 'joomla_root';
    case Owner = 'owner';
    case Group = 'group';
    case DistDirectory = 'dist_dir';
    case DirectoryMode = 'dir_mode';
    case FileMode = 'file_mode';

    private const string MODE_PATTERN = '/^0?[0-7]{3}$/';

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
     * Validates a value and returns it normalized. Allowed are non-empty strings or null.
     *
     * Modes are returned with four digits, e.g. `775` as `0775`.
     *
     * @param string $source Origin of the value for the error message, e.g. the file path
     *
     * @throws ConfigurationException for an invalid value
     */
    public function validate(mixed $value, string $source): ?string
    {
        if ($value === null) {
            return null;
        }

        if (!is_string($value) || trim($value) === '') {
            throw new ConfigurationException(
                sprintf('Value of "%s" in %s must be a non-empty string.', $this->value, $source),
            );
        }

        $isValid = match ($this) {
            self::JoomlaRoot => str_starts_with($value, '/'),
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
}
