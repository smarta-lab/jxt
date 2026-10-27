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
 * `sites` is an object with the sites by id. A site is complete in itself and inherits nothing. Missing or
 * contradicting keys do not prevent loading; they are reported when the site is used, so that a site can be changed
 * in several calls of `jxt config`.
 */
final readonly class SiteEntry
{
    public const string ID_RULE = 'It must start with a letter and may contain letters, digits, "_", "." and "-".';

    private const string ID_PATTERN = '/^[A-Za-z][A-Za-z0-9_.-]*$/';

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
     * @param array-key $id     Id of the site, its key in `sites`
     * @param string    $source File the site comes from
     *
     * @throws ConfigurationException for an invalid id or site
     */
    public static function fromValue(int|string $id, mixed $value, string $source): self
    {
        $id = (string) $id;
        $label = sprintf('Site "%s" in %s', $id, $source);

        if (!self::isValidId($id)) {
            throw new ConfigurationException(sprintf('Invalid site id "%s" in %s. %s', $id, $source, self::ID_RULE));
        }

        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new ConfigurationException(
                sprintf('%s must be an object, e.g. {"joomla_root": "/var/www/html"}.', $label),
            );
        }

        $values = [];

        foreach ($value as $name => $siteValue) {
            $key = ConfigurationKey::tryFrom((string) $name);

            if ($key === null || $key->scope() !== KeyScope::Site) {
                throw new ConfigurationException(sprintf(
                    '%s has the unknown key "%s". Allowed: %s.',
                    $label,
                    $name,
                    implode(', ', array_map(static fn (ConfigurationKey $key): string => $key->value, self::keys())),
                ));
            }

            $values[$key->value] = $key->validate($siteValue, $label);
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
     * Returns the keys a site may have.
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
     * Checks whether a value can be the id of a site: a letter, then letters, digits, `_`, `.` and `-`.
     *
     * The first letter keeps ids from being integer keys after `json_decode()`, which would make `sites` a list, and
     * from looking like an option.
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
     * Removes trailing slashes but keeps the root directory `/`.
     */
    private static function withoutTrailingSlash(string $path): string
    {
        $trimmed = rtrim($path, '/');

        return $trimmed === '' ? '/' : $trimmed;
    }
}
