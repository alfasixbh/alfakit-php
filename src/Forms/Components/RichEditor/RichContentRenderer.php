<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Forms\Components\RichEditor;

use AlfaSix\Alfakit\Forms\Components\RichEditor\FileAttachmentProviders\Contracts\FileAttachmentProvider;
use AlfaSix\Alfakit\Forms\Components\RichEditor\Plugins\Contracts\HasFileAttachmentProvider;
use AlfaSix\Alfakit\Forms\Components\RichEditor\Plugins\Contracts\RichContentPlugin;
use AlfaSix\Alfakit\Support\Html;
use AlfaSix\Alfakit\Support\Url;
use Closure;
use Illuminate\Contracts\Support\Htmlable;
use JsonException;

/**
 * Renders RichEditor content for display: replaces merge tags with their values and
 * custom blocks with their `toHtml()`, and converts JSON content (`RichEditor::json()`)
 * to HTML.
 *
 *     RichContentRenderer::make($post->content)
 *         ->mergeTags(['name' => $user->name])
 *         ->customBlocks([HeroBlock::class])
 *         ->toHtml();
 *
 * Text colours, highlights, sub/superscript, small text, lead paragraphs, alignment,
 * tables, `grid` column layouts and collapsible `details` sections are rendered in both
 * formats. With `mentions()`, mentions whose provider has a `url()` become links.
 *
 * JSON content is rebuilt from known nodes and marks with every text and attribute
 * escaped and unsafe link targets dropped. HTML content is sanitised first
 * (`Support\Html::sanitize()`, the panel's `htmlSanitizer()` config): a crafted request
 * can store any HTML in the field, so it is never trusted. Merge tag values and custom
 * block `toHtml()` output are inserted afterwards: plain merge tag strings are escaped,
 * `Htmlable` values and block HTML are markup written in code. Call `sanitize(false)`
 * only when the stored HTML is trusted.
 *
 * `plugins()` adds the plugins' allowed tags and attributes to the sanitiser and renders
 * their JSON nodes; `fileAttachments()` swaps private attachments for temporary URLs.
 * `toEditorHtml()` converts JSON to the HTML the editor stores instead (merge tags and
 * custom blocks kept as editor markers).
 */
final class RichContentRenderer implements Htmlable
{
    /** Nodes rendered here; plugins render the others. */
    private const NODES = [
        'doc', 'text', 'paragraph', 'heading', 'blockquote', 'bulletList', 'orderedList', 'listItem', 'codeBlock',
        'horizontalRule', 'table', 'tableRow', 'tableHeader', 'tableCell', 'details', 'detailsSummary',
        'detailsContent', 'hardBreak', 'image', 'mention', 'mergeTag', 'customBlock', 'grid', 'gridColumn',
    ];

    private const MARKS = ['bold', 'italic', 'underline', 'strike', 'code', 'highlight', 'subscript', 'superscript', 'small', 'textColor', 'link'];

    /** @var array<string, string|Htmlable|Closure|null> */
    private array $mergeTags = [];

    /** @var array<string, class-string<RichContentCustomBlock>> */
    private array $customBlocks = [];

    /** @var array<string, array<string, mixed>> */
    private array $customBlockData = [];

    private bool $shouldSanitize = true;

    /** @var array<RichContentPlugin> */
    private array $plugins = [];

    /** @var array<string, MentionProvider> keyed by trigger character */
    private array $mentionProviders = [];

    private ?FileAttachmentUrls $fileAttachmentUrls = null;

    private ?FileAttachmentProvider $fileAttachmentProvider = null;

    /** Current mention labels of the content being rendered (`MentionProvider::getLabels()`). */
    private ?MentionLabels $mentionLabels = null;

    /** @var list<string>|null `linkProtocols()`: link schemes kept (null: the sanitiser's). */
    private ?array $linkProtocols = null;

    /** JSON → the editor's storage HTML (`toEditorHtml()`). */
    private bool $keepsEditorMarkers = false;

    /**
     * @param  string|array<string, mixed>|null  $content
     */
    public function __construct(private readonly string|array|null $content) {}

    /**
     * @param  string|array<string, mixed>|null  $content
     */
    public static function make(string|array|null $content): self
    {
        return new self($content);
    }

    /**
     * @param  array<string, string|Htmlable|Closure|null>  $values  Plain strings are escaped.
     */
    public function mergeTags(array $values): self
    {
        $this->mergeTags = [...$this->mergeTags, ...$values];

        return $this;
    }

    /**
     * @param  array<int|class-string<RichContentCustomBlock>, class-string<RichContentCustomBlock>|array<string, mixed>>  $blocks
     *                                                                                                                              `[HeroBlock::class]` or `[HeroBlock::class => $data]`
     */
    public function customBlocks(array $blocks): self
    {
        foreach ($blocks as $key => $value) {
            $class = is_string($key) ? $key : $value;

            if (! is_string($class) || ! is_subclass_of($class, RichContentCustomBlock::class)) {
                continue;
            }

            $this->customBlocks[$class::getId()] = $class;
            $this->customBlockData[$class::getId()] = is_array($value) ? $value : [];
        }

        return $this;
    }

    /**
     * Mention providers: mentions show their provider's current label
     * (`getLabelsUsing()`, else `items()`), falling back to the stored label, then the id;
     * a provider's `url()` turns them into links
     * (`<a href="…" data-type="mention" data-id="5">@Jane</a>`).
     *
     * @param  array<MentionProvider>  $providers
     */
    public function mentions(array $providers): self
    {
        foreach ($providers as $provider) {
            $this->mentionProviders[$provider->getChar()] = $provider;
        }

        return $this;
    }

    /**
     * URL schemes links may use (`['https', 'mailto']`), for HTML (the sanitiser's allowed
     * link schemes) and JSON content. Relative links are always kept. Null keeps the
     * defaults (http, https, mailto, tel).
     *
     * @param  array<string>|null  $protocols
     */
    public function linkProtocols(?array $protocols): self
    {
        $this->linkProtocols = $protocols === null ? null : self::normalizeLinkProtocols($protocols);

        return $this;
    }

    /**
     * Lower-case schemes without `:`, invalid ones dropped. `javascript`, `data` and
     * `vbscript` are never allowed.
     *
     * @param  array<mixed>  $protocols
     * @return list<string>
     */
    public static function normalizeLinkProtocols(array $protocols): array
    {
        $normalized = [];

        foreach ($protocols as $protocol) {
            $protocol = is_string($protocol) ? strtolower(rtrim(trim($protocol), ':')) : '';

            if (preg_match('/^[a-z][a-z0-9+.-]*$/', $protocol) === 1 && ! in_array($protocol, ['javascript', 'data', 'vbscript'], true)) {
                $normalized[$protocol] = $protocol;
            }
        }

        return array_values($normalized);
    }

    public function sanitize(bool $condition = true): self
    {
        $this->shouldSanitize = $condition;

        return $this;
    }

    /**
     * @param  array<RichContentPlugin>  $plugins
     */
    public function plugins(array $plugins): self
    {
        $this->plugins = [...$this->plugins, ...array_values($plugins)];

        return $this;
    }

    /**
     * Where the editor stored its images. With `private` visibility the stored URLs are
     * replaced with temporary ones (`temporaryUrl()`), which are never stored. They live
     * `$temporaryUrlExpiry` minutes (`config('alfakit.temporary_file_url_expiry_minutes')`
     * by default), rounded up to the end of the hour.
     */
    public function fileAttachments(?string $disk, ?string $directory = null, ?string $visibility = null, ?int $temporaryUrlExpiry = null): self
    {
        $disk ??= config('alfakit.default_filesystem_disk', config('filesystems.default'));
        // As the editor (and FileUpload): a local disk without a public `url` is private.
        $visibility ??= is_string($disk) ? \AlfaSix\Alfakit\Support\TemporaryUrls::defaultVisibility($disk) : 'public';

        $this->fileAttachmentUrls = $visibility === 'private' && is_string($disk)
            ? new FileAttachmentUrls($disk, alfakit()->getCurrentOrDefaultPanel()->applyTenantUploadDirectory($directory), $temporaryUrlExpiry)
            : null;

        return $this;
    }

    /**
     * A custom file attachment provider: images show the URL its `getFileAttachmentUrl()`
     * returns for their stored `src`. Without one, a plugin's (`HasFileAttachmentProvider`)
     * is used.
     */
    public function fileAttachmentProvider(?FileAttachmentProvider $provider): self
    {
        $this->fileAttachmentProvider = $provider;

        return $this;
    }

    public function getFileAttachmentProvider(): ?FileAttachmentProvider
    {
        if ($this->fileAttachmentProvider !== null) {
            return $this->fileAttachmentProvider;
        }

        foreach ($this->plugins as $plugin) {
            if ($plugin instanceof HasFileAttachmentProvider && ($provider = $plugin->getFileAttachmentProvider()) !== null) {
                return $this->fileAttachmentProvider = $provider;
            }
        }

        return null;
    }

    /**
     * Swap stored image URLs for the provider's display URLs.
     */
    private function applyFileAttachmentProvider(string $html): string
    {
        $provider = $this->getFileAttachmentProvider();

        if ($provider === null) {
            return $html;
        }

        return (string) preg_replace_callback('/<img\b[^>]*>/i', static function (array $tag) use ($provider): string {
            if (preg_match('/\bsrc\s*=\s*(["\'])([^"\']*)\1/i', $tag[0], $src, PREG_OFFSET_CAPTURE) !== 1) {
                return $tag[0];
            }

            $url = Url::sanitize($provider->getFileAttachmentUrl(html_entity_decode($src[2][0], ENT_QUOTES | ENT_HTML5)));

            return $url === null ? $tag[0] : substr_replace($tag[0], e($url), $src[2][1], strlen($src[2][0]));
        }, $html);
    }

    /**
     * The HTML the editor stores for this content: JSON converted to HTML with mentions,
     * merge tags and custom blocks kept as editor markers (not rendered). Not sanitised.
     */
    public function toEditorHtml(): string
    {
        $this->keepsEditorMarkers = true;

        try {
            $content = $this->decodedContent();
            $this->mentionLabels = MentionLabels::for(array_values($this->mentionProviders), $content);

            return is_array($content) ? $this->renderNode($content) : (string) $this->mentionLabels->apply($content);
        } finally {
            $this->keepsEditorMarkers = false;
            $this->mentionLabels = null;
        }
    }

    public function toHtml(): string
    {
        $content = $this->decodedContent();

        try {
            $this->mentionLabels = is_array($content) ? MentionLabels::for(array_values($this->mentionProviders), $content) : null;
            $html = is_array($content) ? $this->renderNode($content) : $this->renderHtml($content);
        } finally {
            $this->mentionLabels = null;
        }

        $html = $this->applyFileAttachmentProvider($html);

        return $this->fileAttachmentUrls === null ? $html : (string) $this->fileAttachmentUrls->sign($html);
    }

    /**
     * @return string|array<string, mixed>
     */
    private function decodedContent(): string|array
    {
        $content = $this->content;

        if (is_string($content) && str_starts_with(ltrim($content), '{')) {
            try {
                $decoded = json_decode($content, true, flags: JSON_THROW_ON_ERROR);
                $content = is_array($decoded) ? $decoded : $content;
            } catch (JsonException) {
                // Not JSON: HTML that starts with a brace.
            }
        }

        return is_array($content) ? $content : (string) $content;
    }

    public function __toString(): string
    {
        return $this->toHtml();
    }

    // ---------------------------------------------------------------- HTML content

    private function renderHtml(string $html): string
    {
        if ($this->shouldSanitize) {
            $config = Html::sanitizerConfig();

            foreach ($this->plugins as $plugin) {
                $config = $plugin->configureHtmlSanitizer($config);
            }

            if ($this->linkProtocols !== null) {
                $config = $config->allowLinkSchemes($this->linkProtocols);
            }

            $html = Html::sanitize($html, $config);
        }

        if ($this->mentionProviders !== []) {
            $labels = MentionLabels::for(array_values($this->mentionProviders), $html);

            $html = (string) preg_replace_callback(
                '/<span\b[^>]*\bdata-type="mention"[^>]*>(.*?)<\/span>/is',
                function (array $match) use ($labels): string {
                    $text = html_entity_decode(strip_tags($match[1]), ENT_QUOTES | ENT_HTML5);
                    $char = mb_substr($text, 0, 1);
                    $id = (string) $this->attribute($match[0], 'data-id');

                    if ($id === '' || ! isset($this->mentionProviders[$char])) {
                        return $match[0];
                    }

                    $label = $labels->label($char, $id, $this->attribute($match[0], 'data-label') ?? mb_substr($text, 1));

                    return $this->renderMentionLink($id, $char, $label)
                        ?? preg_replace('/>.*$/s', '>', $match[0]).e($char.$label).'</span>';
                },
                $html,
            );
        }

        $html = (string) preg_replace_callback(
            '/<span\b[^>]*\bdata-type="mergeTag"[^>]*>.*?<\/span>/is',
            fn (array $match): string => $this->renderMergeTag($this->attribute($match[0], 'data-id')),
            $html,
        );

        return (string) preg_replace_callback(
            '/<div\b[^>]*\bdata-type="customBlock"[^>]*>.*?<\/div>/is',
            fn (array $match): string => $this->renderCustomBlock($this->attribute($match[0], 'data-id'), $this->decodeConfig($this->attribute($match[0], 'data-config'))),
            $html,
        );
    }

    private function attribute(string $tag, string $name): ?string
    {
        if (preg_match('/\b'.preg_quote($name, '/').'\s*=\s*"([^"]*)"/i', $tag, $match) !== 1) {
            return null;
        }

        return html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5);
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeConfig(mixed $config): array
    {
        if (is_array($config)) {
            return $config;
        }

        if (! is_string($config) || $config === '') {
            return [];
        }

        try {
            $decoded = json_decode($config, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    private function renderMergeTag(?string $id): string
    {
        if ($id === null || $id === '') {
            return '';
        }

        if ($this->keepsEditorMarkers) {
            return '<span data-type="mergeTag" data-id="'.e($id).'">'.e('{{ '.$id.' }}').'</span>';
        }

        if (! array_key_exists($id, $this->mergeTags)) {
            return e('{{ '.$id.' }}');
        }

        $value = $this->mergeTags[$id];
        $value = $value instanceof Closure ? $value() : $value;

        return $value instanceof Htmlable ? $value->toHtml() : e((string) $value);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function renderCustomBlock(?string $id, array $config): string
    {
        if ($this->keepsEditorMarkers) {
            return $id === null ? '' : '<div data-type="customBlock" data-id="'.e($id).'" data-config="'.e(json_encode((object) $config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}').'"></div>';
        }

        $block = $id === null ? null : ($this->customBlocks[$id] ?? null);

        return $block === null ? '' : $block::toHtml($config, $this->customBlockData[$id] ?? []);
    }

    // ---------------------------------------------------------------- JSON content

    /**
     * @param  array<string, mixed>  $node
     */
    private function renderNode(array $node): string
    {
        $type = $node['type'] ?? null;
        $attrs = is_array($node['attrs'] ?? null) ? $node['attrs'] : [];
        $children = fn (): string => implode('', array_map(
            fn (mixed $child): string => is_array($child) ? $this->renderNode($child) : '',
            is_array($node['content'] ?? null) ? $node['content'] : [],
        ));

        if ($this->plugins !== [] && is_string($type) && ! in_array($type, self::NODES, true)) {
            $content = $children();

            foreach ($this->plugins as $plugin) {
                if (($html = $plugin->renderJson($node, $content)) !== null) {
                    return $html;
                }
            }

            return $content;
        }

        return match ($type) {
            'doc' => $children(),
            'text' => $this->renderText($node),
            'paragraph' => '<p'.(($attrs['lead'] ?? false) === true ? ' class="alfakit-lead"' : '').$this->alignment($attrs).'>'.$children().'</p>',
            'heading' => $this->wrap('h'.min(6, max(1, (int) ($attrs['level'] ?? 2))), $children(), $this->alignment($attrs)),
            'blockquote' => '<blockquote>'.$children().'</blockquote>',
            'bulletList' => '<ul>'.$children().'</ul>',
            'orderedList' => ((int) ($attrs['start'] ?? 1)) > 1 ? '<ol start="'.(int) $attrs['start'].'">'.$children().'</ol>' : '<ol>'.$children().'</ol>',
            'listItem' => '<li>'.$children().'</li>',
            'codeBlock' => '<pre><code'.(is_string($attrs['language'] ?? null) && preg_match('/^[\w+-]+$/', $attrs['language']) === 1 ? ' class="language-'.e($attrs['language']).'"' : '').'>'.$children().'</code></pre>',
            'horizontalRule' => '<hr>',
            'table' => '<table><tbody>'.$children().'</tbody></table>',
            'tableRow' => '<tr>'.$children().'</tr>',
            'tableHeader' => $this->wrap('th', $children(), $this->cellAttributes($attrs)),
            'tableCell' => $this->wrap('td', $children(), $this->cellAttributes($attrs)),
            'details' => '<details>'.$children().'</details>',
            'detailsSummary' => '<summary>'.$children().'</summary>',
            'detailsContent' => '<div data-type="detailsContent">'.$children().'</div>',
            'grid' => '<div data-type="grid" class="alfakit-grid alfakit-grid-cols-'.min(6, max(2, is_numeric($attrs['columns'] ?? null) ? (int) $attrs['columns'] : 2)).'">'.$children().'</div>',
            'gridColumn' => '<div data-type="gridColumn" class="alfakit-grid-column">'.$children().'</div>',
            'hardBreak' => '<br>',
            'image' => $this->renderImage($attrs),
            'mention' => $this->renderJsonMention($attrs) ?? '<span data-type="mention" data-id="'.e((string) ($attrs['id'] ?? '')).'"'.($this->keepsEditorMarkers ? ' data-label="'.e($this->mentionLabel($attrs)).'" data-char="'.e((string) ($attrs['char'] ?? '@')).'"' : '').'>'.e((string) ($attrs['char'] ?? '@')).e($this->mentionLabel($attrs)).'</span>',
            'mergeTag' => $this->renderMergeTag(is_scalar($attrs['id'] ?? null) ? (string) $attrs['id'] : null),
            'customBlock' => $this->renderCustomBlock(is_scalar($attrs['id'] ?? null) ? (string) $attrs['id'] : null, $this->decodeConfig($attrs['config'] ?? null)),
            default => $children(),
        };
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    private function renderJsonMention(array $attrs): ?string
    {
        if ($this->keepsEditorMarkers || $this->mentionProviders === []) {
            return null;
        }

        $id = (string) ($attrs['id'] ?? '');

        return $this->renderMentionLink($id, (string) ($attrs['char'] ?? '@'), $this->mentionLabel($attrs));
    }

    /**
     * A JSON mention's label: its provider's current one, else the stored one, else the id.
     *
     * @param  array<string, mixed>  $attrs
     */
    private function mentionLabel(array $attrs): string
    {
        $id = is_scalar($attrs['id'] ?? null) ? (string) $attrs['id'] : '';
        $stored = is_scalar($attrs['label'] ?? null) ? (string) $attrs['label'] : null;

        return $this->mentionLabels?->label((string) ($attrs['char'] ?? '@'), $id, $stored) ?? ($stored ?? $id);
    }

    /**
     * A mention as a link when its provider has a `url()` (null otherwise).
     */
    private function renderMentionLink(string $id, string $char, string $label): ?string
    {
        $provider = $this->mentionProviders[$char] ?? null;

        if ($id === '' || $provider === null || ! $provider->hasUrl()) {
            return null;
        }

        $url = $this->safeUrl($provider->getUrl($id, $label));

        if ($url === null) {
            return null;
        }

        return '<a href="'.e($url).'" data-type="mention" data-id="'.e($id).'">'.e($char.$label).'</a>';
    }

    private function wrap(string $tag, string $html, string $attributes = ''): string
    {
        return "<{$tag}{$attributes}>{$html}</{$tag}>";
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    private function alignment(array $attrs): string
    {
        $align = $attrs['textAlign'] ?? null;

        return is_string($align) && in_array($align, ['start', 'center', 'end', 'justify', 'left', 'right'], true)
            ? ' style="text-align: '.$align.'"'
            : '';
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    private function cellAttributes(array $attrs): string
    {
        $html = '';

        foreach (['colspan', 'rowspan'] as $name) {
            $span = is_numeric($attrs[$name] ?? null) ? (int) $attrs[$name] : 1;

            if ($span > 1) {
                $html .= ' '.$name.'="'.min($span, 1000).'"';
            }
        }

        return $html;
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function renderText(array $node): string
    {
        $html = nl2br(e((string) ($node['text'] ?? '')), false);

        foreach (array_reverse(is_array($node['marks'] ?? null) ? $node['marks'] : []) as $mark) {
            if (! is_array($mark)) {
                continue;
            }

            $attrs = is_array($mark['attrs'] ?? null) ? $mark['attrs'] : [];

            foreach (in_array($mark['type'] ?? null, self::MARKS, true) ? [] : $this->plugins as $plugin) {
                if (($marked = $plugin->renderJson($mark, $html)) !== null) {
                    $html = $marked;

                    continue 2;
                }
            }

            $html = match ($mark['type'] ?? null) {
                'bold' => $this->wrap('strong', $html),
                'italic' => $this->wrap('em', $html),
                'underline' => $this->wrap('u', $html),
                'strike' => $this->wrap('s', $html),
                'code' => $this->wrap('code', $html),
                'highlight' => $this->wrap('mark', $html),
                'subscript' => $this->wrap('sub', $html),
                'superscript' => $this->wrap('sup', $html),
                'small' => $this->wrap('small', $html),
                'textColor' => is_string($attrs['color'] ?? null) && Html::isSafeColor($attrs['color'])
                    ? '<span data-color="'.e(trim($attrs['color'])).'" style="color: '.e(trim($attrs['color'])).'">'.$html.'</span>'
                    : $html,
                'link' => ($href = Url::sanitize($attrs['href'] ?? null, $this->linkProtocols)) === null
                    ? $html
                    : '<a href="'.e($href).'"'.(($attrs['target'] ?? null) === '_blank' ? ' target="_blank" rel="noopener noreferrer nofollow"' : '').'>'.$html.'</a>',
                default => $html,
            };
        }

        return $html;
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    private function renderImage(array $attrs): string
    {
        $src = $this->safeUrl($attrs['src'] ?? null);

        if ($src === null) {
            return '';
        }

        $html = '<img src="'.e($src).'"';

        foreach (['alt', 'title'] as $name) {
            if (is_scalar($attrs[$name] ?? null) && (string) $attrs[$name] !== '') {
                $html .= ' '.$name.'="'.e((string) $attrs[$name]).'"';
            }
        }

        // `resizableImages()`: the width the image was resized to (the height follows).
        if (is_numeric($attrs['width'] ?? null) && (int) $attrs['width'] > 0) {
            $html .= ' width="'.min((int) $attrs['width'], 10000).'"';
        }

        return $html.'>';
    }

    /**
     * http(s), mailto and tel URLs, and relative ones. Anything else (`javascript:`,
     * `data:`) is dropped.
     */
    private function safeUrl(mixed $url): ?string
    {
        return Url::sanitize($url);
    }
}
