<?php

/**
 * @copyright  (C) 2026 Carsten Volkwein
 * @license    MIT License; see LICENSE
 */

declare(strict_types=1);

namespace SmartA\Jxt\Tests\Configuration;

use PHPUnit\Framework\TestCase;
use SmartA\Jxt\Configuration\ConfigurationKey;
use SmartA\Jxt\Configuration\SiteList;

/**
 * Tests for changing the sites of the global configuration.
 */
final class SiteListTest extends TestCase
{
    public function testReturnsTheIds(): void
    {
        self::assertSame(['local', 'ddev'], $this->sites()->ids());
    }

    public function testChangesTheSiteWithTheId(): void
    {
        $sites = $this->sites()->with('ddev', ConfigurationKey::WebUser, 'www-data');

        self::assertSame([
            'local' => ['joomla_root' => '/var/www/html'],
            'ddev' => ['joomla_root' => '/srv/ddev', 'web_user' => 'www-data'],
        ], $sites->sites);
    }

    public function testChangesNoSiteThatDoesNotExist(): void
    {
        self::assertSame($this->sites()->sites, $this->sites()->with('j5', ConfigurationKey::WebUser, 'www-data')->sites);
    }

    public function testAddsASiteWithoutKeys(): void
    {
        self::assertSame(['local', 'ddev', 'j5'], $this->sites()->withSite('j5')->ids());
        self::assertSame([], $this->sites()->withSite('j5')->sites['j5'] ?? null);
    }

    public function testRemovesAKeyAndKeepsTheSite(): void
    {
        $sites = $this->sites()->without('ddev', ConfigurationKey::JoomlaRoot);

        self::assertSame([], $sites->sites['ddev'] ?? null);
        self::assertFalse($sites->hasKey('ddev', ConfigurationKey::JoomlaRoot));
        self::assertTrue($sites->hasKey('local', ConfigurationKey::JoomlaRoot));
    }

    public function testRenamesASiteAtItsPosition(): void
    {
        $sites = $this->sites()->renamed('local', 'default');

        self::assertSame(['default' => ['joomla_root' => '/var/www/html'], 'ddev' => ['joomla_root' => '/srv/ddev']], $sites->sites);
    }

    public function testRemovesASite(): void
    {
        self::assertSame(['local' => ['joomla_root' => '/var/www/html']], $this->sites()->withoutSite('ddev')->value());
    }

    public function testValueIsNullWithoutSites(): void
    {
        self::assertNull($this->sites()->withoutSite('local')->withoutSite('ddev')->value());
    }

    /**
     * Returns the sites `local` and `ddev`.
     */
    private function sites(): SiteList
    {
        return SiteList::fromValue(['local' => ['joomla_root' => '/var/www/html'], 'ddev' => ['joomla_root' => '/srv/ddev']]);
    }
}
