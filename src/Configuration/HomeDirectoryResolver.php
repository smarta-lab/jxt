<?php

declare(strict_types=1);

namespace SmartA\Jxt\Configuration;

use Closure;

/**
 * Determines the home directory of the calling user.
 *
 * Under `sudo`, `HOME` points to `/root`. If the process runs as root and `SUDO_USER` is set,
 * the home directory of that user is used instead.
 */
final readonly class HomeDirectoryResolver
{
    /**
     * @param array<string, string>        $environment     Environment variables
     * @param int                          $effectiveUserId Effective user ID of the process
     * @param Closure(string): ?string     $homeOfUser      Returns the home directory of a user
     */
    public function __construct(
        private array $environment,
        private int $effectiveUserId,
        private Closure $homeOfUser,
    ) {
    }

    /**
     * Creates a resolver for the current process.
     */
    public static function fromSystem(): self
    {
        return new self(
            getenv(),
            posix_geteuid(),
            static function (string $user): ?string {
                $entry = posix_getpwnam($user);

                return $entry === false ? null : $entry['dir'];
            },
        );
    }

    /**
     * Returns the home directory.
     *
     * @throws ConfigurationException if it cannot be determined
     */
    public function resolve(): string
    {
        $sudoUser = $this->environment['SUDO_USER'] ?? '';

        if ($this->effectiveUserId === 0 && $sudoUser !== '' && $sudoUser !== 'root') {
            return ($this->homeOfUser)($sudoUser)
                ?? throw new ConfigurationException(sprintf('Home directory of "%s" not found.', $sudoUser));
        }

        $home = $this->environment['HOME'] ?? '';

        return $home !== '' ? $home : throw new ConfigurationException('Cannot determine the home directory (HOME is not set).');
    }
}
