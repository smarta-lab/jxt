<?php

/**
 * @copyright  (C) 2026 Carsten Volkwein
 * @license    MIT License; see LICENSE
 */

declare(strict_types=1);

namespace SmartA\Jxt\Configuration;

/**
 * A test site in `sites` of the global configuration, as written there.
 *
 * Every site has an id that is unique among the sites. A site is complete in itself and inherits nothing. Missing or
 * contradicting keys do not prevent loading; they are reported when the site is used, so that a site can be changed
 * in several calls of `jxt config`.
 */
final readonly class SiteEntry
{
    public const string ID_KEY = 'id';

    private const string ID_PATTERN = '/^[A-Za-z0-9_.-]+$/';

    /**
     * @param string       $id         Id of the site
     * @param string       $source     File the site comes from, for messages
     * @param string|null  $joomlaRoot Absolute path of the site on this machine without trailing `/`
     * @param string|null  $webUser    User that runs the Joomla CLI via sudo; null to run it without sudo
     * @param list<string> $cliCommand Command that starts PHP for the Joomla CLI; empty for the PHP running jxt
     * @param string|null  $cliRoot    Path of the site as `$cliCommand` sees it, without trailing `/`; null if it
     *                                 equals `$joomlaRoot`
     */
    public function __construct(
        public string $id,
        public string $source,
        public ?string $joomlaRoot = null,
        public ?string $webUser = null,
        public array $cliCommand = [],
        public ?string $cliRoot = null,
    ) {
    }

    /**
     * Validates a site of `sites` and creates it.
     *
     * @param int    $index  Position of the site, starting at 0
     * @param string $source File the site comes from
     *
     * @throws ConfigurationException for an invalid site
     */
    public static function fromValue(mixed $value, int $index, string $source): self
    {
        $label = sprintf('Site %d of "%s" in %s', $index + 1, ConfigurationKey::Sites->value, $source);

        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new ConfigurationException(
                sprintf('%s must be an object, e.g. {"id": "local", "joomla_root": "/var/www/html"}.', $label),
            );
        }

        $id = null;
        $values = [];

        foreach ($value as $name => $siteValue) {
            if ($name === self::ID_KEY) {
                $id = self::validateId($siteValue, $label);

                continue;
            }

            $key = ConfigurationKey::tryFrom((string) $name);

            if ($key === null || $key->scope() !== KeyScope::Site) {
                throw new ConfigurationException(sprintf(
                    '%s has the unknown key "%s". Allowed: %s.',
                    $label,
                    $name,
                    implode(', ', [self::ID_KEY, ...array_map(static fn (ConfigurationKey $key): string => $key->value, self::keys())]),
                ));
            }

            $values[$key->value] = $key->validate($siteValue, $label);
        }

        if ($id === null) {
            throw new ConfigurationException(
                sprintf('%s has no id; every site needs one, e.g. {"id": "local", "joomla_root": "/var/www/html"}.', $label),
            );
        }

        $string = static fn (ConfigurationKey $key): ?string => is_string($values[$key->value] ?? null) ? $values[$key->value] : null;
        $cliCommand = $values[ConfigurationKey::CliCommand->value] ?? null;
        $joomlaRoot = $string(ConfigurationKey::JoomlaRoot);
        $cliRoot = $string(ConfigurationKey::CliRoot);

        return new self(
            $id,
            $source,
            $joomlaRoot === null ? null : self::withoutTrailingSlash($joomlaRoot),
            $string(ConfigurationKey::WebUser),
            is_array($cliCommand) ? array_values(array_filter($cliCommand, is_string(...))) : [],
            $cliRoot === null ? null : self::withoutTrailingSlash($cliRoot),
        );
    }

    /**
     * Returns the keys a site may have besides its id.
     *
     * @return list<ConfigurationKey>
     */
    public static function keys(): array
    {
        return array_values(array_filter(
            ConfigurationKey::cases(),
            static fn (ConfigurationKey $key): bool => $key->scope() === KeyScope::Site,
        ));
    }

    /**
     * Checks whether a value can be the id of a site: letters, digits, `_`, `.` and `-`.
     */
    public static function isValidId(string $id): bool
    {
        return preg_match(self::ID_PATTERN, $id) === 1;
    }

    /**
     * Returns the name of the site for messages, e.g. `site "leaflet"`.
     */
    public function name(): string
    {
        return sprintf('site "%s"', $this->id);
    }

    /**
     * Returns why the keys of the site contradict each other, or null if they do not.
     *
     * Without `web_user`, the Joomla CLI runs without sudo, as it does with `cli_command`, so both together are a
     * contradiction.
     */
    public function conflict(): ?string
    {
        return match (true) {
            $this->cliCommand !== [] && $this->webUser !== null => sprintf(
                'web_user and cli_command cannot be combined in %s of %s: with cli_command, the command itself '
                . 'determines the user. Remove web_user.',
                $this->name(),
                $this->source,
            ),
            $this->cliCommand === [] && $this->cliRoot !== null => sprintf(
                'cli_root is only used together with cli_command (in %s of %s).',
                $this->name(),
                $this->source,
            ),
            default => null,
        };
    }

    /**
     * Validates the id of a site.
     *
     * @throws ConfigurationException for an invalid id
     */
    private static function validateId(mixed $id, string $label): string
    {
        if (!is_string($id) || !self::isValidId($id)) {
            throw new ConfigurationException(
                sprintf('%s has an invalid id. It may contain letters, digits, "_", "." and "-".', $label),
            );
        }

        return $id;
    }

    /**
     * Removes trailing slashes but keeps the root directory `/`.
     */
    private static function withoutTrailingSlash(string $path): string
    {
        $trimmed = rtrim($path, '/');

        return $trimmed === '' ? '/' : $trimmed;
    }
}
