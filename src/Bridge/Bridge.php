<?php

/**
 * @copyright  (C) 2026 Carsten Volkwein
 * @license    MIT License; see LICENSE
 */

declare(strict_types=1);

namespace SmartA\Jxt\Bridge;

use SmartA\Jxt\Build\PackageBuilder;
use SmartA\Jxt\Configuration\Configuration;
use SmartA\Jxt\Install\JoomlaCli;
use SmartA\Jxt\Install\JoomlaInstaller;
use SmartA\Jxt\Install\ProcessResult;
use SmartA\Jxt\Manifest\Manifest;
use SmartA\Jxt\Manifest\ManifestException;
use SmartA\Jxt\Manifest\ManifestParser;
use SmartA\Jxt\Site\TestSite;
use SmartA\Jxt\Sync\SyncPlan;

/**
 * The jxt bridge: a Joomla console plugin shipped in `plugin/` that applies the plans of `jxt sync` as web server
 * user (`jxt:apply`).
 *
 * jxt builds the plugin with its own plugin support and installs it with the Joomla installer. Whether the
 * installed plugin is current is decided by comparing the version in its installed manifest with the bundled
 * manifest, so the plan format only has to match the plugin of the same jxt release.
 */
final readonly class Bridge
{
    public const string INSTALLED_MANIFEST = 'plugins/console/jxt/jxt.xml';
    public const string BUNDLED_DIRECTORY = __DIR__ . '/../../plugin';

    /**
     * @param string      $pluginDirectory    Directory of the bundled plugin
     * @param string|null $temporaryDirectory Directory for the ZIP file of the plugin; null for the system default
     */
    public function __construct(
        private JoomlaCli $cli = new JoomlaCli(),
        private JoomlaInstaller $installer = new JoomlaInstaller(),
        private PlanStager $stager = new PlanStager(),
        private ManifestParser $manifestParser = new ManifestParser(),
        private PackageBuilder $packageBuilder = new PackageBuilder(),
        private string $pluginDirectory = self::BUNDLED_DIRECTORY,
        private ?string $temporaryDirectory = null,
    ) {
    }

    /**
     * Returns the version of the bundled plugin.
     *
     * @throws ManifestException if the bundled manifest is missing or invalid
     */
    public function bundledVersion(): string
    {
        return $this->bundledManifest()->version ?? '';
    }

    /**
     * Returns the version of the plugin installed on the site, null if it is not installed.
     */
    public function installedVersion(string $joomlaRoot): ?string
    {
        $path = $joomlaRoot . '/' . self::INSTALLED_MANIFEST;

        try {
            return is_file($path) ? $this->manifestParser->parseFile($path)->version ?? '' : null;
        } catch (ManifestException) {
            return '';
        }
    }

    /**
     * Builds the bundled plugin and installs it with the Joomla installer.
     *
     * @throws \SmartA\Jxt\JxtException if the plugin cannot be built or the Joomla CLI cannot be started
     */
    public function install(TestSite $site): ProcessResult
    {
        $directory = sprintf('%s/jxt-bridge-%s', $this->temporaryDirectory ?? sys_get_temp_dir(), bin2hex(random_bytes(6)));

        try {
            $package = $this->packageBuilder->build($this->bundledManifest(), $this->pluginDirectory, $directory);

            return $this->installer->install($package->path, $site);
        } finally {
            $this->stager->remove($directory);
        }
    }

    /**
     * Uninstalls the plugin with `extension:remove`, after asking the plugin for its extension ID.
     *
     * @return ProcessResult Result of `extension:remove`, or of `jxt:status` if that failed
     *
     * @throws \SmartA\Jxt\JxtException if the extension ID cannot be determined or the Joomla CLI cannot be started
     */
    public function remove(TestSite $site): ProcessResult
    {
        $status = $this->cli->run($site, ['jxt:status']);

        if ($status->exitCode !== 0) {
            return $status;
        }

        return $this->cli->run($site, ['extension:remove', '-n', (string) $this->extensionId($status)]);
    }

    /**
     * Runs `jxt:status`, which reports the extension ID of the plugin.
     *
     * @throws \SmartA\Jxt\Install\InstallException if the Joomla CLI cannot be started
     */
    public function status(TestSite $site): ProcessResult
    {
        return $this->cli->run($site, ['jxt:status']);
    }

    /**
     * Returns the extension ID from the output of `jxt:status`.
     *
     * @throws BridgeException if the output contains no extension ID
     */
    public function extensionId(ProcessResult $status): int
    {
        $data = preg_match('/^\{.*\}$/m', $status->output, $match) === 1 ? json_decode($match[0], true) : null;
        $id = is_array($data) ? $data['extension_id'] ?? null : null;

        return is_int($id) ? $id : throw new BridgeException('Cannot determine the extension ID of the jxt bridge: ' . trim($status->output));
    }

    /**
     * Stages the plan and applies it with `jxt:apply`; the staging directory is removed afterwards.
     *
     * With the site's `cli_command`, the plan is staged in the site's `tmp/` directory, which the command can see.
     *
     * @throws \SmartA\Jxt\JxtException if the plan cannot be staged or the Joomla CLI cannot be started
     */
    public function apply(
        SyncPlan $plan,
        Manifest $manifest,
        string $extensionDirectory,
        TestSite $site,
        Configuration $configuration,
    ): ProcessResult {
        $directory = $this->stager->stage($plan, $manifest, $extensionDirectory, $configuration, $site->stagingDirectory());

        try {
            return $this->cli->run($site, ['jxt:apply', $site->cliPath($directory)]);
        } finally {
            $this->stager->remove($directory);
        }
    }

    /**
     * Returns the sudoers rules for `jxt install` and the bridge: installing, removing and the plugin's commands.
     *
     * Each command has its own rule, since sudo-rs only accepts `*` as a whole argument, not inside one such as
     * `jxt\:*`.
     *
     * @return list<string>
     */
    public function sudoersRules(string $user, string $joomlaRoot, string $webUser): array
    {
        return [
            $this->installer->sudoersRule($user, $joomlaRoot, $webUser),
            $this->cli->sudoersRule($user, $joomlaRoot, $webUser, 'extension\:remove -n *'),
            $this->cli->sudoersRule($user, $joomlaRoot, $webUser, 'jxt\:apply *'),
            $this->cli->sudoersRule($user, $joomlaRoot, $webUser, 'jxt\:status'),
        ];
    }

    /**
     * Returns the parsed manifest of the bundled plugin.
     *
     * @throws ManifestException if it is missing or invalid
     */
    private function bundledManifest(): Manifest
    {
        return $this->manifestParser->parseFile($this->pluginDirectory . '/jxt.xml');
    }
}
