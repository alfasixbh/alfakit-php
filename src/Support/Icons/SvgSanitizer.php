<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Support\Icons;

use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * Cleans inline SVG icons (`icon(new HtmlString('<svg…>'))`, icon sets) before they reach
 * a renderer, which inserts them as markup. Only drawing elements and presentation
 * attributes survive: no scripts, event handlers, `<foreignObject>`, external references
 * or `url()`s that leave the document.
 */
final class SvgSanitizer
{
    private const array ELEMENTS = [
        'svg', 'g', 'path', 'circle', 'ellipse', 'line', 'polyline', 'polygon', 'rect', 'defs',
        'title', 'desc', 'lineargradient', 'radialgradient', 'stop', 'clippath', 'mask', 'symbol', 'use',
    ];

    private const array ATTRIBUTES = [
        'viewbox', 'xmlns', 'width', 'height', 'fill', 'fill-rule', 'fill-opacity', 'clip-rule', 'clip-path',
        'stroke', 'stroke-width', 'stroke-linecap', 'stroke-linejoin', 'stroke-miterlimit', 'stroke-dasharray',
        'stroke-dashoffset', 'stroke-opacity', 'opacity', 'd', 'cx', 'cy', 'r', 'rx', 'ry', 'x', 'y', 'x1', 'x2',
        'y1', 'y2', 'points', 'transform', 'id', 'class', 'offset', 'stop-color', 'stop-opacity',
        'gradientunits', 'gradienttransform', 'mask', 'maskunits', 'preserveaspectratio', 'aria-hidden',
        'role', 'focusable', 'href', 'xlink:href', 'color', 'vector-effect', 'shape-rendering',
    ];

    /** @var array<string, string|null> */
    private static array $cache = [];

    /**
     * The sanitised `<svg>` markup, or null when the input isn't an SVG document.
     */
    public static function sanitize(string $svg): ?string
    {
        $key = md5($svg);

        if (array_key_exists($key, self::$cache)) {
            return self::$cache[$key];
        }

        if (count(self::$cache) > 500) {
            self::$cache = [];
        }

        return self::$cache[$key] = self::clean($svg);
    }

    private static function clean(string $svg): ?string
    {
        $svg = trim($svg);

        // Drop the XML prolog, doctype and comments: DOCTYPEs can declare entities.
        $svg = (string) preg_replace(['/<\?xml.*?\?>/s', '/<!DOCTYPE[^>]*>/is', '/<!--.*?-->/s'], '', $svg);

        if (stripos($svg, '<svg') === false || stripos($svg, '<!ENTITY') !== false) {
            return null;
        }

        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadXML($svg, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $document->documentElement;

        if (! $loaded || ! $root instanceof DOMElement || strtolower($root->localName ?? '') !== 'svg') {
            return null;
        }

        self::cleanNode($root);

        if (! $root->hasAttribute('xmlns')) {
            $root->setAttribute('xmlns', 'http://www.w3.org/2000/svg');
        }

        $markup = $document->saveXML($root);

        return $markup === false ? null : $markup;
    }

    private static function cleanNode(DOMElement $element): void
    {
        foreach (iterator_to_array($element->attributes ?? []) as $attribute) {
            $name = strtolower($attribute->nodeName);
            $value = trim($attribute->nodeValue ?? '');

            $allowed = in_array($name, self::ATTRIBUTES, true)
                || str_starts_with($name, 'data-')
                || ($name === 'style' && self::isSafeStyle($value));

            if ($allowed && in_array($name, ['href', 'xlink:href'], true)) {
                // Only fragment references inside the icon itself.
                $allowed = preg_match('/^#[A-Za-z][\w.-]*$/', $value) === 1;
            }

            if ($allowed && preg_match('/url\(\s*[\'"]?\s*(?!#)/i', $value) === 1) {
                $allowed = false;
            }

            if (! $allowed) {
                $element->removeAttributeNode($attribute);
            }
        }

        /** @var array<int, DOMNode> $children */
        $children = iterator_to_array($element->childNodes);

        foreach ($children as $child) {
            if ($child instanceof DOMElement) {
                if (! in_array(strtolower($child->localName ?? ''), self::ELEMENTS, true)) {
                    $element->removeChild($child);

                    continue;
                }

                self::cleanNode($child);
            } elseif ($child->nodeType !== XML_TEXT_NODE) {
                // Comments, CDATA (could hold CSS), processing instructions.
                $element->removeChild($child);
            }
        }
    }

    private static function isSafeStyle(string $style): bool
    {
        return preg_match('/(?:url|expression|import|javascript|behavior|@)/i', $style) !== 1
            && preg_match('/^[a-z0-9\s:;#.,%()\/-]*$/i', $style) === 1;
    }
}
