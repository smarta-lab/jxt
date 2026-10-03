<?php

declare(strict_types=1);

namespace SmartA\Jxt\Tests\Configuration;

use PHPUnit\Framework\TestCase;
use SmartA\Jxt\Configuration\ConfigurationException;
use SmartA\Jxt\Configuration\HomeDirectoryResolver;

/**
 * Tests for determining the home directory, also when called via sudo.
 */
final class HomeDirectoryResolverTest extends TestCase
{
    public function testUsesHomeForNormalUser(): void
    {
        $resolver = new HomeDirectoryResolver(['HOME' => '/home/anna', 'SUDO_USER' => 'bert'], 1000, $this->lookup(...));

        self::assertSame('/home/anna', $resolver->resolve());
    }

    public function testUsesHomeOfSudoUserForRoot(): void
    {
        $resolver = new HomeDirectoryResolver(['HOME' => '/root', 'SUDO_USER' => 'anna'], 0, $this->lookup(...));

        self::assertSame('/home/anna', $resolver->resolve());
    }

    public function testUsesHomeForRootWithoutSudo(): void
    {
        $resolver = new HomeDirectoryResolver(['HOME' => '/root'], 0, $this->lookup(...));

        self::assertSame('/root', $resolver->resolve());
    }

    public function testFailsForUnknownSudoUser(): void
    {
        $resolver = new HomeDirectoryResolver(['HOME' => '/root', 'SUDO_USER' => 'nobody'], 0, $this->lookup(...));

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessageIsOrContains('nobody');

        $resolver->resolve();
    }

    public function testFailsWithoutHome(): void
    {
        $resolver = new HomeDirectoryResolver([], 1000, $this->lookup(...));

        $this->expectException(ConfigurationException::class);

        $resolver->resolve();
    }

    /**
     * Replaces the user database lookup.
     */
    private function lookup(string $user): ?string
    {
        return $user === 'anna' ? '/home/anna' : null;
    }
}
