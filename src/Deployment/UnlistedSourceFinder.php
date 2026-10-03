<?php

declare(strict_types=1);

namespace SmartA\Jxt\Deployment;

/**
 * Finds files and directories in the manifest's source directories that are not listed in the manifest.
 *
 * Example: `media/svg/` exists in the project, but `<media>` only lists `css`. Such entries
 * would be transferred neither to the ZIP nor to the server. Names starting with `.git` are
 * ignored, as in the PlanExpander.
 */
final class UnlistedSourceFinder
{
    /**
     * Returns the unlisted paths relative to the project directory, directories with a trailing `/`.
     *
     * If a whole directory is unlisted, only the directory is reported, not every file in it.
     *
     * @return list<string>
     */
    public function find(DeploymentPlan $plan, string $projectDirectory): array
    {
        $unlisted = [];

        foreach ($plan->sourceDirectories as $directory) {
            if (is_dir($projectDirectory . '/' . $directory)) {
                $unlisted = [...$unlisted, ...$this->scan($plan, $projectDirectory, $directory)];
            }
        }

        return array_values(array_unique($unlisted));
    }

    /**
     * Recursively searches a directory for unlisted entries.
     *
     * @return list<string>
     */
    private function scan(DeploymentPlan $plan, string $projectDirectory, string $directory): array
    {
        $unlisted = [];

        foreach (scandir($projectDirectory . '/' . $directory) ?: [] as $name) {
            $path = $directory . '/' . $name;

            if ($name === '.' || $name === '..' || str_starts_with($name, '.git') || $this->isCovered($plan, $path)) {
                continue;
            }

            if (!is_dir($projectDirectory . '/' . $path)) {
                $unlisted[] = $path;
            } elseif ($this->containsListedEntries($plan, $path)) {
                $unlisted = [...$unlisted, ...$this->scan($plan, $projectDirectory, $path)];
            } else {
                $unlisted[] = $path . '/';
            }
        }

        return $unlisted;
    }

    /**
     * Checks whether the path itself is in the plan or lies in a listed directory.
     */
    private function isCovered(DeploymentPlan $plan, string $path): bool
    {
        foreach ($plan->entries as $entry) {
            if (
                $entry->source === $path
                || ($entry->kind === EntryKind::Directory && str_starts_with($path, $entry->source . '/'))
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Checks whether listed entries lie below the directory.
     */
    private function containsListedEntries(DeploymentPlan $plan, string $directory): bool
    {
        foreach ($plan->entries as $entry) {
            if (str_starts_with($entry->source, $directory . '/')) {
                return true;
            }
        }

        return false;
    }
}
