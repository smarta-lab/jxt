<?php

declare(strict_types=1);

namespace SmartA\Jxt\Configuration;

use JsonException;
use SmartA\Jxt\PhpErrors;

/**
 * A configuration file in JSON format (global or `.jxt.json` in the project).
 */
final readonly class ConfigurationFile
{
    public function __construct(public string $path)
    {
    }

    /**
     * Reads and validates the file; a missing file results in an empty configuration.
     *
     * @return array<string, string|null> Values by key name
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

        foreach ($data as $name => $value) {
            $key = ConfigurationKey::fromName((string) $name, $this->path);
            $values[$key->value] = $key->validate($value, $this->path);
        }

        return $values;
    }

    /**
     * Writes the values in key order and creates missing directories.
     *
     * @param array<string, string|null> $values Values by key name
     *
     * @throws ConfigurationException if the directory or file cannot be written
     */
    public function write(array $values): void
    {
        $ordered = [];

        foreach (ConfigurationKey::cases() as $key) {
            if (array_key_exists($key->value, $values)) {
                $ordered[$key->value] = $values[$key->value];
            }
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
}
