<?php

/**
 * @copyright  (C) 2026 Carsten Volkwein
 * @license    MIT License; see LICENSE
 */

declare(strict_types=1);

namespace SmartA\Jxt\Configuration;

/**
 * Loads the global configuration and the optional project configuration `.jxt.json`, and selects the test site.
 *
 * Values of the project configuration override the global values. `null` resets a value to its default. The test
 * sites are only defined in `sites` of the global configuration; `--site` selects one before `JXT_SITE` before
 * `default_site`. A missing site and contradictions in the keys of a site do not prevent loading; they are reported
 * by `Configuration::testSite()`, so that commands without a test site still work.
 *
 * @phpstan-import-type ConfigurationValue from ConfigurationKey
 */
final readonly class ConfigurationLoader
{
    public const string PROJECT_FILE = '.jxt.json';

    /**
     * @param string|null $globalConfigFile Path of the global configuration; null if none is used
     * @param string|null $environmentSite  Value of the environment variable `JXT_SITE`; null if it is not set
     */
    public function __construct(private ?string $globalConfigFile, private ?string $environmentSite = null)
    {
    }

    /**
     * Creates a loader for `~/.config/jxt/config.json` of the calling user.
     *
     * @param string|null $environmentSite Value of the environment variable `JXT_SITE`; null if it is not set
     */
    public static function forUser(HomeDirectoryResolver $homeDirectoryResolver, ?string $environmentSite = null): self
    {
        return new self($homeDirectoryResolver->resolve() . '/.config/jxt/config.json', $environmentSite);
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
     * @param string|null $siteOption Value of `--site`; null if it is not given
     *
     * @throws ConfigurationException for unreadable files, invalid values or an empty `--site`
     */
    public function load(string $projectDirectory, ?string $siteOption = null): Configuration
    {
        $global = $this->globalFile()?->read() ?? [];
        $project = $this->projectFile($projectDirectory)->read();
        $values = [...$global, ...$project];
        $value = fn (ConfigurationKey $key): ?string => $this->stringValue($values, $key) ?? $key->defaultValue();

        $manifest = $value(ConfigurationKey::Manifest);
        $distDirectory = (string) $value(ConfigurationKey::DistDirectory);
        $sites = $this->siteEntries($global);
        $selection = SiteSelection::choose(
            $siteOption,
            $this->environmentSite,
            $this->stringValue($global, ConfigurationKey::DefaultSite),
        );

        return new Configuration(
            $this->withoutTrailingSlash(
                str_starts_with($distDirectory, '/') ? $distDirectory : $projectDirectory . '/' . $distDirectory,
            ),
            $manifest === null ? null : $projectDirectory . '/' . $manifest,
            $this->listValue($values, ConfigurationKey::CheckExclude),
            $this->listValue($values, ConfigurationKey::CheckInclude),
            $this->prepareSteps($values, $this->projectFile($projectDirectory)->path),
            $selection->find($sites),
            $selection,
            $selection->missingMessage(
                array_map(static fn (SiteEntry $site): string => $site->id, $sites),
                $this->globalConfigFile ?? 'the global configuration',
            ),
        );
    }

    /**
     * Returns the selected site from `--site`, `JXT_SITE` and `default_site`.
     *
     * @param string|null $siteOption Value of `--site`; null if it is not given
     *
     * @throws ConfigurationException for an unreadable global configuration, invalid values or an empty `--site`
     */
    public function selection(?string $siteOption): SiteSelection
    {
        $global = $this->globalFile()?->read() ?? [];

        return SiteSelection::choose($siteOption, $this->environmentSite, $this->stringValue($global, ConfigurationKey::DefaultSite));
    }

    /**
     * Returns the sites of the global configuration.
     *
     * @return list<SiteEntry>
     *
     * @throws ConfigurationException for an unreadable file or invalid values
     */
    public function sites(): array
    {
        return $this->siteEntries($this->globalFile()?->read() ?? []);
    }

    /**
     * Returns the keys outside of the sites, except `sites` itself, with their value and its origin, as written in
     * the files.
     *
     * @return list<ConfigurationEntry>
     *
     * @throws ConfigurationException for unreadable files or invalid values
     */
    public function describe(string $projectDirectory): array
    {
        $global = $this->globalFile()?->read() ?? [];
        $project = $this->projectFile($projectDirectory)->read();
        $keys = array_filter(
            ConfigurationKey::cases(),
            static fn (ConfigurationKey $key): bool => $key->scope() !== KeyScope::Site && $key !== ConfigurationKey::Sites,
        );

        return array_values(array_map(
            static fn (ConfigurationKey $key): ConfigurationEntry => match (true) {
                array_key_exists($key->value, $project)
                    => new ConfigurationEntry($key, $project[$key->value], ConfigurationSource::Project),
                array_key_exists($key->value, $global)
                    => new ConfigurationEntry($key, $global[$key->value], ConfigurationSource::Global),
                $key->defaultValue() !== null
                    => new ConfigurationEntry($key, $key->defaultValue(), ConfigurationSource::Default),
                default => new ConfigurationEntry($key, null, ConfigurationSource::None),
            },
            $keys,
        ));
    }

    /**
     * Returns the sites of `sites`.
     *
     * @param array<string, ConfigurationValue> $global Validated values of the global configuration by key name
     *
     * @return list<SiteEntry>
     *
     * @throws ConfigurationException for an invalid site
     */
    private function siteEntries(array $global): array
    {
        $sites = $global[ConfigurationKey::Sites->value] ?? null;

        return is_array($sites) ? array_map(
            fn (int|string $id, mixed $site): SiteEntry => SiteEntry::fromValue($id, $site, (string) $this->globalConfigFile),
            array_keys($sites),
            $sites,
        ) : [];
    }

    /**
     * Returns the steps of `prepare`, or an empty list if it is not set.
     *
     * @param array<string, ConfigurationValue> $values Validated values by key name
     * @param string                            $source File the steps come from
     *
     * @return list<PrepareStep>
     *
     * @throws ConfigurationException for an invalid step
     */
    private function prepareSteps(array $values, string $source): array
    {
        $steps = $values[ConfigurationKey::Prepare->value] ?? null;

        return is_array($steps) && array_is_list($steps) ? array_map(
            static fn (int $index, mixed $step): PrepareStep
                => PrepareStep::fromValue($step, ConfigurationKey::Prepare->stepLabel($index, $source)),
            array_keys($steps),
            $steps,
        ) : [];
    }

    /**
     * Returns the value of a single-value key, or null if it is not set.
     *
     * @param array<string, ConfigurationValue> $values Validated values by key name
     */
    private function stringValue(array $values, ConfigurationKey $key): ?string
    {
        $value = $values[$key->value] ?? null;

        return is_string($value) ? $value : null;
    }

    /**
     * Returns the value of a list key, or an empty list if it is not set.
     *
     * @param array<string, ConfigurationValue> $values Validated values by key name
     *
     * @return list<string>
     */
    private function listValue(array $values, ConfigurationKey $key): array
    {
        $value = $values[$key->value] ?? null;

        return is_array($value) ? array_values(array_filter($value, is_string(...))) : [];
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
