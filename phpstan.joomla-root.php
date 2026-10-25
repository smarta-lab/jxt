<?php

/**
 * @copyright  (C) 2026 Carsten Volkwein
 * @license    MIT License; see LICENSE
 */

declare(strict_types=1);

/**
 * Returns `joomla_root` of the test site from the global jxt configuration if it contains Joomla's autoloader,
 * otherwise null; the site is the one of `JXT_SITE`, otherwise of `default_site`, as for jxt itself.
 */
$home = getenv('HOME');
$file = (is_string($home) ? $home : '') . '/.config/jxt/config.json';
$configuration = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
$sites = is_array($configuration) && is_array($configuration['sites'] ?? null) ? $configuration['sites'] : [];
$siteId = getenv('JXT_SITE');
$siteId = is_string($siteId) && $siteId !== '' ? $siteId : (is_array($configuration) ? $configuration['default_site'] ?? null : null);
$joomlaRoot = null;

foreach ($sites as $site) {
    if (is_string($siteId) && is_array($site) && ($site['id'] ?? null) === $siteId && is_string($site['joomla_root'] ?? null)) {
        $joomlaRoot = $site['joomla_root'];
    }
}

return $joomlaRoot !== null && is_file($joomlaRoot . '/libraries/vendor/autoload.php') ? $joomlaRoot : null;
