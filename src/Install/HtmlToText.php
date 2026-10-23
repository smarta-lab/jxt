<?php

/**
 * @copyright  (C) 2026 Carsten Volkwein
 * @license    MIT License; see LICENSE
 */

declare(strict_types=1);

namespace SmartA\Jxt\Install;

use Dom\Element;
use Dom\HTMLDocument;
use Dom\Node;
use Dom\Text;

/**
 * Converts HTML into plain text for the console, e.g. the messages that install scripts echo for the backend.
 *
 * Block elements start a new line, paragraphs, headings, lists and tables are set off by blank lines, and lines
 * are wrapped at the width. Headings are underlined (`=` for `h1`, `-` otherwise), list items get `- ` or their
 * number, table cells are separated by ` | `, and absolute links with text show their URL in parentheses. Scripts, styles
 * and images are dropped. The HTML5 parser of PHP repairs broken markup and ignores unknown elements.
 */
final readonly class HtmlToText
{
    private const array SKIPPED = ['head', 'iframe', 'img', 'noscript', 'object', 'script', 'style', 'svg', 'template', 'title'];

    private const array SEPARATED = ['address', 'details', 'dl', 'fieldset', 'figure', 'p', 'table'];

    private const array BLOCKS = [
        'article', 'aside', 'caption', 'center', 'dialog', 'div', 'dt', 'figcaption', 'footer', 'form', 'header',
        'legend', 'li', 'main', 'nav', 'section', 'summary', 'tr',
    ];

    private const array HEADINGS = ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'];

    /**
     * @param int $width Maximum line length; words longer than that, e.g. URLs, stay unbroken
     */
    public function __construct(private int $width = 80)
    {
    }

    /**
     * Returns the text of the HTML without leading and trailing blank lines.
     */
    public function convert(string $html): string
    {
        $document = HTMLDocument::createFromString($html, LIBXML_NOERROR, 'UTF-8');
        $layout = new TextLayout($this->width);

        if ($document->body !== null) {
            $this->children($document->body, $layout, 0);
        }

        return $layout->render();
    }

    /**
     * Adds the child nodes of the node to the layout.
     *
     * @param int $listDepth Number of enclosing lists
     */
    private function children(Node $node, TextLayout $layout, int $listDepth): void
    {
        foreach ($node->childNodes as $child) {
            if ($child instanceof Text) {
                $layout->text($child->data);
            } elseif ($child instanceof Element) {
                $this->element($child, $layout, $listDepth);
            }
        }
    }

    /**
     * Adds the element to the layout according to its kind.
     *
     * @param int $listDepth Number of enclosing lists
     */
    private function element(Element $element, TextLayout $layout, int $listDepth): void
    {
        $name = $element->localName;

        if (in_array($name, self::SKIPPED, true)) {
            return;
        }

        if ($name === 'br') {
            $layout->lineBreak();
        } elseif ($name === 'hr') {
            $layout->rule();
        } elseif ($name === 'ul' || $name === 'ol') {
            $this->listItems($element, $layout, $listDepth);
        } elseif ($name === 'a') {
            $this->link($element, $layout, $listDepth);
        } elseif ($name === 'td' || $name === 'th') {
            $this->cell($element, $layout, $listDepth);
        } elseif (in_array($name, self::HEADINGS, true)) {
            $this->block($element, $layout, $listDepth, TextLayout::BLANK_LINE, underline: $name === 'h1' ? '=' : '-');
        } elseif ($name === 'pre') {
            $this->block($element, $layout, $listDepth, TextLayout::BLANK_LINE, preformatted: true);
        } elseif ($name === 'blockquote') {
            $this->block($element, $layout, $listDepth, TextLayout::BLANK_LINE, indent: 2);
        } elseif ($name === 'dd') {
            $this->block($element, $layout, $listDepth, TextLayout::LINE, indent: 2);
        } elseif (in_array($name, self::SEPARATED, true)) {
            $this->block($element, $layout, $listDepth, TextLayout::BLANK_LINE);
        } elseif (in_array($name, self::BLOCKS, true)) {
            $this->block($element, $layout, $listDepth, TextLayout::LINE);
        } else {
            $this->children($element, $layout, $listDepth);
        }
    }

    /**
     * Adds the element as a block with the gap before and after it.
     *
     * @param int $listDepth Number of enclosing lists
     */
    private function block(
        Element $element,
        TextLayout $layout,
        int $listDepth,
        int $gap,
        int $indent = 0,
        ?string $underline = null,
        bool $preformatted = false,
    ): void {
        $layout->open($gap, $indent, underline: $underline, preformatted: $preformatted);
        $this->children($element, $layout, $listDepth);
        $layout->close($gap);
    }

    /**
     * Adds the items of a `ul` or `ol` list with bullet or number; nested lists are not set off by blank lines.
     *
     * @param int $listDepth Number of enclosing lists
     */
    private function listItems(Element $list, TextLayout $layout, int $listDepth): void
    {
        $gap = $listDepth === 0 ? TextLayout::BLANK_LINE : TextLayout::LINE;
        $number = $list->localName === 'ol' ? (int) ($list->getAttribute('start') ?? 1) : null;
        $layout->open($gap);

        foreach ($list->childNodes as $item) {
            if (!$item instanceof Element || $item->localName !== 'li') {
                continue;
            }

            $layout->open(TextLayout::LINE, prefix: $number === null ? '- ' : $number++ . '. ');
            $this->children($item, $layout, $listDepth + 1);
            $layout->close(TextLayout::LINE);
        }

        $layout->close($gap);
    }

    /**
     * Adds the text of the link and its URL, if it is absolute and differs from the text; a link without text,
     * e.g. around an image, is dropped like the image.
     *
     * @param int $listDepth Number of enclosing lists
     */
    private function link(Element $link, TextLayout $layout, int $listDepth): void
    {
        $this->children($link, $layout, $listDepth);
        $url = trim($link->getAttribute('href') ?? '');
        $text = trim($link->textContent ?? '');

        if ($text !== '' && $text !== $url && preg_match('~^https?://~i', $url) === 1) {
            $layout->text(' (' . $url . ')');
        }
    }

    /**
     * Adds a table cell, separated from the previous cell of the row.
     *
     * @param int $listDepth Number of enclosing lists
     */
    private function cell(Element $cell, TextLayout $layout, int $listDepth): void
    {
        if ($cell->previousElementSibling !== null) {
            $layout->text(' | ');
        }

        $this->children($cell, $layout, $listDepth);
    }
}
