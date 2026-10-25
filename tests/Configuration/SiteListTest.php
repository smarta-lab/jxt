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
            ['id' => 'local', 'joomla_root' => '/var/www/html'],
            ['id' => 'ddev', 'joomla_root' => '/srv/ddev', 'web_user' => 'www-data'],
        ], $sites->sites);
    }

    public function testChangesNoSiteThatDoesNotExist(): void
    {
        self::assertSame($this->sites()->sites, $this->sites()->with('j5', ConfigurationKey::WebUser, 'www-data')->sites);
    }

    public function testAddsASiteWithOnlyItsId(): void
    {
        self::assertSame(['id' => 'j5'], $this->sites()->withSite('j5')->sites[2]);
    }

    public function testRemovesAKeyAndKeepsTheSite(): void
    {
        $sites = $this->sites()->without('ddev', ConfigurationKey::JoomlaRoot);

        self::assertSame(['id' => 'ddev'], $sites->sites[1]);
        self::assertFalse($sites->hasKey('ddev', ConfigurationKey::JoomlaRoot));
        self::assertTrue($sites->hasKey('local', ConfigurationKey::JoomlaRoot));
    }

    public function testRenamesASite(): void
    {
        $sites = $this->sites()->renamed('local', 'default');

        self::assertSame(['id' => 'default', 'joomla_root' => '/var/www/html'], $sites->sites[0]);
        self::assertFalse($sites->has('local'));
    }

    public function testRemovesASite(): void
    {
        self::assertSame([['id' => 'local', 'joomla_root' => '/var/www/html']], $this->sites()->withoutSite('ddev')->value());
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
        return SiteList::fromValue([['id' => 'local', 'joomla_root' => '/var/www/html'], ['id' => 'ddev', 'joomla_root' => '/srv/ddev']]);
    }
}
