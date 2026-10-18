<?php

/**
 * @copyright  (C) 2026 Carsten Volkwein
 * @license    MIT License; see LICENSE
 */

declare(strict_types=1);

namespace SmartA\Jxt\Tests\Sync;

use PHPUnit\Framework\TestCase;
use SmartA\Jxt\Deployment\EntryKind;
use SmartA\Jxt\Deployment\PlanExpander;
use SmartA\Jxt\Manifest\Manifest;
use SmartA\Jxt\Manifest\ManifestParser;
use SmartA\Jxt\Sync\Deletion;
use SmartA\Jxt\Sync\LeftoverFinder;
use SmartA\Jxt\Tests\TemporaryDirectoryTrait;

/**
 * Tests for finding leftovers in the exclusive directories of an extension.
 */
final class LeftoverFinderTest extends TestCase
{
    use TemporaryDirectoryTrait;

    private const string MEDIA = '<media destination="mod_x" folder="media"><folder>css</folder></media>';

    #[\Override]
    protected function setUp(): void
    {
        $this->createTemporaryDirectory();
        $this->writeFile('project/mod_x.xml');
        $this->writeFile('project/tmpl/default.php');
        $this->writeFile('project/media/css/x.css');
        $this->writeFile('joomla/modules/mod_x/mod_x.xml');
        $this->writeFile('joomla/modules/mod_x/tmpl/default.php');
        $this->writeFile('joomla/media/mod_x/css/x.css');
        $this->writeFile('joomla/media/other/keep.css');
        $this->writeFile('joomla/language/de-DE/mod_y.ini');
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->removeTemporaryDirectory();
    }

    public function testFindsNothingWhenServerMatchesPlan(): void
    {
        $manifest = $this->parse(self::MEDIA);

        self::assertSame([], $this->find($manifest, $manifest));
    }

    public function testFindsLeftoversWithDirectoryContentsFirst(): void
    {
        $this->writeFile('joomla/modules/mod_x/tmpl/old.php');
        $this->writeFile('joomla/modules/mod_x/helper/x.php');
        mkdir($this->temporaryDirectory . '/joomla/media/mod_x/js');
        $manifest = $this->parse(self::MEDIA);

        self::assertEquals([
            new Deletion('modules/mod_x/helper/x.php', EntryKind::File),
            new Deletion('modules/mod_x/helper', EntryKind::Directory),
            new Deletion('modules/mod_x/tmpl/old.php', EntryKind::File),
            new Deletion('media/mod_x/js', EntryKind::Directory),
        ], $this->find($manifest, $manifest));
    }

    public function testFindsExclusiveDirectoryOfInstalledManifestOnly(): void
    {
        $new = $this->parse();

        self::assertEquals([
            new Deletion('media/mod_x/css/x.css', EntryKind::File),
            new Deletion('media/mod_x/css', EntryKind::Directory),
            new Deletion('media/mod_x', EntryKind::Directory),
        ], $this->find($new, $this->parse(self::MEDIA)));
    }

    public function testDoesNotFollowSymbolicLinks(): void
    {
        symlink($this->temporaryDirectory . '/joomla/media/other', $this->temporaryDirectory . '/joomla/modules/mod_x/link');
        $manifest = $this->parse(self::MEDIA);

        self::assertEquals(
            [new Deletion('modules/mod_x/link', EntryKind::File)],
            $this->find($manifest, $manifest),
        );
    }

    public function testTopLevelListsEachLeftoverDirectoryOnceAndSorts(): void
    {
        $leftovers = [
            new Deletion('modules/mod_x/tmpl/old.php', EntryKind::File),
            new Deletion('modules/mod_x/helper/a/b.php', EntryKind::File),
            new Deletion('modules/mod_x/helper/a', EntryKind::Directory),
            new Deletion('modules/mod_x/helper', EntryKind::Directory),
            new Deletion('modules/mod_x/helpers.php', EntryKind::File),
        ];

        self::assertEquals([
            new Deletion('modules/mod_x/helper', EntryKind::Directory),
            new Deletion('modules/mod_x/helpers.php', EntryKind::File),
            new Deletion('modules/mod_x/tmpl/old.php', EntryKind::File),
        ], (new LeftoverFinder())->topLevel($leftovers));
    }

    /**
     * Runs the finder with the project and Joomla directories of the test.
     *
     * @return list<Deletion>
     */
    private function find(Manifest $new, Manifest $old): array
    {
        $files = (new PlanExpander())->expand($new->plan, $this->temporaryDirectory . '/project');

        return (new LeftoverFinder())->find($new, $old, $files, $this->temporaryDirectory . '/joomla');
    }

    /**
     * Parses the manifest of `mod_x` with an optional `<media>` section.
     */
    private function parse(string $media = ''): Manifest
    {
        return (new ManifestParser())->parse(
            '<extension type="module"><name>mod_x</name><files><folder module="mod_x">tmpl</folder></files>'
                . $media . '</extension>',
            'mod_x.xml',
        );
    }
}
