<?php

/**
 * @copyright  (C) 2026 Carsten Volkwein
 * @license    MIT License; see LICENSE
 */

declare(strict_types=1);

namespace SmartA\Jxt\Check\Checker;

use SmartA\Jxt\Check\CheckContext;
use SmartA\Jxt\Check\CheckName;
use SmartA\Jxt\Check\CheckReport;
use SmartA\Jxt\Check\CommentStripper;
use SmartA\Jxt\Check\Severity;
use SmartA\Jxt\Manifest\ExtensionType;
use SmartA\Jxt\PhpErrors;

/**
 * Checks the language files of the extension.
 *
 * Every language must have the same files with the same keys, compared per target directory (`language/`,
 * `administrator/language/`); as in Joomla, the language tag in front of a file name (`de-DE.mod_x.ini`) is
 * optional. Language constants with the prefix of the
 * extension (`MOD_X_`, `TPL_X_`, `PLG_GROUP_X_`, `COM_X_`) used in PHP, XML, JS or SQL files outside of
 * comments must be defined. Defined constants not found in the code are only a notice, as constants may be
 * built at runtime; keys without the prefix are searched literally, and constants that Joomla uses itself
 * (the extension name, template positions and layouts, including the layout overrides of templates) are never
 * reported.
 */
final readonly class LanguageChecker implements Checker
{
    private const string LANGUAGE_FILE_PATTERN = '#^(.*/)?([a-z]{2,3}-[A-Z]{2})/([^/]+\.ini)$#';

    private const string TAG_PLACEHOLDER = '{tag}';

    #[\Override]
    public function group(): string
    {
        return 'language';
    }

    #[\Override]
    public function check(CheckContext $context, CheckReport $report): void
    {
        [$keys, $actualNames] = $this->readLanguageFiles($context, $report);

        if ($keys === []) {
            return;
        }

        $this->compareLanguages($keys, $actualNames, $report);

        $prefix = $this->prefix($context);
        $defined = array_unique(array_merge(...array_merge(...array_map(array_values(...), array_values($keys)))));
        $code = $this->readCode($context);
        $used = $this->usedConstants($code, $prefix);

        foreach ($used as $constant => $files) {
            if (!in_array($constant, $defined, true)) {
                $report->add(CheckName::LanguageUndefined, Severity::Error, sprintf(
                    '%s is used in %s but not defined',
                    $constant,
                    implode(', ', $files),
                ));
            }
        }

        $implicit = $this->implicitConstants($context, $prefix);

        foreach ($defined as $constant) {
            if (
                !isset($used[$constant])
                && preg_match($implicit, $constant) !== 1
                && (str_starts_with($constant, $prefix) || !$this->containsLiterally($code, $constant))
            ) {
                $report->add(CheckName::LanguageUnused, Severity::Notice, sprintf(
                    '%s is defined but not found in the code (may be built at runtime)',
                    $constant,
                ));
            }
        }
    }

    /**
     * Returns the pattern of the constants that Joomla uses itself: the extension name, template positions and
     * layouts; for templates also the layout overrides of other extensions (`TPL_X_MOD_MENU_LAYOUT_…`,
     * `TPL_X_COM_CONTENT_ARTICLE_LAYOUT_…`).
     */
    private function implicitConstants(CheckContext $context, string $prefix): string
    {
        $layout = $context->manifest->type === ExtensionType::Template ? '(_.+)?_LAYOUT_.+' : '_LAYOUT_.+';

        return '/^' . preg_quote(rtrim($prefix, '_'), '/') . '(_POSITION_.+|' . $layout . ')?$/';
    }

    /**
     * Reads the keys of all language files of the plan.
     *
     * A file is identified by its target path with a placeholder for the language tag and without the tag in
     * front of the file name (`administrator/language/{tag}/com_x.ini`), so that the site and administrator
     * files of a component with the same name stay apart.
     *
     * @return array{array<string, array<string, list<string>>>, array<string, array<string, string>>} Keys and
     *     target paths, both by language tag and file identifier
     */
    private function readLanguageFiles(CheckContext $context, CheckReport $report): array
    {
        $keys = [];
        $actualNames = [];

        foreach ($context->files as $entry) {
            if (preg_match(self::LANGUAGE_FILE_PATTERN, $entry->target, $match, PREG_UNMATCHED_AS_NULL) !== 1) {
                continue;
            }

            [$parsed, $error] = PhpErrors::capture(
                static fn (): array|false => parse_ini_file($context->absolutePath($entry->source), false, INI_SCANNER_RAW),
            );

            if ($parsed === false) {
                $report->add(
                    CheckName::LanguageParse,
                    Severity::Error,
                    'cannot be parsed' . ($error === null ? '' : ': ' . $error),
                    $context->projectPath($entry->source),
                );

                continue;
            }

            [, $directory, $tag, $name] = $match;
            $name = preg_replace('/^' . preg_quote((string) $tag, '/') . '\\./', '', (string) $name) ?? $name;
            $file = $directory . self::TAG_PLACEHOLDER . '/' . $name;
            $keys[(string) $tag][$file] = array_map(strval(...), array_keys($parsed));
            $actualNames[(string) $tag][$file] = $entry->target;
        }

        ksort($keys);

        return [$keys, $actualNames];
    }

    /**
     * Reports files and keys that are missing in one of the languages.
     *
     * @param array<string, array<string, list<string>>> $keys        Keys by language tag and file identifier
     * @param array<string, array<string, string>>       $actualNames Target paths, by the same indexes
     */
    private function compareLanguages(array $keys, array $actualNames, CheckReport $report): void
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
                    $report->add(CheckName::LanguageFiles, Severity::Error, sprintf(
                        '%s is missing',
                        str_replace(self::TAG_PLACEHOLDER, $tag, $fileName),
                    ));

                    continue;
                }

                $missing = array_diff($all, $files[$fileName]);

                if ($missing !== []) {
                    $report->add(CheckName::LanguageKeys, Severity::Error, sprintf(
                        '%s lacks %s',
                        $actualNames[$tag][$fileName],
                        implode(', ', $missing),
                    ));
                }
            }
        }
    }

    /**
     * Reads the PHP, XML, JS and SQL files of the plan without comments.
     *
     * @return array<string, string> Code by file (relative to the project)
     */
    private function readCode(CheckContext $context): array
    {
        $stripper = new CommentStripper();
        $code = [];

        foreach ($context->files as $entry) {
            if (preg_match('/\.(php|xml|js|sql)$/', $entry->source) !== 1) {
                continue;
            }

            $content = (string) file_get_contents($context->absolutePath($entry->source));
            $code[$context->projectPath($entry->source)] = $stripper->strip($entry->source, $content);
        }

        return $code;
    }

    /**
     * Returns the language constants with the prefix found in the code.
     *
     * @param array<string, string> $code Code by file
     *
     * @return array<string, list<string>> Files (relative to the project) by constant
     */
    private function usedConstants(array $code, string $prefix): array
    {
        $pattern = '/\b' . preg_quote($prefix, '/') . '[A-Z0-9_]*[A-Z0-9]\b/';
        $used = [];

        foreach ($code as $file => $content) {
            preg_match_all($pattern, $content, $matches);

            foreach (array_unique($matches[0]) as $constant) {
                $used[$constant][] = $file;
            }
        }

        return $used;
    }

    /**
     * Tells whether the key occurs in the code as a whole word.
     *
     * @param array<string, string> $code Code by file
     */
    private function containsLiterally(array $code, string $key): bool
    {
        $pattern = '/(?<!\w)' . preg_quote($key, '/') . '(?!\w)/';

        foreach ($code as $content) {
            if (preg_match($pattern, $content) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Returns the prefix of the extension's language constants, e.g. `MOD_EXAMPLE_`, `TPL_CASSIOPEIA_` or
     * `PLG_SYSTEM_EXAMPLE_`.
     */
    private function prefix(CheckContext $context): string
    {
        return strtoupper($context->manifest->name()) . '_';
    }
}
