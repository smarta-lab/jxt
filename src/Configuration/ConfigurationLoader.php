<?php

declare(strict_types=1);

namespace SmartA\Jxt\Configuration;

/**
 * Loads the global configuration and the optional project configuration `.jxt.json`.
 *
 * Values of the project configuration override the global values. `null` resets a value,
 * e.g. `"owner": null` to disable `chown` for a project.
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
        return $this->globalConfigFile === null ? null : new ConfigurationFile($this->globalConfigFile);
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
     * @throws ConfigurationException for unreadable files or invalid values
     */
    public function load(string $projectDirectory): Configuration
    {
        $values = [...($this->globalFile()?->read() ?? []), ...$this->projectFile($projectDirectory)->read()];
        $value = static fn (ConfigurationKey $key): ?string => $values[$key->value] ?? $key->defaultValue();

        $joomlaRoot = $value(ConfigurationKey::JoomlaRoot);
        $distDirectory = (string) $value(ConfigurationKey::DistDirectory);

        return new Configuration(
            $joomlaRoot === null ? null : $this->withoutTrailingSlash($joomlaRoot),
            $value(ConfigurationKey::Owner),
            $value(ConfigurationKey::Group),
            $this->withoutTrailingSlash(
                str_starts_with($distDirectory, '/') ? $distDirectory : $projectDirectory . '/' . $distDirectory,
            ),
            (int) octdec((string) $value(ConfigurationKey::DirectoryMode)),
            (int) octdec((string) $value(ConfigurationKey::FileMode)),
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
     * Removes trailing slashes but keeps the root directory `/`.
     */
    private function withoutTrailingSlash(string $path): string
    {
        $trimmed = rtrim($path, '/');

        return $trimmed === '' ? '/' : $trimmed;
    }
}
