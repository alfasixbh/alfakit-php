<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Support;

use AlfaSix\Alfakit\Support\HtmlSanitizer\ClassAttributeSanitizer;
use AlfaSix\Alfakit\Support\HtmlSanitizer\ImageDimensionSanitizer;
use AlfaSix\Alfakit\Support\HtmlSanitizer\ImageSourceSanitizer;
use AlfaSix\Alfakit\Support\HtmlSanitizer\StyleAttributeSanitizer;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Str;
use Stringable;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;
use WeakMap;

/**
 * Values the renderers insert as HTML (notification bodies, modal descriptions, helper
 * text, placeholder content). Plain strings are escaped; pass an `Htmlable` (`HtmlString`,
 * a view, `str()->markdown()->toHtmlString()`) to send markup you trust.
 *
 * The trust rule: markup written in code (`HtmlString`, `Htmlable`, Blade views) is sent
 * as-is; strings that come from state (record attributes, rich editor content) are
 * escaped or, where a component renders them as HTML (`->html()`, `->markdown()`, the
 * `Html` component, `RichContentRenderer`), passed through {@see self::sanitize()}.
 */
final class Html
{
    /** @var WeakMap<HtmlSanitizerConfig, HtmlSanitizer>|null */
    private static ?WeakMap $sanitizers = null;

    private static ?HtmlSanitizerConfig $defaultConfig = null;

    public static function from(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof Htmlable) {
            return $value->toHtml();
        }

        return is_scalar($value) || $value instanceof Stringable ? e((string) $value) : null;
    }

    /**
     * Plain text of a label that may be `Htmlable` (markup stripped, entities decoded), for
     * titles, tooltips and `aria-label`s.
     */
    public static function toText(string|Htmlable|null $value): string
    {
        if (! $value instanceof Htmlable) {
            return (string) $value;
        }

        return trim(html_entity_decode(strip_tags(self::sanitize($value)), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /**
     * Markdown with raw HTML escaped, `javascript:` style links removed and the result
     * sanitised.
     */
    public static function markdown(string $markdown, bool $sanitize = true): string
    {
        $html = Str::markdown($markdown, [
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
        ]);

        return $sanitize ? self::sanitize($html) : $html;
    }

    /**
     * Remove scripts, event handlers, unsafe URLs and unknown elements from HTML.
     *
     * Kept: text formatting, headings, paragraphs, lists, quotes, code blocks, tables,
     * links (http, https, mailto, tel, relative), images (http, https, relative and
     * `data:image/*`; `width`/`height` as plain pixel sizes), collapsible `details`, and the rich editor's mention, merge tag and
     * custom block markers, text colours, highlights and alignment (inline `style` limited
     * to `color`, `background-color` and `text-align` with plain values).
     * Pass a config, or set one per panel with `Panel::htmlSanitizer()`, to change this.
     */
    public static function sanitize(string|Htmlable|null $html, ?HtmlSanitizerConfig $config = null): string
    {
        if ($html instanceof Htmlable) {
            $html = $html->toHtml();
        }

        if ($html === null || trim($html) === '') {
            return '';
        }

        // Plain text needs no parsing (and keeps its exact spelling).
        if (! str_contains($html, '<') && ! str_contains($html, '&')) {
            return $html;
        }

        return self::sanitizerFor($config ?? self::sanitizerConfig())->sanitize($html);
    }

    /**
     * The configuration {@see self::sanitize()} uses without an explicit one: the current
     * panel's `htmlSanitizer()`, or {@see self::defaultSanitizerConfig()}. Rich content
     * plugins extend this one (`RichContentPlugin::configureHtmlSanitizer()`).
     */
    public static function sanitizerConfig(): HtmlSanitizerConfig
    {
        return self::panelConfig() ?? self::defaultSanitizerConfig();
    }

    /**
     * The configuration {@see self::sanitize()} uses when the panel sets none. Extend it
     * in `Panel::htmlSanitizer(fn (HtmlSanitizerConfig $config) => $config->allowElement('iframe', ['src']))`.
     */
    public static function defaultSanitizerConfig(): HtmlSanitizerConfig
    {
        if (self::$defaultConfig !== null) {
            return self::$defaultConfig;
        }

        $global = ['title', 'lang', 'dir', 'class'];

        // `style` is limited to colour, background colour and alignment ({@see StyleAttributeSanitizer}).
        $aligned = ['style'];
        $coloured = ['style', 'data-color'];

        $elements = [
            'p' => $aligned, 'br' => [], 'hr' => [], 'div' => ['data-type', 'data-id', 'data-config'],
            'span' => ['data-type', 'data-id', 'data-label', ...$coloured], 'blockquote' => ['cite'], 'pre' => [], 'code' => [],
            'kbd' => [], 'samp' => [], 'var' => [], 'strong' => [], 'b' => [], 'em' => [], 'i' => [], 'u' => [],
            's' => [], 'strike' => [], 'del' => ['cite', 'datetime'], 'ins' => ['cite', 'datetime'], 'sub' => [],
            'sup' => [], 'small' => [], 'mark' => $coloured, 'abbr' => [], 'cite' => [], 'q' => ['cite'], 'dfn' => [],
            'time' => ['datetime'], 'bdi' => [], 'bdo' => [], 'wbr' => [],
            'h1' => $aligned, 'h2' => $aligned, 'h3' => $aligned, 'h4' => $aligned, 'h5' => $aligned, 'h6' => $aligned,
            'ul' => [], 'ol' => ['start', 'reversed', 'type'], 'li' => ['value'], 'dl' => [], 'dt' => [], 'dd' => [],
            'a' => ['href', 'target', 'hreflang'],
            'img' => ['src', 'alt', 'width', 'height', 'loading'], 'figure' => [], 'figcaption' => [],
            'table' => [], 'caption' => [], 'colgroup' => ['span'], 'col' => ['span'], 'thead' => [], 'tbody' => [],
            'tfoot' => [], 'tr' => [], 'th' => ['colspan', 'rowspan', 'scope', 'headers', 'abbr', ...$aligned],
            'td' => ['colspan', 'rowspan', 'headers', ...$aligned],
            'details' => ['open', 'data-type'], 'summary' => [],
        ];

        $config = (new HtmlSanitizerConfig)
            ->allowLinkSchemes(['http', 'https', 'mailto', 'tel'])
            ->allowRelativeLinks()
            ->allowMediaSchemes(['http', 'https', 'data'])
            ->allowRelativeMedias()
            ->forceAttribute('a', 'rel', 'noopener noreferrer nofollow')
            ->withAttributeSanitizer(new ClassAttributeSanitizer)
            ->withAttributeSanitizer(new ImageSourceSanitizer)
            ->withAttributeSanitizer(new ImageDimensionSanitizer)
            ->withAttributeSanitizer(new StyleAttributeSanitizer)
            ->withMaxInputLength(-1);

        foreach ($elements as $element => $attributes) {
            $config = $config->allowElement($element, [...$global, ...$attributes]);
        }

        // Content of these is never shown as text.
        foreach (['script', 'style', 'template', 'noscript', 'iframe', 'object', 'embed', 'svg', 'math', 'title', 'head', 'textarea', 'select'] as $element) {
            $config = $config->dropElement($element);
        }

        return self::$defaultConfig = $config;
    }

    /**
     * Whether a CSS colour is a plain value safe to store inline: `#rgb(a)`, `#rrggbb(aa)`,
     * `rgb()`/`rgba()`/`hsl()`/`hsla()` with numeric arguments, or a colour keyword.
     */
    public static function isSafeColor(string $color): bool
    {
        $color = trim($color);

        return preg_match('/^#(?:[0-9a-f]{3,4}|[0-9a-f]{6}|[0-9a-f]{8})$/i', $color) === 1
            || preg_match('/^(?:rgba?|hsla?)\(\s*[0-9.%\s,\/deg+-]{1,60}\)$/i', $color) === 1
            || preg_match('/^[a-z]{3,20}$/i', $color) === 1;
    }

    private static function panelConfig(): ?HtmlSanitizerConfig
    {
        return alfakit()->getCurrentPanel()?->getHtmlSanitizerConfig();
    }

    private static function sanitizerFor(HtmlSanitizerConfig $config): HtmlSanitizer
    {
        self::$sanitizers ??= new WeakMap;

        return self::$sanitizers[$config] ??= new HtmlSanitizer($config);
    }
}
