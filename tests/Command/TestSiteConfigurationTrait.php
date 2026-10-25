<?php

/**
 * @copyright  (C) 2026 Carsten Volkwein
 * @license    MIT License; see LICENSE
 */

declare(strict_types=1);

namespace SmartA\Jxt\Tests\Command;

/**
 * Writes the test site into a global configuration in the temporary directory, for commands that use the site.
 *
 * Requires `TemporaryDirectoryTrait`.
 */
trait TestSiteConfigurationTrait
{
    /**
     * Writes the sites into the global configuration; a site without id gets `local`, and the first site is the
     * default site.
     *
     * @param array<string, mixed> ...$sites
     */
    private function writeSites(array ...$sites): void
    {
        $sites = array_map(static fn (array $site): array => ['id' => 'local', ...$site], array_values($sites));

        $this->writeFile('config.json', (string) json_encode(['default_site' => $sites[0]['id'], 'sites' => $sites]));
    }

    /**
     * Returns the path of the global configuration.
     */
    private function globalConfigFile(): string
    {
        return $this->temporaryDirectory . '/config.json';
    }
}
