<?php

/**
 * @copyright  (C) 2026 Carsten Volkwein
 * @license    MIT License; see LICENSE
 */

declare(strict_types=1);

/**
 * Loads Joomla's autoloader and the path constants for the analysis of the bridge plugin.
 */
$joomlaRoot = require __DIR__ . '/phpstan.joomla-root.php';

if (is_string($joomlaRoot)) {
    defined('_JEXEC') || define('_JEXEC', 1);
    defined('JPATH_ROOT') || define('JPATH_ROOT', $joomlaRoot);
    defined('JPATH_BASE') || define('JPATH_BASE', $joomlaRoot);
    defined('JPATH_ADMINISTRATOR') || define('JPATH_ADMINISTRATOR', $joomlaRoot . '/administrator');
    defined('JPATH_LIBRARIES') || define('JPATH_LIBRARIES', $joomlaRoot . '/libraries');
    defined('JPATH_PLUGINS') || define('JPATH_PLUGINS', $joomlaRoot . '/plugins');
    defined('JPATH_CACHE') || define('JPATH_CACHE', JPATH_ADMINISTRATOR . '/cache');
    require_once $joomlaRoot . '/libraries/vendor/autoload.php';
    spl_autoload_register(static function (string $class): void {
        $prefix = 'SmartA\\Plugin\\Console\\Jxt\\';
        $file = __DIR__ . '/plugin/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';

        if (str_starts_with($class, $prefix) && is_file($file)) {
            require_once $file;
        }
    });
}
