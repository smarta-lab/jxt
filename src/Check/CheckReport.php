<?php

declare(strict_types=1);

namespace SmartA\Jxt\Check;

/**
 * Collects the findings of all checkers; findings of excluded checks are dropped.
 */
final class CheckReport
{
    /** @var list<Finding> */
    private array $findings = [];

    public function __construct(private readonly CheckSelection $selection)
    {
    }

    /**
     * Adds a finding unless its check is excluded.
     *
     * @param string|null $file Path relative to the project directory, see `Finding`
     */
    public function add(CheckName $check, Severity $severity, string $message, ?string $file = null, ?int $line = null): void
    {
        if ($this->selection->isEnabled($check)) {
            $this->findings[] = new Finding($check, $severity, $message, $file, $line);
        }
    }

    /**
     * Returns the findings of a group in the order they were added.
     *
     * @return list<Finding>
     */
    public function findings(string $group): array
    {
        return array_values(array_filter(
            $this->findings,
            static fn (Finding $finding): bool => $finding->check->group() === $group,
        ));
    }

    /**
     * Returns the number of findings with the given severity.
     */
    public function count(Severity $severity): int
    {
        return count(array_filter($this->findings, static fn (Finding $finding): bool => $finding->severity === $severity));
    }

    /**
     * Checks whether the check failed: errors always, warnings only in strict mode.
     */
    public function failed(bool $strict): bool
    {
        return $this->count(Severity::Error) > 0 || ($strict && $this->count(Severity::Warning) > 0);
    }
}
