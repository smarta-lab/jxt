<?php

/**
 * @copyright  (C) 2026 Carsten Volkwein
 * @license    MIT License; see LICENSE
 */

declare(strict_types=1);

namespace SmartA\Jxt\Configuration;

/**
 * A step of the `prepare` setting: a command that generates files before jxt builds, checks or syncs the
 * extension, optionally replaced or skipped for `jxt sync`.
 */
final readonly class PrepareStep
{
    private const string EXAMPLE = '{"run": ["npm", "run", "build"]}';

    /**
     * @param non-empty-list<string>            $run  Command for `jxt build`, `jxt install` and `jxt check`
     * @param non-empty-list<string>|false|null $sync Command for `jxt sync` instead of `$run`; false to skip the
     *                                                step there, null to run `$run`
     */
    public function __construct(public array $run, public array|false|null $sync = null)
    {
    }

    /**
     * Creates the step from its value in the configuration, an object with `run` and optionally `sync`.
     *
     * @param string $label Step and origin for error messages, e.g. `Step 2 of "prepare" in .jxt.json`
     *
     * @throws ConfigurationException for an invalid step
     */
    public static function fromValue(mixed $value, string $label): self
    {
        if (!is_array($value) || $value === [] || array_is_list($value)) {
            throw new ConfigurationException(sprintf(
                '%s must be an object with "run" and optionally "sync", e.g. %s.',
                $label,
                self::EXAMPLE,
            ));
        }

        foreach (array_keys($value) as $name) {
            if ($name !== 'run' && $name !== 'sync') {
                throw new ConfigurationException(sprintf('%s has unknown key "%s". Allowed: run, sync.', $label, $name));
            }
        }

        $run = $value['run'] ?? null;

        if (!ConfigurationKey::isArgumentList($run)) {
            throw new ConfigurationException(
                sprintf('%s needs "run" with a non-empty list of arguments, e.g. %s.', $label, self::EXAMPLE),
            );
        }

        return new self($run, array_key_exists('sync', $value) ? self::sync($value['sync'], $label) : null);
    }

    /**
     * Returns the command to run, or null if the step is skipped.
     *
     * @param bool $forSync Whether the step runs for `jxt sync`
     *
     * @return non-empty-list<string>|null
     */
    public function command(bool $forSync): ?array
    {
        return match (true) {
            !$forSync, $this->sync === null => $this->run,
            $this->sync === false => null,
            default => $this->sync,
        };
    }

    /**
     * Validates the value of `sync`, which must be a command or false.
     *
     * @return non-empty-list<string>|false
     *
     * @throws ConfigurationException for null or another invalid value
     */
    private static function sync(mixed $value, string $label): array|false
    {
        if ($value === false || ConfigurationKey::isArgumentList($value)) {
            return $value;
        }

        throw new ConfigurationException($value === null
            ? sprintf(
                '%s: sync: null is ambiguous. Omit sync to run the same command, or use false to skip the step for jxt sync.',
                $label,
            )
            : sprintf('%s needs "sync" with a non-empty list of arguments, or false to skip the step for jxt sync.', $label));
    }
}
