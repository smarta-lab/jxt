<?php

/**
 * @copyright  (C) 2026 Carsten Volkwein
 * @license    MIT License; see LICENSE
 */

declare(strict_types=1);

namespace SmartA\Jxt\Configuration;

/**
 * Loads the global configuration and the optional project configuration `.jxt.json`.
 *
 * Values of the project configuration override the global values. `null` resets a value to its default;
 * only for `web_user`, an explicit `null` means running the Joomla CLI without sudo. With `cli_command`, the
 * Joomla CLI runs without sudo as well, and an explicit `web_user` is a contradiction.
 */
final readonly class ConfigurationLoader
{
    public const string PROJECT_FILE = '.jxt.json';

    /**
     * @param string|null $globalConfigFile Path of the global configuration; null if none is used
     */
    public function __construct(private ?string $globalConfigFile)
    {
    }

    /**
     * Creates a loader for `~/.config/jxt/config.json` of the calling user.
     */
    public static function forUser(HomeDirectoryResolver $homeDirectoryResolver): self
    {
        return new self($homeDirectoryResolver->resolve() . '/.config/jxt/config.json');
    }

    /**
     * Returns the global configuration file, or null if none is used.
     */
    public function globalFile(): ?ConfigurationFile
    {
        return $this->globalConfigFile === null ? null : new ConfigurationFile($this->globalConfigFile, true);
    }

    /**
     * Returns the configuration file of the project.
     */
    public function projectFile(string $projectDirectory): ConfigurationFile
    {
        return new ConfigurationFile($projectDirectory . '/' . self::PROJECT_FILE);
    }

    /**
     * Loads the configuration for the given project directory.
     *
     * @throws ConfigurationException for unreadable files, invalid values or contradicting keys
     */
    public function load(string $projectDirectory): Configuration
    {
        $globalFile = $this->globalFile();
        $projectFile = $this->projectFile($projectDirectory);
        $global = $globalFile?->read() ?? [];
        $project = $projectFile->read();
        $values = [...$global, ...$project];
        $value = fn (ConfigurationKey $key): ?string => $this->stringValue($values, $key) ?? $key->defaultValue();
        $origin = static fn (ConfigurationKey $key): string => array_key_exists($key->value, $project)
            ? $projectFile->path
            : (string) $globalFile?->path;

        $joomlaRoot = $value(ConfigurationKey::JoomlaRoot);
        $manifest = $value(ConfigurationKey::Manifest);
        $distDirectory = (string) $value(ConfigurationKey::DistDirectory);
        $cliCommand = $this->listValue($values, ConfigurationKey::CliCommand);
        $cliRoot = $value(ConfigurationKey::CliRoot);
        $webUser = $this->stringValue($values, ConfigurationKey::WebUser);

        if ($cliCommand !== [] && $webUser !== null) {
            throw new ConfigurationException(sprintf(
                'web_user (in %s) and cli_command (in %s) cannot be combined: with cli_command, the command itself '
                . 'determines the user. Remove web_user or set it to null in %s.',
                $origin(ConfigurationKey::WebUser),
                $origin(ConfigurationKey::CliCommand),
                $origin(ConfigurationKey::CliCommand),
            ));
        }

        if ($cliCommand === [] && $cliRoot !== null) {
            throw new ConfigurationException(sprintf(
                'cli_root is only used together with cli_command (cli_root in %s).',
                $origin(ConfigurationKey::CliRoot),
            ));
        }

        return new Configuration(
            $joomlaRoot === null ? null : $this->withoutTrailingSlash($joomlaRoot),
            array_key_exists(ConfigurationKey::WebUser->value, $values) || $cliCommand !== []
                ? $webUser
                : ConfigurationKey::WebUser->defaultValue(),
            $this->withoutTrailingSlash(
                str_starts_with($distDirectory, '/') ? $distDirectory : $projectDirectory . '/' . $distDirectory,
            ),
            (int) octdec((string) $value(ConfigurationKey::DirectoryMode)),
            (int) octdec((string) $value(ConfigurationKey::FileMode)),
            $manifest === null ? null : $projectDirectory . '/' . $manifest,
            $this->listValue($values, ConfigurationKey::CheckExclude),
            $this->listValue($values, ConfigurationKey::CheckInclude),
            $cliCommand,
            $cliRoot === null ? null : $this->withoutTrailingSlash($cliRoot),
        );
    }

    /**
     * Returns all keys with their value and its origin, as written in the files.
     *
     * @return list<ConfigurationEntry>
     *
     * @throws ConfigurationException for unreadable files or invalid values
     */
    public function describe(string $projectDirectory): array
    {
        $global = $this->globalFile()?->read() ?? [];
        $project = $this->projectFile($projectDirectory)->read();

        return array_map(
            static fn (ConfigurationKey $key): ConfigurationEntry => match (true) {
                array_key_exists($key->value, $project)
                    => new ConfigurationEntry($key, $project[$key->value], ConfigurationSource::Project),
                array_key_exists($key->value, $global)
                    => new ConfigurationEntry($key, $global[$key->value], ConfigurationSource::Global),
                $key->defaultValue() !== null
                    => new ConfigurationEntry($key, $key->defaultValue(), ConfigurationSource::Default),
                default => new ConfigurationEntry($key, null, ConfigurationSource::None),
            },
            ConfigurationKey::cases(),
        );
    }

    /**
     * Returns the value of a single-value key, or null if it is not set.
     *
     * @param array<string, string|list<string>|null> $values Validated values by key name
     */
    private function stringValue(array $values, ConfigurationKey $key): ?string
    {
        $value = $values[$key->value] ?? null;

        return is_string($value) ? $value : null;
    }

    /**
     * Returns the value of a list key, or an empty list if it is not set.
     *
     * @param array<string, string|list<string>|null> $values Validated values by key name
     *
     * @return list<string>
     */
    private function listValue(array $values, ConfigurationKey $key): array
    {
        $value = $values[$key->value] ?? null;

        return is_array($value) ? $value : [];
    }

    /**
     * Removes trailing slashes but keeps the root directory `/`.
     */
    private function withoutTrailingSlash(string $path): string
    {
        $trimmed = rtrim($path, '/');

        return $trimmed === '' ? '/' : $trimmed;
    }
}
