<?php

/**
 * @copyright  (C) 2026 Carsten Volkwein
 * @license    MIT License; see LICENSE
 */

declare(strict_types=1);

/**
 * Returns `joomla_root` from the global jxt configuration if it contains Joomla's autoloader, otherwise null.
 */
$home = getenv('HOME');
$file = (is_string($home) ? $home : '') . '/.config/jxt/config.json';
$configuration = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
$joomlaRoot = is_array($configuration) && is_string($configuration['joomla_root'] ?? null) ? $configuration['joomla_root'] : null;

return $joomlaRoot !== null && is_file($joomlaRoot . '/libraries/vendor/autoload.php') ? $joomlaRoot : null;
