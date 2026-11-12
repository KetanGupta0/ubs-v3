<?php

namespace App\Services\Legacy;

use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * The old site's page HTML, turned into the Markdown a v3 page is written in.
 *
 * Only what Markdown can say survives: headings, paragraphs, lists, quotes,
 * emphasis and links to https or to a path on this site. Everything else,
 * scripts, styles, inline handlers, iframes and forms included, is dropped
 * with its contents, because a page is reviewed by a person before it is
 * published and a person cannot review what a script will do.
 */
class HtmlToMarkdown
{
    protected const DROPPED = ['script', 'style', 'iframe', 'object', 'embed', 'form', 'input', 'button', 'select', 'textarea', 'noscript', 'svg', 'head'];

    public static function convert(?string $html): string
    {
        if (blank($html)) {
            return '';
        }

        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8"><body>'.$html.'</body>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $body = $document->getElementsByTagName('body')->item(0);
        $markdown = $body ? (new self)->children($body) : '';

        return trim(preg_replace("/\n{3,}/", "\n\n", $markdown));
    }

    protected function children(DOMNode $node): string
    {
        $out = '';

        foreach ($node->childNodes as $child) {
            $out .= $this->node($child);
        }

        return $out;
    }

    protected function node(DOMNode $node): string
    {
        if ($node->nodeType === XML_TEXT_NODE) {
            return preg_replace('/\s+/', ' ', $node->textContent);
        }

        if (! $node instanceof DOMElement) {
            return '';
        }

        $tag = strtolower($node->tagName);

        if (in_array($tag, self::DROPPED, true)) {
            return '';
        }

        $inner = fn () => trim($this->children($node));

        return match ($tag) {
            'h1', 'h2' => "\n\n## ".$inner()."\n\n",
            'h3', 'h4', 'h5', 'h6' => "\n\n### ".$inner()."\n\n",
            'p', 'div', 'section', 'article', 'header', 'footer', 'main' => "\n\n".$inner()."\n\n",
            'br' => "  \n",
            'strong', 'b' => ($text = $inner()) !== '' ? "**{$text}**" : '',
            'em', 'i' => ($text = $inner()) !== '' ? "*{$text}*" : '',
            'blockquote' => "\n\n".collect(explode("\n", $inner()))->map(fn ($line) => '> '.$line)->implode("\n")."\n\n",
            'ul' => "\n\n".$this->items($node, false)."\n\n",
            'ol' => "\n\n".$this->items($node, true)."\n\n",
            'a' => $this->link($node, $inner()),
            'hr' => "\n\n---\n\n",
            'img' => '',
            default => $this->children($node),
        };
    }

    protected function items(DOMElement $list, bool $numbered): string
    {
        $lines = [];
        $n = 1;

        foreach ($list->childNodes as $child) {
            if ($child instanceof DOMElement && strtolower($child->tagName) === 'li') {
                $lines[] = ($numbered ? ($n++).'. ' : '- ').trim(preg_replace('/\s+/', ' ', $this->children($child)));
            }
        }

        return implode("\n", $lines);
    }

    /** A link only where it goes somewhere safe; otherwise just its words. */
    protected function link(DOMElement $node, string $text): string
    {
        $href = trim($node->getAttribute('href'));

        if ($text === '' || ! preg_match('#^(https://|/(?!/))#i', $href)) {
            return $text;
        }

        return '['.str_replace([']', '['], '', $text).']('.str_replace([' ', ')'], ['%20', '%29'], $href).')';
    }
}
