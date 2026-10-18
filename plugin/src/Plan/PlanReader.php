<?php

/**
 * @copyright  (C) 2026 Carsten Volkwein
 * @license    MIT License; see LICENSE
 */

declare(strict_types=1);

namespace SmartA\Plugin\Console\Jxt\Plan;

use JsonException;

/**
 * Reads `plan.json` from the staging directory and validates it.
 *
 * jxt writes the plan as the developer; the format only has to match the plugin of the same jxt release, since
 * jxt reinstalls the plugin when the versions differ.
 */
final class PlanReader
{
    public const string PLAN_FILE = 'plan.json';

    private const string SOURCE_PATTERN = '#^files/[0-9]+$#';
    private const string NAME_PATTERN = '/^[A-Za-z0-9_][A-Za-z0-9_.-]*$/';
    private const array EXTENSION_TYPES = ['module', 'template', 'plugin'];

    /**
     * Returns the validated plan of the staging directory.
     *
     * @throws PlanException if the plan is missing, not valid JSON or contains invalid entries
     */
    public function read(string $directory): Plan
    {
        $path = $directory . '/' . self::PLAN_FILE;
        $json = is_file($path) ? file_get_contents($path) : false;

        if ($json === false) {
            throw new PlanException(sprintf('%s is not readable.', $path));
        }

        try {
            $data = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new PlanException(sprintf('%s is not valid JSON: %s', $path, $exception->getMessage()), 0, $exception);
        }

        $data = $this->map($data, 'plan');

        return new Plan(
            $this->mode($data, 'directory_mode'),
            $this->mode($data, 'file_mode'),
            array_map($this->deletion(...), $this->list($data, 'deletions')),
            array_map(
                static fn (mixed $directory): string => TargetPath::check(is_string($directory) ? $directory : ''),
                $this->list($data, 'directories'),
            ),
            array_map(fn (mixed $file): PlanFile => $this->file($file, $directory), $this->list($data, 'files')),
            ($data['extension'] ?? null) === null ? null : $this->extension($this->map($data['extension'], 'extension')),
        );
    }

    /**
     * Creates a deletion from its JSON object.
     */
    private function deletion(mixed $value): Deletion
    {
        $deletion = $this->map($value, 'deletion');

        return new Deletion(TargetPath::check($this->string($deletion, 'target')), $this->bool($deletion, 'directory'));
    }

    /**
     * Creates a file from its JSON object; the source must be a regular file in the staging directory.
     */
    private function file(mixed $value, string $directory): PlanFile
    {
        $file = $this->map($value, 'file');
        $target = TargetPath::check($this->string($file, 'target'));
        $source = $file['source'] ?? null;

        if ($source === null) {
            return new PlanFile($target, null);
        }

        if (
            !is_string($source)
            || preg_match(self::SOURCE_PATTERN, $source) !== 1
            || is_link($directory . '/' . $source)
            || !is_file($directory . '/' . $source)
        ) {
            throw new PlanException(sprintf('Invalid source "%s" for %s in the plan.', print_r($source, true), $target));
        }

        return new PlanFile($target, $source);
    }

    /**
     * Creates the identity of the extension from its JSON object.
     *
     * @param array<array-key, mixed> $extension
     */
    private function extension(array $extension): ExtensionIdentity
    {
        $type = $this->string($extension, 'type');
        $element = $this->string($extension, 'element');
        $folder = $this->string($extension, 'folder');
        $clientId = $extension['client_id'] ?? null;

        if (!in_array($type, self::EXTENSION_TYPES, true)) {
            throw new PlanException(sprintf('Unsupported extension type "%s" in the plan.', $type));
        }

        $this->checkName($element);

        if ($folder !== '') {
            $this->checkName($folder);
        }

        if ($clientId !== 0 && $clientId !== 1) {
            throw new PlanException('Invalid client_id in the plan.');
        }

        return new ExtensionIdentity($type, $element, $folder, $clientId);
    }

    /**
     * Checks that an element or plugin group is safe as a directory name.
     */
    private function checkName(string $name): void
    {
        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw new PlanException(sprintf('Invalid name "%s" in the plan.', $name));
        }
    }

    /**
     * Returns a mode between 0 and 0777.
     *
     * @param array<array-key, mixed> $data
     */
    private function mode(array $data, string $key): int
    {
        $mode = $data[$key] ?? null;

        if (!is_int($mode) || $mode < 0 || $mode > 0777) {
            throw new PlanException(sprintf('Invalid %s in the plan.', $key));
        }

        return $mode;
    }

    /**
     * Returns the list under the key.
     *
     * @param array<array-key, mixed> $data
     *
     * @return list<mixed>
     */
    private function list(array $data, string $key): array
    {
        $list = $data[$key] ?? null;

        if (!is_array($list) || !array_is_list($list)) {
            throw new PlanException(sprintf('Invalid or missing %s in the plan.', $key));
        }

        return $list;
    }

    /**
     * Returns the value as JSON object.
     *
     * @return array<array-key, mixed>
     */
    private function map(mixed $value, string $name): array
    {
        if (!is_array($value)) {
            throw new PlanException(sprintf('Invalid %s in the plan.', $name));
        }

        return $value;
    }

    /**
     * Returns the string under the key.
     *
     * @param array<array-key, mixed> $data
     */
    private function string(array $data, string $key): string
    {
        $value = $data[$key] ?? null;

        return is_string($value) ? $value : throw new PlanException(sprintf('Invalid or missing %s in the plan.', $key));
    }

    /**
     * Returns the boolean under the key.
     *
     * @param array<array-key, mixed> $data
     */
    private function bool(array $data, string $key): bool
    {
        $value = $data[$key] ?? null;

        return is_bool($value) ? $value : throw new PlanException(sprintf('Invalid or missing %s in the plan.', $key));
    }
}
