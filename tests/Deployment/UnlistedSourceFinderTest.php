<?php

declare(strict_types=1);

namespace SmartA\Jxt\Tests\Deployment;

use PHPUnit\Framework\TestCase;
use SmartA\Jxt\Deployment\DeploymentPlan;
use SmartA\Jxt\Deployment\UnlistedSourceFinder;
use SmartA\Jxt\Manifest\ManifestParser;
use SmartA\Jxt\Tests\TemporaryDirectoryTrait;

/**
 * Tests for finding files in source directories that are not listed in the manifest.
 */
final class UnlistedSourceFinderTest extends TestCase
{
    use TemporaryDirectoryTrait;

    private string $project;

    #[\Override]
    protected function setUp(): void
    {
        $this->project = $this->createTemporaryDirectory();
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->removeTemporaryDirectory();
    }

    public function testReportsUnlistedMediaFolderAndLanguageFiles(): void
    {
        $this->writeFile('media/css/site.css');
        $this->writeFile('media/svg/icon.svg');
        $this->writeFile('media/svg/sub/other.svg');
        $this->writeFile('media/joomla.asset.json');
        $this->writeFile('media/.gitkeep');
        $this->writeFile('language/de-DE/mod_x.ini');
        $this->writeFile('language/de-DE/mod_x.sys.ini');
        $this->writeFile('language/en-GB/mod_x.ini');
        $this->writeFile('tests/SomeTest.php');

        self::assertSame(
            ['language/de-DE/mod_x.sys.ini', 'language/en-GB/', 'media/joomla.asset.json', 'media/svg/'],
            (new UnlistedSourceFinder())->find($this->plan(), $this->project),
        );
    }

    public function testReportsNothingWhenEverythingIsListed(): void
    {
        $this->writeFile('media/css/site.css');
        $this->writeFile('language/de-DE/mod_x.ini');

        self::assertSame([], (new UnlistedSourceFinder())->find($this->plan(), $this->project));
    }

    public function testIgnoresMissingSourceDirectories(): void
    {
        self::assertSame(
            [],
            (new UnlistedSourceFinder())->find(new DeploymentPlan([], [], ['media']), $this->project),
        );
    }

    /**
     * Returns the plan of a module with `media/css` and a German language file.
     */
    private function plan(): DeploymentPlan
    {
        $xml = <<<'XML'
            <extension type="module">
                <name>mod_x</name>
                <files><folder module="mod_x">tmpl</folder></files>
                <languages><language tag="de-DE">language/de-DE/mod_x.ini</language></languages>
                <media destination="mod_x" folder="media"><folder>css</folder></media>
            </extension>
            XML;

        return (new ManifestParser())->parse($xml, 'mod_x.xml')->plan;
    }
}
