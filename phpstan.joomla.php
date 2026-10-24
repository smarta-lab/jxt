<?php

/**
 * @copyright  (C) 2026 Carsten Volkwein
 * @license    MIT License; see LICENSE
 */

declare(strict_types=1);

/**
 * Adds the Joomla-dependent files of the bridge plugin to the analysis if the Joomla site from the global jxt
 * configuration is available; without it (e.g. in CI), only the Joomla-independent part `plugin/src/Plan` is
 * analysed.
 *
 * PHPStan does not notice when the result of this file changes and would keep its cached container, so both cases
 * use a temporary directory of their own.
 */
$joomlaRoot = require __DIR__ . '/phpstan.joomla-root.php';
$tmpDir = sys_get_temp_dir() . '/phpstan-jxt';

return $joomlaRoot === null ? ['parameters' => ['tmpDir' => $tmpDir]] : [
    'parameters' => [
        'tmpDir' => $tmpDir . '-joomla',
        'paths' => [
            __DIR__ . '/plugin/script.php',
            __DIR__ . '/plugin/services',
            __DIR__ . '/plugin/src/Console',
            __DIR__ . '/plugin/src/Extension',
        ],
        'bootstrapFiles' => [__DIR__ . '/phpstan.joomla-bootstrap.php'],
    ],
];
