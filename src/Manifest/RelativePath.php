<?php

declare(strict_types=1);

namespace SmartA\Jxt\Manifest;

/**
 * Normalizes paths from the manifest and rejects unsafe paths.
 *
 * Since `jxt sync` deletes files on the server, paths must neither be absolute
 * nor lead out of the project or target directory via `..`.
 */
final class RelativePath
{
    /**
     * Returns the path with `/` as separator, without empty segments, `.` segments and leading or trailing slashes.
     *
     * @throws ManifestException for empty, absolute or upward-leading paths
     */
    public static function normalize(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));

        if (str_starts_with($path, '/') || preg_match('#^[A-Za-z]:#', $path) === 1) {
            throw new ManifestException(sprintf('Absoluter Pfad „%s“ im Manifest ist nicht erlaubt.', $path));
        }

        $segments = array_values(array_filter(
            explode('/', $path),
            static fn (string $segment): bool => $segment !== '' && $segment !== '.',
        ));

        if (in_array('..', $segments, true)) {
            throw new ManifestException(sprintf('Pfad „%s“ im Manifest darf kein „..“ enthalten.', $path));
        }

        if ($segments === []) {
            throw new ManifestException('Leerer Pfad im Manifest.');
        }

        return implode('/', $segments);
    }
}
