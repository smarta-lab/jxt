<?php

/**
 * @copyright  (C) 2026 Carsten Volkwein
 * @license    MIT License; see LICENSE
 */

declare(strict_types=1);

namespace SmartA\Jxt\Configuration;

use JsonException;
use SmartA\Jxt\PhpErrors;

/**
 * A configuration file in JSON format (global or `.jxt.json` in the project).
 *
 * @phpstan-import-type ConfigurationValue from ConfigurationKey
 */
final readonly class ConfigurationFile
{
    /**
     * @param bool $isGlobal Whether this is the global configuration, where project-only keys are not allowed and
     *                       `sites` is
     */
    public function __construct(public string $path, public bool $isGlobal = false)
    {
    }

    /**
     * Reads and validates the file; a missing file results in an empty configuration.
     *
     * @return array<string, ConfigurationValue> Values by key name
     *
     * @throws ConfigurationException for an unreadable file, invalid JSON, unknown keys or values
     */
    public function read(): array
    {
        if (!file_exists($this->path)) {
            return [];
        }

        $content = is_readable($this->path) ? file_get_contents($this->path) : false;

        if ($content === false) {
            throw new ConfigurationException(sprintf('Configuration %s is not readable.', $this->path));
        }

        try {
            $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new ConfigurationException(
                sprintf('Configuration %s does not contain valid JSON: %s', $this->path, $exception->getMessage()),
                0,
                $exception,
            );
        }

        if (!is_array($data) || ($data !== [] && array_is_list($data))) {
            throw new ConfigurationException(sprintf('Configuration %s must be a JSON object.', $this->path));
        }

        $values = [];
        $siteValues = [];

        foreach ($data as $name => $value) {
            $key = ConfigurationKey::fromName((string) $name, $this->path);

            if ($key->scope() === KeyScope::Site) {
                $siteValues[$key->value] = $value;

                continue;
            }

            $this->assertAllowed($key);
            $values[$key->value] = $key->validate($value, $this->path);
        }

        if ($siteValues !== []) {
            throw $this->siteKeysError($siteValues);
        }

        return $values;
    }

    /**
     * Writes the values in key order and creates missing directories; without values, the file is deleted.
     *
     * @param array<string, ConfigurationValue> $values Values by key name
     *
     * @throws ConfigurationException if a key is not allowed in this file or the file cannot be written or deleted
     */
    public function write(array $values): void
    {
        $ordered = [];

        foreach (ConfigurationKey::cases() as $key) {
            if (array_key_exists($key->value, $values)) {
                $this->assertAllowed($key);
                $ordered[$key->value] = $values[$key->value];
            }
        }

        if ($ordered === []) {
            $this->delete();

            return;
        }

        $json = json_encode((object) $ordered, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $directory = dirname($this->path);

        [$written, $error] = PhpErrors::capture(
            fn (): bool => (is_dir($directory) || mkdir($directory, 0777, true))
                && file_put_contents($this->path, $json . "\n") !== false,
        );

        if (!$written) {
            throw new ConfigurationException(
                sprintf('Cannot write configuration %s%s', $this->path, $error === null ? '.' : ': ' . $error),
            );
        }
    }

    /**
     * Deletes the file if it exists, since an empty configuration equals a missing one.
     *
     * @throws ConfigurationException if the file cannot be deleted
     */
    private function delete(): void
    {
        [$deleted, $error] = PhpErrors::capture(fn (): bool => !file_exists($this->path) || unlink($this->path));

        if (!$deleted) {
            throw new ConfigurationException(
                sprintf('Cannot delete configuration %s%s', $this->path, $error === null ? '.' : ': ' . $error),
            );
        }
    }

    /**
     * Rejects project-only keys in the global configuration, `sites` in the project configuration and the keys of a
     * site at the top level of either.
     *
     * @throws ConfigurationException if the key is not allowed in this file
     */
    private function assertAllowed(ConfigurationKey $key): void
    {
        $message = match (true) {
            $key->scope() === KeyScope::Site => throw $this->siteKeysError([$key->value => null]),
            $this->isGlobal && $key->scope() === KeyScope::Project => '%s can only be set in the project configuration %s, not in %s.',
            !$this->isGlobal && $key->scope() === KeyScope::Global => '%s can only be set in the global configuration, not in %3$s.',
            default => null,
        };

        if ($message !== null) {
            throw new ConfigurationException(sprintf($message, $key->value, ConfigurationLoader::PROJECT_FILE, $this->path));
        }
    }

    /**
     * Returns the error for keys of the test site at the top level, with a site that takes their values.
     *
     * @param array<string, mixed> $siteValues Values as written, by key name
     */
    private function siteKeysError(array $siteValues): ConfigurationException
    {
        $id = $this->isGlobal ? 'local' : $this->projectSiteId();
        $site = array_filter($siteValues, static fn (mixed $value): bool => $value !== null);
        $example = json_encode(
            [
                ...($this->isGlobal ? [ConfigurationKey::DefaultSite->value => $id] : []),
                ConfigurationKey::Sites->value => [[SiteEntry::ID_KEY => $id, ...$site]],
            ],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );

        return new ConfigurationException(sprintf(
            '%s can no longer be set at the top level of %s: the keys of the test site belong to a site in "%s" of '
            . 'the global configuration ~/.config/jxt/config.json%s%s',
            implode(', ', array_keys($siteValues)),
            $this->path,
            ConfigurationKey::Sites->value,
            $site === [] || $example === false ? '.' : ', e.g.:' . PHP_EOL . $example,
            $this->isGlobal ? '' : PHP_EOL . sprintf(
                'Select it for this project with --site or %s, e.g. in its .envrc.',
                SiteSelection::ENVIRONMENT_VARIABLE,
            ),
        ));
    }

    /**
     * Returns an id for a site that takes the values of this project, after its directory.
     */
    private function projectSiteId(): string
    {
        $id = (string) preg_replace('/[^A-Za-z0-9_.-]+/', '-', basename(dirname($this->path)));

        return SiteEntry::isValidId($id) ? $id : 'project';
    }
}
