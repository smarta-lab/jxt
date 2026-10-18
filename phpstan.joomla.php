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
 */
$joomlaRoot = require __DIR__ . '/phpstan.joomla-root.php';

return $joomlaRoot === null ? [] : [
    'parameters' => [
        'paths' => [
            __DIR__ . '/plugin/script.php',
            __DIR__ . '/plugin/services',
            __DIR__ . '/plugin/src/Console',
            __DIR__ . '/plugin/src/Extension',
        ],
        'bootstrapFiles' => [__DIR__ . '/phpstan.joomla-bootstrap.php'],
    ],
];
