<?php

/**
 * @copyright  (C) 2026 Carsten Volkwein
 * @license    MIT License; see LICENSE
 */

declare(strict_types=1);

namespace SmartA\Jxt\Configuration;

/**
 * The test sites and `default_site` in the global configuration, read and written together with its other keys.
 */
final readonly class SiteFile
{
    public function __construct(public ConfigurationFile $file)
    {
    }

    /**
     * Returns the sites as written.
     *
     * @throws ConfigurationException for an unreadable file or invalid values
     */
    public function sites(): SiteList
    {
        return SiteList::fromValue($this->file->read()[ConfigurationKey::Sites->value] ?? null);
    }

    /**
     * Returns `default_site`, or null if it is not set.
     *
     * @throws ConfigurationException for an unreadable file or invalid values
     */
    public function defaultSite(): ?string
    {
        $defaultSite = $this->file->read()[ConfigurationKey::DefaultSite->value] ?? null;

        return is_string($defaultSite) ? $defaultSite : null;
    }

    /**
     * Writes the sites and `default_site` and keeps the other keys; a key without value is removed.
     *
     * @throws ConfigurationException if a site would become invalid or the file cannot be written
     */
    public function write(SiteList $sites, ?string $defaultSite): void
    {
        $values = array_diff_key(
            $this->file->read(),
            [ConfigurationKey::Sites->value => true, ConfigurationKey::DefaultSite->value => true],
        );
        $siteValue = $sites->value();

        if ($defaultSite !== null) {
            $values[ConfigurationKey::DefaultSite->value] = $defaultSite;
        }

        if ($siteValue !== null) {
            $values[ConfigurationKey::Sites->value] = ConfigurationKey::Sites->validate($siteValue, $this->file->path);
        }

        $this->file->write($values);
    }
}
