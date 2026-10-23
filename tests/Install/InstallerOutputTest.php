<?php

/**
 * @copyright  (C) 2026 Carsten Volkwein
 * @license    MIT License; see LICENSE
 */

declare(strict_types=1);

namespace SmartA\Jxt\Tests\Install;

use PHPUnit\Framework\TestCase;
use SmartA\Jxt\Install\InstallerOutput;

/**
 * Tests for preparing the output of the Joomla installer for the console.
 */
final class InstallerOutputTest extends TestCase
{
    public function testKeepsPlainOutputUnchanged(): void
    {
        $output = "\n [OK] Folder created.\n\n [OK] Extension installed successfully.   \n\n";

        self::assertSame(
            " [OK] Folder created.\n\n [OK] Extension installed successfully.",
            (new InstallerOutput())->toText($output),
        );
    }

    public function testConvertsHtmlBetweenPlainLines(): void
    {
        $output = "Install Extension\n=================\n"
            . "<div><b>Folder a created!</b></div><div>Folder b created!</div>\n"
            . "<h2>Thank you</h2>\n<p>for installing\n    the component.</p>\n"
            . "\n [OK] Extension installed successfully.\n";

        self::assertSame(
            "Install Extension\n=================\n"
                . "Folder a created!\nFolder b created!\n\n"
                . "Thank you\n---------\n\nfor installing the component.\n"
                . "\n [OK] Extension installed successfully.",
            (new InstallerOutput())->toText($output),
        );
    }

    public function testKeepsAnElementOpenOverLinesWithoutTags(): void
    {
        $output = "<div class=\"message\">\n    Folder\n\n    created\n</div>\nPlain   text";

        self::assertSame("Folder created\nPlain   text", (new InstallerOutput())->toText($output));
    }

    public function testParagraphsWithoutEndTagDoNotSwallowTheFollowingLines(): void
    {
        $output = "<p>Done\n [OK]   Extension installed successfully.";

        self::assertSame("Done\n [OK]   Extension installed successfully.", (new InstallerOutput())->toText($output));
    }

    public function testIgnoresTagsThatAreNotHtml(): void
    {
        $output = "Error:  missing <extension> element in <manifest.xml>";

        self::assertSame($output, (new InstallerOutput())->toText($output));
    }
}
