<?php

declare(strict_types=1);

namespace SmartA\Jxt\Check;

/**
 * Release state of the manifest version as determined by the ReleaseDetector.
 */
final readonly class ReleaseState
{
    /**
     * @param string|null $baselineTag     Tag of the last release to compare assets with; null without Git or
     *                                     before the first release
     * @param string|null $baselineVersion Version of that release
     * @param string      $reason          Explanation of the mode for the output
     */
    public function __construct(
        public CheckMode $mode,
        public ?string $baselineTag,
        public ?string $baselineVersion,
        public string $reason,
    ) {
    }
}
