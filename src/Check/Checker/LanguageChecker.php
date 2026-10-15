<?php

declare(strict_types=1);

namespace SmartA\Jxt\Check\Checker;

use SmartA\Jxt\Check\CheckContext;
use SmartA\Jxt\Check\CheckName;
use SmartA\Jxt\Check\CheckReport;
use SmartA\Jxt\Check\Severity;
use SmartA\Jxt\Deployment\PlanEntry;
use SmartA\Jxt\Manifest\ExtensionType;
use SmartA\Jxt\PhpErrors;

/**
 * Checks the language files of the extension.
 *
 * Every language must have the same files with the same keys. Language constants with the prefix of the
 * extension (`MOD_X_`, `TPL_X_`) used in PHP, XML or JS files must be defined. Defined constants not
 * found in the code are only a notice, as constants may be built at runtime; constants that Joomla uses
 * itself (the extension name, template positions and layouts) are never reported.
 */
final readonly class LanguageChecker implements Checker
{
    private const string LANGUAGE_FILE_PATTERN = '#(?:^|/)([a-z]{2,3}-[A-Z]{2})/([^/]+\.ini)$#';

    #[\Override]
    public function group(): string
    {
        return 'language';
    }

    #[\Override]
    public function check(CheckContext $context, CheckReport $report): void
    {
        $keys = $this->readLanguageFiles($context, $report);

        if ($keys === []) {
            return;
        }

        $this->compareLanguages($keys, $report);

        $prefix = $this->prefix($context);
        $defined = array_unique(array_merge(...array_merge(...array_map(array_values(...), array_values($keys)))));
        $used = $this->usedConstants($context, $prefix);

        foreach ($used as $constant => $files) {
            if (!in_array($constant, $defined, true)) {
                $report->add(CheckName::LanguageUndefined, Severity::Error, sprintf(
                    '%s is used in %s but not defined',
                    $constant,
                    implode(', ', $files),
                ));
            }
        }

        $implicit = '/^' . preg_quote(rtrim($prefix, '_'), '/') . '(_POSITION_.+|_LAYOUT_.+)?$/';

        foreach ($defined as $constant) {
            if (!isset($used[$constant]) && preg_match($implicit, $constant) !== 1) {
                $report->add(CheckName::LanguageUnused, Severity::Notice, sprintf(
                    '%s is defined but not found in the code (may be built at runtime)',
                    $constant,
                ));
            }
        }
    }

    /**
     * Reads the keys of all language files of the plan.
     *
     * @return array<string, array<string, list<string>>> Keys by language tag and file name
     */
    private function readLanguageFiles(CheckContext $context, CheckReport $report): array
    {
        $keys = [];

        foreach ($context->files as $entry) {
            if (preg_match(self::LANGUAGE_FILE_PATTERN, $entry->target, $match) !== 1) {
                continue;
            }

            [$parsed, $error] = PhpErrors::capture(
                static fn (): array|false => parse_ini_file($context->absolutePath($entry->source), false, INI_SCANNER_RAW),
            );

            if ($parsed === false) {
                $report->add(CheckName::LanguageParse, Severity::Error, sprintf(
                    '%s cannot be parsed%s',
                    $context->projectPath($entry->source),
                    $error === null ? '' : ': ' . $error,
                ));

                continue;
            }

            $keys[$match[1]][$match[2]] = array_map(strval(...), array_keys($parsed));
        }

        ksort($keys);

        return $keys;
    }

    /**
     * Reports files and keys that are missing in one of the languages.
     *
     * @param array<string, array<string, list<string>>> $keys Keys by language tag and file name
     */
    private function compareLanguages(array $keys, CheckReport $report): void
    {
        $fileNames = array_unique(array_merge(...array_map(array_keys(...), array_values($keys))));
        sort($fileNames);

        foreach ($fileNames as $fileName) {
            $all = array_unique(array_merge(...array_map(
                static fn (array $files): array => $files[$fileName] ?? [],
                array_values($keys),
            )));

            foreach ($keys as $tag => $files) {
                if (!isset($files[$fileName])) {
                    $report->add(CheckName::LanguageFiles, Severity::Error, sprintf('%s/%s is missing', $tag, $fileName));

                    continue;
                }

                $missing = array_diff($all, $files[$fileName]);

                if ($missing !== []) {
                    $report->add(CheckName::LanguageKeys, Severity::Error, sprintf(
                        '%s/%s lacks %s',
                        $tag,
                        $fileName,
                        implode(', ', $missing),
                    ));
                }
            }
        }
    }

    /**
     * Returns the language constants with the prefix found in the PHP, XML and JS files of the plan.
     *
     * @return array<string, list<string>> Files (relative to the project) by constant
     */
    private function usedConstants(CheckContext $context, string $prefix): array
    {
        $pattern = '/\b' . preg_quote($prefix, '/') . '[A-Z0-9_]*[A-Z0-9]\b/';
        $used = [];

        foreach ($context->files as $entry) {
            if (preg_match('/\.(php|xml|js)$/', $entry->source) !== 1) {
                continue;
            }

            preg_match_all($pattern, (string) file_get_contents($context->absolutePath($entry->source)), $matches);

            foreach (array_unique($matches[0]) as $constant) {
                $used[$constant][] = $context->projectPath($entry->source);
            }
        }

        return $used;
    }

    /**
     * Returns the prefix of the extension's language constants, e.g. `MOD_EXAMPLE_` or `TPL_CASSIOPEIA_`.
     */
    private function prefix(CheckContext $context): string
    {
        $element = strtoupper($context->manifest->element);

        return match ($context->manifest->type) {
            ExtensionType::Module => $element . '_',
            ExtensionType::Template => 'TPL_' . $element . '_',
        };
    }
}
