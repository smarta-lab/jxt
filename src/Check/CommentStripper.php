<?php

/**
 * @copyright  (C) 2026 Carsten Volkwein
 * @license    MIT License; see LICENSE
 */

declare(strict_types=1);

namespace SmartA\Jxt\Check;

/**
 * Removes comments from PHP, XML, JS and SQL files, so that commented-out code does not count as used.
 *
 * PHP is tokenized exactly; HTML comments in PHP templates stay, since PHP inside them still runs. For JS and
 * SQL, a regular expression skips string literals and removes comments outside of them; it does not know
 * JS regular expression literals, so a quote in one may leave a comment in place.
 */
final readonly class CommentStripper
{
    private const string JS_PATTERN = '~("(?:\\\\.|[^"\\\\\n]++)*+"|\'(?:\\\\.|[^\'\\\\\n]++)*+\'|`(?:\\\\.|[^`\\\\]++)*+`)'
        . '|//[^\n]*+|/\*.*?\*/~s';

    private const string SQL_PATTERN = '~(\'(?:\\\\.|\'\'|[^\'\\\\]++)*+\'|"(?:\\\\.|[^"\\\\]++)*+"|`[^`]*+`)'
        . '|(?:--(?=\s)|#)[^\n]*+|/\*.*?\*/~s';

    /**
     * Returns the content without comments; files of other types are returned unchanged.
     *
     * @param string $path File name or path, only its extension matters
     */
    public function strip(string $path, string $content): string
    {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'php' => $this->stripPhp($content),
            'xml' => preg_replace('/<!--.*?-->/s', ' ', $content) ?? $content,
            'js' => $this->stripWithPattern(self::JS_PATTERN, $content),
            'sql' => $this->stripWithPattern(self::SQL_PATTERN, $content),
            default => $content,
        };
    }

    /**
     * Removes the comment tokens of a PHP file.
     */
    private function stripPhp(string $content): string
    {
        $code = '';

        foreach (token_get_all($content) as $token) {
            if (!is_array($token)) {
                $code .= $token;
            } elseif ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                $code .= ' ';
            } else {
                $code .= $token[1];
            }
        }

        return $code;
    }

    /**
     * Replaces every match of the pattern by a space, unless it is a string literal (first group).
     */
    private function stripWithPattern(string $pattern, string $content): string
    {
        return preg_replace_callback(
            $pattern,
            static fn (array $match): string => isset($match[1]) && $match[1] !== '' ? $match[1] : ' ',
            $content,
        ) ?? $content;
    }
}
