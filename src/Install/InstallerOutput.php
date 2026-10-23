<?php

/**
 * @copyright  (C) 2026 Carsten Volkwein
 * @license    MIT License; see LICENSE
 */

declare(strict_types=1);

namespace SmartA\Jxt\Install;

/**
 * Prepares the output of the Joomla installer for the console: install scripts echo HTML, which the backend shows
 * as such and the Joomla CLI passes on unchanged; it becomes plain text, the other lines stay as they are.
 *
 * A chunk of HTML starts at a line with a tag of a known HTML element and extends over the following lines as long
 * as they contain such a tag or an element is still open. Other tags, e.g. `<extension>` in a message, do not
 * count; void elements and elements whose end tag may be omitted (`p`, `li`, …) do not keep a chunk open.
 */
final readonly class InstallerOutput
{
    private const array ELEMENTS = [
        'a', 'abbr', 'address', 'article', 'aside', 'b', 'big', 'blockquote', 'body', 'br', 'caption', 'center',
        'cite', 'code', 'dd', 'del', 'details', 'div', 'dl', 'dt', 'em', 'fieldset', 'figcaption', 'figure', 'font',
        'footer', 'form', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'header', 'hr', 'html', 'i', 'img', 'ins', 'kbd',
        'label', 'legend', 'li', 'main', 'mark', 'nav', 'ol', 'p', 'pre', 'q', 's', 'samp', 'script', 'section',
        'small', 'span', 'strike', 'strong', 'style', 'sub', 'summary', 'sup', 'table', 'tbody', 'td', 'tfoot', 'th',
        'thead', 'tr', 'tt', 'u', 'ul', 'var',
    ];

    private const array WITHOUT_END_TAG = ['body', 'br', 'dd', 'dt', 'hr', 'html', 'img', 'li', 'p', 'tbody', 'td', 'tfoot', 'th', 'thead', 'tr'];

    private HtmlToText $htmlToText;

    private string $tagPattern;

    /**
     * @param int $width Maximum line length of converted HTML
     */
    public function __construct(int $width = 80)
    {
        $this->htmlToText = new HtmlToText($width);
        $this->tagPattern = '~<(/?)(' . implode('|', self::ELEMENTS) . ')(?=[\s/>])[^<>]*?(/?)>~i';
    }

    /**
     * Returns the output with HTML converted to text, without leading blank lines and trailing whitespace.
     */
    public function toText(string $output): string
    {
        $result = [];
        $chunk = [];
        $depth = 0;

        foreach (preg_split('/\r\n|\r|\n/', ltrim(rtrim($output), "\r\n")) ?: [] as $line) {
            $balance = $this->balance($line);

            if ($balance === null && $depth === 0) {
                $result = [...$result, ...$this->converted($chunk)];
                $result[] = $line;
                $chunk = [];

                continue;
            }

            $chunk[] = $line;
            $depth = max(0, $depth + ($balance ?? 0));
        }

        return implode("\n", [...$result, ...$this->converted($chunk)]);
    }

    /**
     * Returns the number of elements the line opens minus those it closes, or null if it contains no HTML tag.
     */
    private function balance(string $line): ?int
    {
        if (preg_match_all($this->tagPattern, $line, $matches, PREG_SET_ORDER) === 0) {
            return null;
        }

        $balance = 0;

        foreach ($matches as [, $closing, $name, $selfClosing]) {
            if ($selfClosing === '' && !in_array(strtolower($name), self::WITHOUT_END_TAG, true)) {
                $balance += $closing === '' ? 1 : -1;
            }
        }

        return $balance;
    }

    /**
     * Returns the text of a chunk of HTML lines as lines; nothing if the chunk has no visible text.
     *
     * @param list<string> $chunk
     *
     * @return list<string>
     */
    private function converted(array $chunk): array
    {
        $text = $chunk === [] ? '' : $this->htmlToText->convert(implode("\n", $chunk));

        return $text === '' ? [] : [$text];
    }
}
