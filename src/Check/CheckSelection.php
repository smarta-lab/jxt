<?php

declare(strict_types=1);

namespace SmartA\Jxt\Check;

/**
 * Decides which checks run, from `check_exclude`/`check_include` of the project and `--exclude`/`--include`.
 *
 * All checks run by default. A name denotes all checks (`all`), a group (`manifest`) or a single check
 * (`manifest/version-semver`). The command line overrides the project; within one source the most specific
 * name wins, and for the same name an include wins over an exclude.
 */
final readonly class CheckSelection
{
    private const int PROJECT = 1;
    private const int COMMAND_LINE = 2;

    /**
     * @param list<array{string, int, bool}> $rules Name, source and whether the check runs
     */
    private function __construct(private array $rules)
    {
    }

    /**
     * Creates the selection from the lists of the project and the command line.
     *
     * @param list<string> $projectExclude
     * @param list<string> $projectInclude
     * @param list<string> $commandLineExclude
     * @param list<string> $commandLineInclude
     *
     * @throws CheckException for an unknown check name
     */
    public static function create(
        array $projectExclude = [],
        array $projectInclude = [],
        array $commandLineExclude = [],
        array $commandLineInclude = [],
    ): self {
        $rules = [
            ...self::rules($projectExclude, self::PROJECT, false),
            ...self::rules($projectInclude, self::PROJECT, true),
            ...self::rules($commandLineExclude, self::COMMAND_LINE, false),
            ...self::rules($commandLineInclude, self::COMMAND_LINE, true),
        ];

        return new self($rules);
    }

    /**
     * Checks whether the check runs.
     */
    public function isEnabled(CheckName $check): bool
    {
        $best = null;

        foreach ($this->rules as [$name, $source, $enabled]) {
            $specificity = match ($name) {
                $check->value => 3,
                $check->group() => 2,
                CheckName::ALL => 1,
                default => 0,
            };

            if ($specificity > 0) {
                $score = [$source, $specificity, (int) $enabled];
                $best = $best === null || $score > $best[0] ? [$score, $enabled] : $best;
            }
        }

        return $best === null || $best[1];
    }

    /**
     * Checks whether at least one check of the group runs.
     */
    public function isGroupEnabled(string $group): bool
    {
        foreach (CheckName::cases() as $check) {
            if ($check->group() === $group && $this->isEnabled($check)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Validates the names of one list and turns them into rules.
     *
     * @param list<string> $names
     *
     * @return list<array{string, int, bool}>
     *
     * @throws CheckException for an unknown check name
     */
    private static function rules(array $names, int $source, bool $enabled): array
    {
        foreach ($names as $name) {
            if (!CheckName::isKnown($name)) {
                throw new CheckException(sprintf(
                    'Unknown check "%s". Allowed are %s, the groups %s or a single check such as %s.',
                    $name,
                    CheckName::ALL,
                    implode(', ', CheckName::groups()),
                    CheckName::ManifestVersionSemver->value,
                ));
            }
        }

        return array_map(static fn (string $name): array => [$name, $source, $enabled], $names);
    }
}
