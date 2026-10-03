<?php

declare(strict_types=1);

namespace SmartA\Jxt\Tests\Sync;

use PHPUnit\Framework\TestCase;
use SmartA\Jxt\Deployment\EntryKind;
use SmartA\Jxt\Manifest\Manifest;
use SmartA\Jxt\Manifest\ManifestParser;
use SmartA\Jxt\Sync\Deletion;
use SmartA\Jxt\Sync\FileStatus;
use SmartA\Jxt\Sync\SyncPlan;
use SmartA\Jxt\Sync\SyncPlanner;
use SmartA\Jxt\Tests\TemporaryDirectoryTrait;

/**
 * Tests for determining the copy and delete operations of `jxt sync`.
 */
final class SyncPlannerTest extends TestCase
{
    use TemporaryDirectoryTrait;

    #[\Override]
    protected function setUp(): void
    {
        $this->createTemporaryDirectory();
        mkdir($this->project());
        mkdir($this->joomla());
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->removeTemporaryDirectory();
    }

    public function testClassifiesFilesAsNewChangedOrUnchanged(): void
    {
        $xml = $this->manifestXml();
        $this->writeFile('project/mod_x.xml', $xml);
        $this->writeFile('project/tmpl/a.php', 'A');
        $this->writeFile('project/tmpl/b.php', 'B');
        $this->writeFile('project/tmpl/c.php', 'C');
        $this->writeFile('joomla/modules/mod_x/mod_x.xml', $xml);
        $this->writeFile('joomla/modules/mod_x/tmpl/a.php', 'A');
        $this->writeFile('joomla/modules/mod_x/tmpl/b.php', 'alt');

        $plan = $this->plan($xml, $xml);

        self::assertSame([
            'modules/mod_x/mod_x.xml' => FileStatus::Unchanged,
            'modules/mod_x/tmpl/a.php' => FileStatus::Unchanged,
            'modules/mod_x/tmpl/b.php' => FileStatus::Changed,
            'modules/mod_x/tmpl/c.php' => FileStatus::New,
        ], $this->statuses($plan));
        self::assertSame([], $plan->deletions);
        self::assertSame(2, count($plan->changedFiles()));
        self::assertSame(2, $plan->unchangedCount());
    }

    public function testDeletesStaleEntriesInExclusiveDirectories(): void
    {
        $oldXml = $this->manifestXml(media: '<media destination="mod_x" folder="media"><folder>css</folder></media>');
        $newXml = $this->manifestXml();
        $this->writeFile('project/mod_x.xml', $newXml);
        $this->writeFile('project/tmpl/a.php', 'A');
        $this->writeFile('joomla/modules/mod_x/mod_x.xml', $oldXml);
        $this->writeFile('joomla/modules/mod_x/helper/x.php');
        $this->writeFile('joomla/modules/mod_x/tmpl/a.php', 'A');
        $this->writeFile('joomla/modules/mod_x/tmpl/old.php');
        mkdir($this->joomla() . '/modules/mod_x/tmpl/sub');
        $this->writeFile('joomla/media/mod_x/css/old.css');
        $this->writeFile('joomla/media/other/keep.css');

        $plan = $this->plan($newXml, $oldXml);

        self::assertEquals([
            new Deletion('modules/mod_x/helper/x.php', EntryKind::File),
            new Deletion('modules/mod_x/helper', EntryKind::Directory),
            new Deletion('modules/mod_x/tmpl/old.php', EntryKind::File),
            new Deletion('modules/mod_x/tmpl/sub', EntryKind::Directory),
            new Deletion('media/mod_x/css/old.css', EntryKind::File),
            new Deletion('media/mod_x/css', EntryKind::Directory),
            new Deletion('media/mod_x', EntryKind::Directory),
        ], $plan->deletions);
    }

    public function testDeletesOnlyOwnObsoleteLanguageFiles(): void
    {
        $oldXml = $this->manifestXml(languages: '<languages>'
            . '<language tag="de-DE">language/de-DE/mod_x.ini</language>'
            . '<language tag="de-DE">language/de-DE/mod_x.sys.ini</language>'
            . '<language tag="en-GB">language/en-GB/mod_x.ini</language>'
            . '</languages>');
        $newXml = $this->manifestXml(languages: '<languages>'
            . '<language tag="de-DE">language/de-DE/mod_x.ini</language>'
            . '</languages>');
        $this->writeFile('project/mod_x.xml', $newXml);
        $this->writeFile('project/tmpl/a.php');
        $this->writeFile('project/language/de-DE/mod_x.ini');
        $this->writeFile('joomla/modules/mod_x/mod_x.xml', $oldXml);
        $this->writeFile('joomla/language/de-DE/mod_x.ini');
        $this->writeFile('joomla/language/de-DE/mod_x.sys.ini');
        $this->writeFile('joomla/language/de-DE/foreign.ini');

        $plan = $this->plan($newXml, $oldXml);

        self::assertEquals([new Deletion('language/de-DE/mod_x.sys.ini', EntryKind::File)], $plan->deletions);
    }

    public function testListsManagedDirectoriesParentsFirst(): void
    {
        $xml = $this->manifestXml(media: '<media destination="mod_x" folder="media"><folder>css</folder></media>');
        $this->writeFile('project/mod_x.xml', $xml);
        $this->writeFile('project/tmpl/sub/a.php');
        $this->writeFile('project/media/css/a.css');

        $plan = $this->plan($xml, $xml);

        self::assertSame(
            ['media/mod_x', 'media/mod_x/css', 'modules/mod_x', 'modules/mod_x/tmpl', 'modules/mod_x/tmpl/sub'],
            $plan->directories,
        );
    }

    public function testDeletesAutoloadCacheWhenNamespaceChanges(): void
    {
        $oldXml = $this->manifestXml(namespace: '<namespace path="src">SmartA\Module\Old</namespace>');
        $newXml = $this->manifestXml(namespace: '<namespace path="src">SmartA\Module\X</namespace>');
        $this->writeFile('project/mod_x.xml', $newXml);
        $this->writeFile('project/tmpl/a.php');
        $this->writeFile('joomla/administrator/cache/autoload_psr4.php');

        $plan = $this->plan($newXml, $oldXml);

        self::assertTrue($plan->comparison->namespaceChanged);
        self::assertEquals(
            [new Deletion('administrator/cache/autoload_psr4.php', EntryKind::File)],
            $plan->deletions,
        );
    }

    public function testKeepsAutoloadCacheWhenNamespaceIsUnchanged(): void
    {
        $xml = $this->manifestXml(namespace: '<namespace path="src">SmartA\Module\X</namespace>');
        $this->writeFile('project/mod_x.xml', $xml);
        $this->writeFile('project/tmpl/a.php');
        $this->writeFile('joomla/administrator/cache/autoload_psr4.php');

        $plan = $this->plan($xml, $xml);

        self::assertFalse($plan->comparison->namespaceChanged);
        self::assertSame([], $plan->deletions);
    }

    public function testDetectsChangedInstallerSectionsAndFiles(): void
    {
        $oldXml = $this->manifestXml(installer: '<scriptfile>script.php</scriptfile>'
            . '<updateservers><server>https://example.org/old.xml</server></updateservers>');
        $newXml = $this->manifestXml(installer: '<scriptfile>script.php</scriptfile>'
            . '<updateservers><server>https://example.org/new.xml</server></updateservers>');
        $this->writeFile('project/mod_x.xml', $newXml);
        $this->writeFile('project/tmpl/a.php');
        $this->writeFile('project/script.php', 'neu');
        $this->writeFile('joomla/modules/mod_x/script.php', 'alt');

        $plan = $this->plan($newXml, $oldXml);

        self::assertSame(['updateservers'], $plan->comparison->changedInstallerSections);
        self::assertSame(['modules/mod_x/script.php'], $plan->changedInstallerFiles);
    }

    public function testDetectsNewFilesInSchemaDirectory(): void
    {
        $xml = $this->manifestXml(
            files: '<folder module="mod_x">tmpl</folder><folder>sql</folder>',
            installer: '<update><schemas><schemapath type="mysql">sql/updates</schemapath></schemas></update>',
        );
        $this->writeFile('project/mod_x.xml', $xml);
        $this->writeFile('project/tmpl/a.php');
        $this->writeFile('project/sql/updates/1.0.0.sql');
        $this->writeFile('project/sql/updates/1.1.0.sql');
        $this->writeFile('joomla/modules/mod_x/sql/updates/1.0.0.sql');

        $plan = $this->plan($xml, $xml);

        self::assertSame([], $plan->comparison->changedInstallerSections);
        self::assertSame(['modules/mod_x/sql/updates/1.1.0.sql'], $plan->changedInstallerFiles);
    }

    public function testReportsVersionChange(): void
    {
        $this->writeFile('project/mod_x.xml');
        $this->writeFile('project/tmpl/a.php');

        $plan = $this->plan($this->manifestXml(version: '1.1.0'), $this->manifestXml(version: '1.0.0'));

        self::assertTrue($plan->comparison->versionChanged());
        self::assertSame('1.0.0', $plan->comparison->oldVersion);
        self::assertSame('1.1.0', $plan->comparison->newVersion);
    }

    /**
     * Creates the sync plan from the new and the installed manifest.
     */
    private function plan(string $newXml, string $oldXml): SyncPlan
    {
        return (new SyncPlanner())->plan($this->parse($newXml), $this->parse($oldXml), $this->project(), $this->joomla());
    }

    /**
     * Parses a manifest with the file name `mod_x.xml`.
     */
    private function parse(string $xml): Manifest
    {
        return (new ManifestParser())->parse($xml, 'mod_x.xml');
    }

    /**
     * Returns the status per target path.
     *
     * @return array<string, FileStatus>
     */
    private function statuses(SyncPlan $plan): array
    {
        $statuses = [];

        foreach ($plan->files as $file) {
            $statuses[$file->entry->target] = $file->status;
        }

        return $statuses;
    }

    /**
     * Returns a module manifest for `mod_x` with replaceable sections.
     */
    private function manifestXml(
        string $version = '1.0.0',
        string $files = '<folder module="mod_x">tmpl</folder>',
        string $languages = '',
        string $media = '',
        string $namespace = '',
        string $installer = '',
    ): string {
        return '<extension type="module"><name>mod_x</name>'
            . '<version>' . $version . '</version>' . $namespace . $installer
            . '<files>' . $files . '</files>' . $languages . $media
            . '</extension>';
    }

    /**
     * Returns the project directory.
     */
    private function project(): string
    {
        return $this->temporaryDirectory . '/project';
    }

    /**
     * Returns the Joomla root directory.
     */
    private function joomla(): string
    {
        return $this->temporaryDirectory . '/joomla';
    }
}
