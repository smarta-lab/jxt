<?php

/**
 * @copyright  (C) 2026 Carsten Volkwein
 * @license    MIT License; see LICENSE
 */

declare(strict_types=1);

namespace SmartA\Jxt\Tests\Command;

use PHPUnit\Framework\TestCase;
use SmartA\Jxt\Command\PackageGuard;
use SmartA\Jxt\Command\UnsupportedPackageException;
use SmartA\Jxt\Manifest\ManifestParser;

/**
 * Tests for rejecting package manifests in the commands that do not support packages yet.
 */
final class PackageGuardTest extends TestCase
{
    public function testAcceptsOtherExtensionTypes(): void
    {
        $this->expectNotToPerformAssertions();

        PackageGuard::reject((new ManifestParser())->parse('<extension type="component"><name>x</name></extension>', 'x.xml'), 'sync');
    }

    public function testRejectsPackagesWithAHintOnTheExtensions(): void
    {
        $manifest = (new ManifestParser())->parse('<extension type="package"><name>x</name><packagename>x</packagename></extension>', 'pkg_x.xml');

        $this->expectException(UnsupportedPackageException::class);
        $this->expectExceptionMessage(
            'jxt sync does not support packages yet. Run it in the directory of each extension of pkg_x.',
        );

        PackageGuard::reject($manifest, 'sync');
    }
}
