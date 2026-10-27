<?php

/**
 * @copyright  (C) 2026 Carsten Volkwein
 * @license    MIT License; see LICENSE
 */

declare(strict_types=1);

namespace SmartA\Jxt\Configuration;

/**
 * Allowed keys in the configuration files with their scope, default values and validation rules.
 *
 * @phpstan-type ConfigurationValue string|list<string>|non-empty-list<mixed>|non-empty-array<string, mixed>|null
 */
enum ConfigurationKey: string
{
    case JoomlaRoot = 'joomla_root';
    case WebUser = 'web_user';
    case CliCommand = 'cli_command';
    case CliRoot = 'cli_root';
    case Manifest = 'manifest';
    case Prepare = 'prepare';
    case DistDirectory = 'dist_dir';
    case CheckExclude = 'check_exclude';
    case CheckInclude = 'check_include';
    case DefaultSite = 'default_site';
    case Sites = 'sites';

    /**
     * Keys that jxt no longer reads, with the reason for the error message.
     */
    private const array REMOVED = [
        'dir_mode' => 'jxt sync uses the modes of the Joomla installer, 0755 for directories and 0644 for files',
        'file_mode' => 'jxt sync uses the modes of the Joomla installer, 0755 for directories and 0644 for files',
    ];

    private const string CHECK_NAME_PATTERN = '#^[a-z][a-z0-9-]*(/[a-z][a-z0-9-]*)?$#';

    private const string STEP_EXAMPLE = '{"run": ["npm", "run", "build"]}';

    private const string SITES_EXAMPLE = '{"local": {"joomla_root": "/var/www/html"}}';

    /**
     * Returns the default value, or null if the key is unset when not specified.
     */
    public function defaultValue(): ?string
    {
        return match ($this) {
            self::DistDirectory => 'dist',
            default => null,
        };
    }

    /**
     * Returns where the key may be set.
     */
    public function scope(): KeyScope
    {
        return match ($this) {
            self::JoomlaRoot, self::WebUser, self::CliCommand, self::CliRoot => KeyScope::Site,
            self::Manifest, self::Prepare, self::CheckExclude, self::CheckInclude => KeyScope::Project,
            self::DefaultSite, self::Sites => KeyScope::Global,
            self::DistDirectory => KeyScope::Anywhere,
        };
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
     * Single values must be non-empty strings, `default_site` the id of a site. Commands must be non-empty lists of
     * non-empty arguments. Other lists must contain check names (`group` or `group/check`) and are returned without
     * duplicates. The steps of `prepare` are returned as written, the sites of `sites` as written by id.
     *
     * @param string $source Origin of the value for the error message, e.g. the file path
     *
     * @return ConfigurationValue
     *
     * @throws ConfigurationException for an invalid value
     */
    public function validate(mixed $value, string $source): string|array|null
    {
        if ($value === null) {
            return null;
        }

        return match (true) {
            $this === self::Prepare => $this->validateSteps($value, $source),
            $this === self::Sites => $this->validateSites($value, $source),
            $this->isCommand() => $this->validateCommand($value, $source),
            $this->isList() => $this->validateList($value, $source),
            default => $this->validateString($value, $source),
        };
    }

    /**
     * Returns the value as written to a configuration file: the sites of `sites` as objects, even without keys, so
     * that `json_encode()` does not write them as lists.
     *
     * @param ConfigurationValue $value
     */
    public function jsonValue(string|array|null $value): mixed
    {
        return $this === self::Sites && is_array($value)
            ? (object) array_map(static fn (mixed $site): mixed => is_array($site) ? (object) $site : $site, $value)
            : $value;
    }

    /**
     * Returns the key for the name.
     *
     * @throws ConfigurationException for an unknown key
     */
    public static function fromName(string $name, string $source): self
    {
        if (array_key_exists($name, self::REMOVED)) {
            throw new ConfigurationException(sprintf(
                '%s in %s is no longer supported: %s. Please remove it.',
                $name,
                $source,
                self::REMOVED[$name],
            ));
        }

        return self::tryFrom($name) ?? throw new ConfigurationException(sprintf(
            'Unknown key "%s" in %s. Allowed: %s.',
            $name,
            $source,
            implode(', ', array_map(static fn (self $key): string => $key->value, self::cases())),
        ));
    }

    /**
     * Returns the label of a step of `prepare` for error messages.
     *
     * @param int    $index  Position of the step, starting at 0
     * @param string $source Origin of the value, e.g. the file path
     */
    public function stepLabel(int $index, string $source): string
    {
        return sprintf('Step %d of "%s" in %s', $index + 1, $this->value, $source);
    }

    /**
     * Checks whether the value is a command: a non-empty list of non-empty strings.
     *
     * @phpstan-assert-if-true non-empty-list<string> $value
     */
    public static function isArgumentList(mixed $value): bool
    {
        return is_array($value)
            && $value !== []
            && array_is_list($value)
            && array_filter($value, static fn (mixed $argument): bool => is_string($argument) && $argument !== '') === $value;
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
        if (!self::isArgumentList($value)) {
            throw new ConfigurationException(sprintf(
                'Value of "%s" in %s must be a non-empty list of arguments, e.g. ["docker", "exec", "web", "php"].',
                $this->value,
                $source,
            ));
        }

        return $value;
    }

    /**
     * Validates the steps of `prepare` and returns them as written.
     *
     * @return non-empty-list<mixed>
     *
     * @throws ConfigurationException for an invalid value
     */
    private function validateSteps(mixed $value, string $source): array
    {
        if (!is_array($value) || $value === [] || !array_is_list($value)) {
            throw new ConfigurationException(sprintf(
                'Value of "%s" in %s must be a non-empty list of steps, e.g. [%s].',
                $this->value,
                $source,
                self::STEP_EXAMPLE,
            ));
        }

        foreach ($value as $index => $step) {
            PrepareStep::fromValue($step, $this->stepLabel($index, $source));
        }

        return $value;
    }

    /**
     * Validates the sites of `sites` and returns them by id.
     *
     * Duplicate ids cannot be detected, since `json_decode()` keeps the last site with the same id.
     *
     * @return non-empty-array<string, mixed>
     *
     * @throws ConfigurationException for an invalid value
     */
    private function validateSites(mixed $value, string $source): array
    {
        if (!is_array($value) || $value === [] || array_is_list($value)) {
            throw new ConfigurationException(sprintf(
                'Value of "%s" in %s must be an object with the sites by id, e.g. %s.%s',
                $this->value,
                $source,
                self::SITES_EXAMPLE,
                is_array($value) && $value !== [] && array_is_list($value) ? self::sitesListHint($value) : '',
            ));
        }

        $sites = [];

        foreach ($value as $id => $site) {
            $sites[SiteEntry::fromValue($id, $site, $source)->id] = $site;
        }

        return $sites;
    }

    /**
     * Returns the sites of the list format of jxt 0.6 by id, for the error message.
     *
     * Transitional: remove before 1.0.
     *
     * @param list<mixed> $sites
     */
    private static function sitesListHint(array $sites): string
    {
        $converted = [];

        foreach ($sites as $site) {
            $id = is_array($site) ? ($site['id'] ?? null) : null;

            if (!is_string($id) || array_key_exists($id, $converted)) {
                return '';
            }

            $converted[$id] = (object) array_diff_key($site, ['id' => true]);
        }

        $json = json_encode([self::Sites->value => (object) $converted], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $json === false ? '' : PHP_EOL . 'Since jxt 0.7, sites is no longer a list; for your sites:' . PHP_EOL . $json;
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
            self::DefaultSite => SiteEntry::isValidId($value),
            default => true,
        };

        if (!$isValid) {
            throw new ConfigurationException(sprintf('Invalid value "%s" for "%s" in %s.', $value, $this->value, $source));
        }

        return $value;
    }
}
