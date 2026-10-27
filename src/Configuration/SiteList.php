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
     * @param array<string, array<array-key, mixed>> $sites The sites as written, by id
     */
    public function __construct(public array $sites = [])
    {
    }

    /**
     * Creates the list from the validated value of `sites`; empty if it is not set.
     */
    public static function fromValue(mixed $value): self
    {
        $sites = [];

        foreach (is_array($value) ? $value : [] as $id => $site) {
            if (is_array($site)) {
                $sites[(string) $id] = $site;
            }
        }

        return new self($sites);
    }

    /**
     * Returns the ids of the sites.
     *
     * @return list<string>
     */
    public function ids(): array
    {
        return array_keys($this->sites);
    }

    /**
     * Checks whether the site exists.
     */
    public function has(string $id): bool
    {
        return array_key_exists($id, $this->sites);
    }

    /**
     * Checks whether the key is set in the site.
     */
    public function hasKey(string $id, ConfigurationKey $key): bool
    {
        return $this->has($id) && array_key_exists($key->value, $this->sites[$id]);
    }

    /**
     * Returns the list with a new site without keys.
     */
    public function withSite(string $id): self
    {
        return new self([...$this->sites, $id => []]);
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
     * Returns the list with the site renamed at its position.
     */
    public function renamed(string $id, string $newId): self
    {
        if (!$this->has($id)) {
            return $this;
        }

        $sites = [];

        foreach ($this->sites as $siteId => $site) {
            $sites[$siteId === $id ? $newId : $siteId] = $site;
        }

        return new self($sites);
    }

    /**
     * Returns the list without the site.
     */
    public function withoutSite(string $id): self
    {
        return new self(array_diff_key($this->sites, [$id => true]));
    }

    /**
     * Returns the value for `sites`, or null if no site is left.
     *
     * @return array<string, array<array-key, mixed>>|null
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
        if (!$this->has($id)) {
            return $this;
        }

        $sites = $this->sites;
        $sites[$id] = $change($sites[$id]);

        return new self($sites);
    }
}
