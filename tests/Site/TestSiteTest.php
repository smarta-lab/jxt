<?php

/**
 * @copyright  (C) 2026 Carsten Volkwein
 * @license    MIT License; see LICENSE
 */

declare(strict_types=1);

namespace SmartA\Jxt\Tests\Site;

use PHPUnit\Framework\TestCase;
use SmartA\Jxt\Site\SiteException;
use SmartA\Jxt\Site\TestSite;
use SmartA\Jxt\Tests\TemporaryDirectoryTrait;

/**
 * Tests for the test site: paths for the Joomla CLI and the staging directory.
 */
final class TestSiteTest extends TestCase
{
    use TemporaryDirectoryTrait;

    private string $root;

    #[\Override]
    protected function setUp(): void
    {
        $this->root = $this->createTemporaryDirectory();
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->removeTemporaryDirectory();
    }

    public function testKeepsPathsWithoutCliCommand(): void
    {
        $site = new TestSite('/var/www/html', 'www-data');

        self::assertFalse($site->usesCliCommand());
        self::assertSame('/var/www/html', $site->cliRoot);
        self::assertSame('/tmp/jxt-1/plan.json', $site->cliPath('/tmp/jxt-1/plan.json'));
        self::assertNull($site->stagingDirectory());
    }

    public function testTranslatesPathsBelowTheJoomlaRoot(): void
    {
        $site = new TestSite('/home/anna/site', null, ['docker', 'exec', 'web', 'php'], '/var/www/html');

        self::assertTrue($site->usesCliCommand());
        self::assertSame('/var/www/html/tmp/jxt-1', $site->cliPath('/home/anna/site/tmp/jxt-1'));
        self::assertSame('/var/www/html', $site->cliPath('/home/anna/site'));
    }

    public function testUsesTheJoomlaRootWithoutCliRoot(): void
    {
        $site = new TestSite('/srv/site', null, ['docker', 'exec', 'web', 'php']);

        self::assertSame('/srv/site', $site->cliRoot);
        self::assertSame('/srv/site/cli/joomla.php', $site->cliPath('/srv/site/cli/joomla.php'));
    }

    public function testRejectsPathsOutsideTheJoomlaRoot(): void
    {
        $site = new TestSite('/home/anna/site', null, ['docker', 'exec', 'web', 'php'], '/var/www/html');

        $this->expectException(SiteException::class);
        $this->expectExceptionMessage('/home/anna/site-old/x is outside of joomla_root /home/anna/site');

        $site->cliPath('/home/anna/site-old/x');
    }

    public function testStagesInTheTmpDirectoryOfTheSiteWithCliCommand(): void
    {
        mkdir($this->root . '/tmp');

        self::assertSame($this->root . '/tmp', (new TestSite($this->root, null, ['php']))->stagingDirectory());
    }

    public function testExplainsAStagingDirectoryThatIsNotWritable(): void
    {
        $site = new TestSite($this->root, null, ['docker', 'exec', 'web', 'php'], '/var/www/html');

        $this->expectException(SiteException::class);
        $this->expectExceptionMessage(sprintf(
            'jxt cannot write to %s/tmp, where it puts files for the Joomla CLI. Make it writable for you as well, '
            . 'e.g. with chmod 1777 /var/www/html/tmp as root in the container.',
            $this->root,
        ));

        $site->stagingDirectory();
    }

    public function testDescribesHowTheJoomlaCliRuns(): void
    {
        self::assertSame('sudo -u www-data', (new TestSite('/var/www/html', 'www-data'))->describeCli());
        self::assertSame('current user', (new TestSite('/var/www/html'))->describeCli());
        self::assertSame(
            "docker exec 'my web' php /var/www/html/cli/joomla.php",
            (new TestSite('/srv/site', 'www-data', ['docker', 'exec', 'my web', 'php'], '/var/www/html'))->describeCli(),
        );
    }
}
