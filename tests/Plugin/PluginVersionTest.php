<?php

/**
 * @copyright  (C) 2026 Carsten Volkwein
 * @license    MIT License; see LICENSE
 */

declare(strict_types=1);

namespace SmartA\Jxt\Tests\Plugin;

use PHPUnit\Framework\TestCase;
use SmartA\Jxt\Bridge\Bridge;
use SmartA\Jxt\Git\GitRepository;
use SmartA\Jxt\Install\SystemProcessRunner;
use SmartA\Jxt\Manifest\ManifestParser;

/**
 * Guards the version check of the bridge: jxt reinstalls the plugin only when its version differs, so a change
 * in `plugin/` since the last release tag needs a higher version in the plugin's manifest.
 *
 * Needs the tags, so CI checks out the full history.
 */
final class PluginVersionTest extends TestCase
{
    private const string MANIFEST = 'plugin/jxt.xml';

    public function testChangedPluginHasHigherVersionThanInLastRelease(): void
    {
        $root = dirname(__DIR__, 2);
        $git = GitRepository::open($root, new SystemProcessRunner());
        $tag = $git === null ? null : $this->lastReleaseTag($git->tags());

        if ($git === null || $tag === null) {
            self::markTestSkipped('No Git repository or no release tag.');
        }

        $parser = new ManifestParser();
        $current = (string) $parser->parseFile(Bridge::BUNDLED_DIRECTORY . '/jxt.xml')->version;
        $released = $git->show($tag, self::MANIFEST);

        if ($released === null || !$git->hasChanged($tag, 'plugin')) {
            $this->addToAssertionCount(1);

            return;
        }

        self::assertTrue(
            version_compare($current, (string) $parser->parse($released, 'jxt.xml')->version, '>'),
            sprintf('plugin/ changed since %s, so the version in %s must be raised.', $tag, self::MANIFEST),
        );
    }

    /**
     * Returns the highest tag of the form `v<semver>`, or null if there is none.
     *
     * @param list<string> $tags
     */
    private function lastReleaseTag(array $tags): ?string
    {
        $releases = array_values(array_filter($tags, static fn (string $tag): bool => preg_match('/^v\d+\.\d+\.\d+$/', $tag) === 1));
        usort($releases, static fn (string $a, string $b): int => version_compare(substr($a, 1), substr($b, 1)));

        return $releases === [] ? null : $releases[count($releases) - 1];
    }
}
