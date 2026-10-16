<?php

declare(strict_types=1);

namespace SmartA\Jxt\Check\Checker;

use SmartA\Jxt\Check\CheckContext;
use SmartA\Jxt\Check\CheckMode;
use SmartA\Jxt\Check\CheckName;
use SmartA\Jxt\Check\CheckReport;
use SmartA\Jxt\Check\GitRepository;
use SmartA\Jxt\Check\Severity;
use SmartA\Jxt\Deployment\PlanEntry;
use SmartA\Jxt\Manifest\ExtensionType;

/**
 * Checks the `joomla.asset.json` files of the extension.
 *
 * The `version` of an asset serves for cache busting, so it must change whenever its file changes.
 * Each asset with a fixed version is compared with the last release tag: a changed file with an
 * unchanged version is reported. In the final check, versions that used to equal the extension
 * version but no longer do are pointed out as well.
 *
 * Templates like Cassiopeia declare `user.css` and `user.js` without shipping them, so that site owners
 * can create them; for templates, these missing files are only notices.
 */
final readonly class AssetChecker implements Checker
{
    private const string FILE_NAME = 'joomla.asset.json';

    private const array TYPE_FOLDERS = ['style' => 'css', 'script' => 'js'];

    private const array USER_FILES = ['user.css', 'user.js', 'user.min.css', 'user.min.js'];

    #[\Override]
    public function group(): string
    {
        return 'assets';
    }

    #[\Override]
    public function check(CheckContext $context, CheckReport $report): void
    {
        $assetFiles = array_values(array_filter(
            $context->files,
            static fn (PlanEntry $entry): bool => basename($entry->source) === self::FILE_NAME,
        ));

        if ($assetFiles === []) {
            return;
        }

        $baseline = $context->git !== null ? $context->state->baselineTag : null;

        if ($baseline === null) {
            $report->add(CheckName::AssetsBaseline, Severity::Notice, sprintf(
                'asset versions not compared: %s',
                $context->git === null ? 'no Git repository' : 'no release tag yet',
            ));
        }

        foreach ($assetFiles as $assetFile) {
            $this->checkFile($context, $report, $assetFile, $baseline);
        }
    }

    /**
     * Checks one `joomla.asset.json`.
     */
    private function checkFile(CheckContext $context, CheckReport $report, PlanEntry $assetFile, ?string $baseline): void
    {
        $path = $context->projectPath($assetFile->source);
        $data = json_decode((string) file_get_contents($context->absolutePath($assetFile->source)), true);

        if (!is_array($data)) {
            $report->add(CheckName::AssetsJson, Severity::Error, sprintf('%s: invalid JSON (%s)', $path, json_last_error_msg()));

            return;
        }

        $assets = $this->assets($data);
        $sources = [];

        foreach ($assets as $name => $asset) {
            $source = $this->source($context, $asset);

            if ($source === false && $this->isUserFile($context, $asset)) {
                $report->add(CheckName::AssetsUri, Severity::Notice, sprintf(
                    '%s: asset %s: %s is not shipped, site owners may create it',
                    $path,
                    $name,
                    basename($this->string($asset, 'uri')),
                ));
            } elseif ($source === false) {
                $report->add(CheckName::AssetsUri, Severity::Warning, sprintf(
                    '%s: asset %s: URI %s does not match a file of the extension',
                    $path,
                    $name,
                    $this->string($asset, 'uri'),
                ));
            } elseif ($source !== null) {
                $sources[$name] = $source;
            }
        }

        if ($baseline !== null && $context->git !== null) {
            $previous = json_decode((string) $context->git->show($baseline, $path), true);
            $previous = is_array($previous) ? $previous : [];
            $this->checkVersions($context, $report, $path, $assets, $sources, $this->assets($previous), $context->git, $baseline);
            $this->checkCoupledVersions($context, $report, $path, $data, $previous, $assets, $this->assets($previous));
        }
    }

    /**
     * Reports assets whose file changed since the release tag while their version did not.
     *
     * @param array<string, array<mixed>> $assets         Current assets by name
     * @param array<string, string>       $sources        Source paths of the assets with a file
     * @param array<string, array<mixed>> $previousAssets Assets at the release tag by name
     */
    private function checkVersions(
        CheckContext $context,
        CheckReport $report,
        string $path,
        array $assets,
        array $sources,
        array $previousAssets,
        GitRepository $git,
        string $baseline,
    ): void {
        foreach ($sources as $name => $source) {
            $version = $this->string($assets[$name], 'version');
            $file = $context->projectPath($source);

            if (
                in_array($version, ['', 'auto'], true)
                || !isset($previousAssets[$name])
                || $this->string($previousAssets[$name], 'version') !== $version
                || !$git->hasChanged($baseline, $file)
            ) {
                continue;
            }

            if ($context->state->mode === CheckMode::PreCheck) {
                $report->add(CheckName::AssetsVersionChanged, Severity::Notice, sprintf(
                    '%s: asset %s: %s changed since %s, raise its version %s with the release',
                    $path,
                    $name,
                    $file,
                    $baseline,
                    $version,
                ));
            } else {
                $report->add(CheckName::AssetsVersionChanged, $context->releaseSeverity(), sprintf(
                    '%s: asset %s: %s changed since %s, but its version is still %s',
                    $path,
                    $name,
                    $file,
                    $baseline,
                    $version,
                ));
            }
        }
    }

    /**
     * In the final check, points out versions that equalled the extension version at the release tag but
     * do not equal the new one.
     *
     * @param array<mixed>                $data           Current content of the file
     * @param array<mixed>                $previous       Content at the release tag
     * @param array<string, array<mixed>> $assets         Current assets by name
     * @param array<string, array<mixed>> $previousAssets Assets at the release tag by name
     */
    private function checkCoupledVersions(
        CheckContext $context,
        CheckReport $report,
        string $path,
        array $data,
        array $previous,
        array $assets,
        array $previousAssets,
    ): void {
        $previousVersion = $context->state->baselineVersion;
        $version = $context->manifest->version;

        if ($context->state->mode !== CheckMode::FinalCheck || $previousVersion === null || $version === null) {
            return;
        }

        $pairs = ['top-level "version"' => [$this->string($data, 'version'), $this->string($previous, 'version')]];

        foreach ($assets as $name => $asset) {
            if (isset($previousAssets[$name])) {
                $pairs['asset ' . $name] = [$this->string($asset, 'version'), $this->string($previousAssets[$name], 'version')];
            }
        }

        foreach ($pairs as $label => [$current, $old]) {
            if ($old === $previousVersion && $current !== $version) {
                $report->add(CheckName::AssetsVersionCoupled, Severity::Notice, sprintf(
                    '%s: %s is %s; it was the extension version %s at the last release, should it be %s?',
                    $path,
                    $label,
                    $current === '' ? 'missing' : $current,
                    $previousVersion,
                    $version,
                ));
            }
        }
    }

    /**
     * Returns the source path of the asset's file, null if it has none to check, false if it cannot be found.
     *
     * Assets without URI, with an external URI or with a URI outside the extension's media are skipped.
     *
     * @param array<mixed> $asset
     */
    private function source(CheckContext $context, array $asset): string|false|null
    {
        $uri = $this->string($asset, 'uri');
        $typeFolder = self::TYPE_FOLDERS[$this->string($asset, 'type')] ?? null;

        if ($uri === '' || $typeFolder === null || str_contains($uri, '://') || str_starts_with($uri, '//')) {
            return null;
        }

        $candidates = array_values(array_filter(
            $this->candidates($uri, $typeFolder, $context->manifest->plan->exclusiveDirectories),
            static fn (string $target): bool => array_any(
                $context->manifest->plan->exclusiveDirectories,
                static fn (string $directory): bool => str_starts_with($target, $directory . '/'),
            ),
        ));

        if ($candidates === []) {
            return null;
        }

        foreach ($context->files as $entry) {
            if (in_array($entry->target, $candidates, true)) {
                return $entry->source;
            }
        }

        return false;
    }

    /**
     * Tells whether the asset is a template's file for the site owner's own CSS or JavaScript.
     *
     * @param array<mixed> $asset
     */
    private function isUserFile(CheckContext $context, array $asset): bool
    {
        return $context->manifest->type === ExtensionType::Template
            && in_array(basename($this->string($asset, 'uri')), self::USER_FILES, true);
    }

    /**
     * Returns the possible targets of a URI relative to the Joomla root, as resolved by Joomla.
     *
     * `media/...` is a path from the root, `extension/file.js` is `media/extension/js/file.js`, and a
     * plain file name refers to the extension's own media directories. Each target also counts in its
     * minified or unminified variant.
     *
     * @param list<string> $directories Exclusive directories of the extension
     *
     * @return list<string>
     */
    private function candidates(string $uri, string $typeFolder, array $directories): array
    {
        if (str_starts_with($uri, 'media/')) {
            $targets = [$uri];
        } elseif (str_contains($uri, '/')) {
            [$extension, $rest] = explode('/', $uri, 2);
            $targets = ['media/' . $extension . '/' . $typeFolder . '/' . $rest, 'media/' . $extension . '/' . $rest];
        } else {
            $targets = array_map(
                static fn (string $directory): string => $directory . '/' . $typeFolder . '/' . $uri,
                array_values(array_filter($directories, static fn (string $directory): bool => str_starts_with($directory, 'media/'))),
            );
        }

        $variants = array_map(
            static fn (string $target): string => str_contains($target, '.min.')
                ? str_replace('.min.', '.', $target)
                : (string) preg_replace('/\.(\w+)$/', '.min.$1', $target),
            $targets,
        );

        return [...$targets, ...$variants];
    }

    /**
     * Returns the assets of a file by name.
     *
     * @param array<mixed> $data
     *
     * @return array<string, array<mixed>>
     */
    private function assets(array $data): array
    {
        $assets = [];

        foreach (is_array($data['assets'] ?? null) ? $data['assets'] : [] as $asset) {
            if (is_array($asset) && is_string($asset['name'] ?? null)) {
                $assets[$asset['name']] = $asset;
            }
        }

        return $assets;
    }

    /**
     * Returns a string value of a JSON object, or an empty string.
     *
     * @param array<mixed> $data
     */
    private function string(array $data, string $key): string
    {
        return is_string($data[$key] ?? null) ? $data[$key] : '';
    }
}
