<?php

declare(strict_types=1);

namespace SmartA\Jxt\Tests\Check;

use PHPUnit\Framework\TestCase;
use SmartA\Jxt\Check\Changelog;
use SmartA\Jxt\Check\CheckMode;
use SmartA\Jxt\Check\ReleaseDetector;
use SmartA\Jxt\Git\GitRepository;

/**
 * Tests for determining the release state from Git tags or the changelog.
 */
final class ReleaseDetectorTest extends TestCase
{
    use CheckProjectTrait;

    #[\Override]
    protected function setUp(): void
    {
        $this->createProject();
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->removeTemporaryDirectory();
    }

    public function testTaggedVersionIsAPreCheckAgainstItsTag(): void
    {
        $this->initGit();
        $this->git('tag', 'v1.0.0');
        $this->git('tag', 'v1.1.0');

        $state = (new ReleaseDetector())->detect('1.1.0', $this->repository(), null);

        self::assertSame(CheckMode::PreCheck, $state->mode);
        self::assertSame('v1.1.0', $state->baselineTag);
        self::assertSame('1.1.0', $state->baselineVersion);
        self::assertSame('v1.1.0 is already tagged', $state->reason);
    }

    public function testUntaggedVersionIsAFinalCheckAgainstThePreviousTag(): void
    {
        $this->initGit();
        $this->git('tag', 'v1.9.0');
        $this->git('tag', 'v1.10.0');
        $this->git('tag', 'v2.0.0');
        $this->git('tag', 'some-other-tag');

        $state = (new ReleaseDetector())->detect('1.11.0', $this->repository(), null);

        self::assertSame(CheckMode::FinalCheck, $state->mode);
        self::assertSame('v1.10.0', $state->baselineTag);
        self::assertSame('1.10.0', $state->baselineVersion);
        self::assertSame('v1.11.0 is not tagged yet', $state->reason);
    }

    public function testRecognizesTagsWithoutPrefix(): void
    {
        $this->initGit();
        $this->git('tag', '1.0.0');

        $state = (new ReleaseDetector())->detect('1.0.0', $this->repository(), null);

        self::assertSame(CheckMode::PreCheck, $state->mode);
        self::assertSame('1.0.0', $state->baselineTag);
    }

    public function testFirstReleaseHasNoBaseline(): void
    {
        $this->initGit();

        $state = (new ReleaseDetector())->detect('0.1.0', $this->repository(), null);

        self::assertSame(CheckMode::FinalCheck, $state->mode);
        self::assertNull($state->baselineTag);
        self::assertSame('v0.1.0 is not tagged yet', $state->reason);
    }

    public function testWithoutGitTheChangelogDecides(): void
    {
        $changelog = new Changelog("## [Unreleased]\n\n## [1.0.0] - 2026-01-01\n");
        $detector = new ReleaseDetector();

        self::assertSame(CheckMode::PreCheck, $detector->detect('1.0.0', null, $changelog)->mode);
        self::assertSame(CheckMode::FinalCheck, $detector->detect('1.1.0', null, $changelog)->mode);
        self::assertSame(CheckMode::PreCheck, $detector->detect('1.1.0', null, null)->mode);
        self::assertNull($detector->detect('1.0.0', null, $changelog)->baselineTag);
    }

    public function testWithoutVersionItIsAPreCheck(): void
    {
        self::assertSame(CheckMode::PreCheck, (new ReleaseDetector())->detect(null, null, null)->mode);
    }

    /**
     * Opens the Git repository of the project.
     */
    private function repository(): GitRepository
    {
        $repository = $this->context()->git;
        self::assertNotNull($repository);

        return $repository;
    }
}
