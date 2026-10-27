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
     * Writes the sites into the global configuration; a site given as positional argument gets the id `local`, others
     * are named arguments by id, and the first site is the default site.
     *
     * @param array<string, mixed> ...$sites
     */
    private function writeSites(array ...$sites): void
    {
        $sites = array_combine(
            array_map(static fn (int|string $id): string => is_int($id) ? 'local' : $id, array_keys($sites)),
            array_map(static fn (array $site): object => (object) $site, $sites),
        );

        $this->writeFile('config.json', (string) json_encode(['default_site' => array_key_first($sites), 'sites' => (object) $sites]));
    }

    /**
     * Returns the path of the global configuration.
     */
    private function globalConfigFile(): string
    {
        return $this->temporaryDirectory . '/config.json';
    }
}
