<?php

/**
 * @copyright  (C) 2026 Carsten Volkwein
 * @license    MIT License; see LICENSE
 */

declare(strict_types=1);

namespace SmartA\Jxt\Configuration;

/**
 * The sites of `sites` as written in the global configuration, changed by `jxt config` and `jxt site`.
 *
 * Sites are found by their id. Changing a key of a site that does not exist leaves the list unchanged; sites are
 * only added, removed and renamed explicitly.
 */
final readonly class SiteList
{
    /**
     * @param list<array<array-key, mixed>> $sites The sites as written
     */
    public function __construct(public array $sites = [])
    {
    }

    /**
     * Creates the list from the validated value of `sites`; null if it is not set.
     */
    public static function fromValue(mixed $value): self
    {
        return new self(is_array($value) ? array_values(array_filter($value, is_array(...))) : []);
    }

    /**
     * Returns the ids of the sites.
     *
     * @return list<string>
     */
    public function ids(): array
    {
        return array_values(array_filter(
            array_map(static fn (array $site): mixed => $site[SiteEntry::ID_KEY] ?? null, $this->sites),
            is_string(...),
        ));
    }

    /**
     * Checks whether the site exists.
     */
    public function has(string $id): bool
    {
        return $this->index($id) !== null;
    }

    /**
     * Checks whether the key is set in the site.
     */
    public function hasKey(string $id, ConfigurationKey $key): bool
    {
        $index = $this->index($id);

        return $index !== null && array_key_exists($key->value, $this->sites[$index]);
    }

    /**
     * Returns the list with a new site that has only its id.
     */
    public function withSite(string $id): self
    {
        return new self([...$this->sites, [SiteEntry::ID_KEY => $id]]);
    }

    /**
     * Returns the list with the value set in the site.
     */
    public function with(string $id, ConfigurationKey $key, mixed $value): self
    {
        return $this->changed($id, static function (array $site) use ($key, $value): array {
            $site[$key->value] = $value;

            return $site;
        });
    }

    /**
     * Returns the list without the key in the site; the site itself stays.
     */
    public function without(string $id, ConfigurationKey $key): self
    {
        return $this->changed($id, static fn (array $site): array => array_diff_key($site, [$key->value => true]));
    }

    /**
     * Returns the list with the site renamed.
     */
    public function renamed(string $id, string $newId): self
    {
        return $this->changed($id, static fn (array $site): array => [...$site, SiteEntry::ID_KEY => $newId]);
    }

    /**
     * Returns the list without the site.
     */
    public function withoutSite(string $id): self
    {
        $index = $this->index($id);

        return $index === null
            ? $this
            : new self(array_values(array_filter($this->sites, static fn (int $i): bool => $i !== $index, ARRAY_FILTER_USE_KEY)));
    }

    /**
     * Returns the value for `sites`, or null if no site is left.
     *
     * @return list<array<array-key, mixed>>|null
     */
    public function value(): ?array
    {
        return $this->sites === [] ? null : $this->sites;
    }

    /**
     * Returns the list with the site changed by the callback; unchanged if the site does not exist.
     *
     * @param \Closure(array<array-key, mixed>): array<array-key, mixed> $change
     */
    private function changed(string $id, \Closure $change): self
    {
        $index = $this->index($id);

        if ($index === null) {
            return $this;
        }

        $sites = $this->sites;
        $sites[$index] = $change($sites[$index]);

        return new self($sites);
    }

    /**
     * Returns the position of the site, or null if it does not exist.
     */
    private function index(string $id): ?int
    {
        foreach ($this->sites as $index => $site) {
            if (($site[SiteEntry::ID_KEY] ?? null) === $id) {
                return $index;
            }
        }

        return null;
    }
}
