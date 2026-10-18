<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Forms\Components\RichEditor;

use AlfaSix\Alfakit\Forms\Components\RichEditor\FileAttachmentProviders\Contracts\FileAttachmentProvider;
use AlfaSix\Alfakit\Forms\Components\RichEditor\Plugins\Contracts\HasFileAttachmentProvider;
use AlfaSix\Alfakit\Forms\Components\RichEditor\Plugins\Contracts\RichContentPlugin;
use Closure;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Stringable;

/**
 * The rich content configuration of one model attribute, registered in
 * `setUpRichContent()` with `$this->registerRichContent('content')`. A
 * `RichEditor::make('content')` on a form for the model uses it for everything it doesn't
 * set itself, and `$model->renderRichContent('content')` renders the stored content with
 * it (custom blocks, merge tag values, plugins, signed URLs for private images).
 *
 * Rendering it (`{{ $post->getRichContentAttribute('content') }}`, `toHtml()`) gives the
 * same HTML as `renderRichContent()`.
 */
final class RichContentAttribute implements Htmlable, Stringable
{
    private ?string $fileAttachmentsDisk = null;

    private ?string $fileAttachmentsDirectory = null;

    private ?string $fileAttachmentsVisibility = null;

    /** @var array<int|string, class-string<RichContentCustomBlock>|array<class-string<RichContentCustomBlock>>> */
    private array $customBlocks = [];

    /** @var array<int|string, mixed> */
    private array $mergeTags = [];

    /** @var array<string, string> */
    private array $mergeTagLabels = [];

    /** @var array<MentionProvider> */
    private array $mentions = [];

    /** @var array<string, string>|null */
    private ?array $textColors = null;

    private bool $hasCustomTextColors = false;

    /** @var array<RichContentPlugin> */
    private array $plugins = [];

    private bool $isJson = false;

    /** @var list<string>|null */
    private ?array $linkProtocols = null;

    private ?FileAttachmentProvider $fileAttachmentProvider = null;

    public function __construct(private readonly Model $model, private readonly string $name) {}

    public function fileAttachmentsDisk(?string $disk): self
    {
        $this->fileAttachmentsDisk = $disk;

        return $this;
    }

    public function fileAttachmentsDirectory(?string $directory): self
    {
        $this->fileAttachmentsDirectory = $directory;

        return $this;
    }

    public function fileAttachmentsVisibility(?string $visibility): self
    {
        $this->fileAttachmentsVisibility = $visibility;

        return $this;
    }

    /**
     * @param  array<int|string, class-string<RichContentCustomBlock>|array<class-string<RichContentCustomBlock>>>  $blocks  String keys group blocks
     */
    public function customBlocks(array $blocks): self
    {
        $this->customBlocks = $blocks;

        return $this;
    }

    /**
     * Merge tags: `['name' => $user->name]` (values replace the tags when rendering; a
     * Closure is called then) or a list of ids for the editor only.
     *
     * @param  array<int|string, mixed>  $tags
     */
    public function mergeTags(array $tags): self
    {
        $this->mergeTags = $tags;

        return $this;
    }

    /**
     * What the editor shows for each merge tag (`['name' => 'Full name']`). Display only:
     * the content stores the id.
     *
     * @param  array<string, string>  $labels
     */
    public function mergeTagLabels(array $labels): self
    {
        $this->mergeTagLabels = $labels;

        return $this;
    }

    /**
     * @param  array<MentionProvider>  $providers
     */
    public function mentions(array $providers): self
    {
        $this->mentions = $providers;

        return $this;
    }

    /**
     * @param  array<string, string>|null  $colors
     */
    public function textColors(?array $colors): self
    {
        $this->textColors = $colors;

        return $this;
    }

    public function customTextColors(bool $condition = true): self
    {
        $this->hasCustomTextColors = $condition;

        return $this;
    }

    /**
     * @param  array<RichContentPlugin>  $plugins
     */
    public function plugins(array $plugins): self
    {
        $this->plugins = $plugins;

        return $this;
    }

    public function json(bool $condition = true): self
    {
        $this->isJson = $condition;

        return $this;
    }

    /**
     * URL schemes links may use (`['https', 'mailto']`); others lose their link when the
     * content is saved and rendered. Null keeps the sanitiser's (http, https, mailto, tel).
     *
     * @param  array<string>|null  $protocols
     */
    public function linkProtocols(?array $protocols): self
    {
        $this->linkProtocols = $protocols === null ? null : RichContentRenderer::normalizeLinkProtocols($protocols);

        return $this;
    }

    /**
     * @return list<string>|null
     */
    public function getLinkProtocols(): ?array
    {
        return $this->linkProtocols;
    }

    /**
     * Store the attribute's image attachments with a custom provider (e.g. a media
     * library) instead of the attachments disk.
     */
    public function fileAttachmentProvider(?FileAttachmentProvider $provider): self
    {
        $this->fileAttachmentProvider = $provider?->attribute($this);

        return $this;
    }

    /**
     * The attribute's provider, else the first plugin's (`HasFileAttachmentProvider`).
     */
    public function getFileAttachmentProvider(): ?FileAttachmentProvider
    {
        if ($this->fileAttachmentProvider !== null) {
            return $this->fileAttachmentProvider;
        }

        foreach ($this->plugins as $plugin) {
            if ($plugin instanceof HasFileAttachmentProvider && ($provider = $plugin->getFileAttachmentProvider()) !== null) {
                return $this->fileAttachmentProvider = $provider->attribute($this);
            }
        }

        return null;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getModel(): Model
    {
        return $this->model;
    }

    public function getFileAttachmentsDisk(): ?string
    {
        return $this->fileAttachmentsDisk;
    }

    public function getFileAttachmentsDirectory(): ?string
    {
        return $this->fileAttachmentsDirectory;
    }

    public function getFileAttachmentsVisibility(): ?string
    {
        return $this->fileAttachmentsVisibility;
    }

    /**
     * @return array<int|string, class-string<RichContentCustomBlock>|array<class-string<RichContentCustomBlock>>>
     */
    public function getCustomBlocks(): array
    {
        return $this->customBlocks;
    }

    /**
     * The merge tags the editor offers: id => label.
     *
     * @return array<string, string>
     */
    public function getMergeTagLabels(): array
    {
        $tags = [];

        foreach ($this->mergeTags as $key => $value) {
            $id = is_int($key) ? (string) $value : $key;
            $tags[$id] = $this->mergeTagLabels[$id] ?? $id;
        }

        foreach ($this->mergeTagLabels as $id => $label) {
            $tags[$id] = $label;
        }

        return $tags;
    }

    /**
     * Merge tag values for rendering (tags registered without a value are left out).
     *
     * @return array<string, mixed>
     */
    public function getMergeTagValues(): array
    {
        return array_filter($this->mergeTags, static fn (int|string $key): bool => is_string($key), ARRAY_FILTER_USE_KEY);
    }

    /**
     * @return array<MentionProvider>
     */
    public function getMentions(): array
    {
        return $this->mentions;
    }

    /**
     * @return array<string, string>|null
     */
    public function getTextColors(): ?array
    {
        return $this->textColors;
    }

    public function hasCustomTextColors(): bool
    {
        return $this->hasCustomTextColors;
    }

    /**
     * @return array<RichContentPlugin>
     */
    public function getPlugins(): array
    {
        return $this->plugins;
    }

    public function isJson(): bool
    {
        return $this->isJson;
    }

    /**
     * A renderer for the attribute's stored content (or `$content`) with this configuration.
     *
     * @param  string|array<string, mixed>|null  $content
     */
    public function getRenderer(string|array|null $content = null): RichContentRenderer
    {
        $content ??= $this->model->getAttribute($this->name);

        if (! is_string($content) && ! is_array($content)) {
            $content = null;
        }

        $blocks = [];

        array_walk_recursive($this->customBlocks, static function (mixed $block) use (&$blocks): void {
            if (is_string($block)) {
                $blocks[] = $block;
            }
        });

        /** @var array<string, string|Htmlable|Closure|null> $mergeTags */
        $mergeTags = array_map(
            static fn (mixed $value): mixed => $value instanceof Closure || $value instanceof Htmlable || $value === null ? $value : (is_scalar($value) || $value instanceof Stringable ? (string) $value : null),
            $this->getMergeTagValues(),
        );

        return RichContentRenderer::make($content)
            ->customBlocks($blocks)
            ->mergeTags($mergeTags)
            ->plugins($this->plugins)
            ->mentions($this->mentions)
            ->linkProtocols($this->linkProtocols)
            ->fileAttachmentProvider($this->getFileAttachmentProvider())
            ->fileAttachments($this->fileAttachmentsDisk, $this->fileAttachmentsDirectory, $this->fileAttachmentsVisibility);
    }

    public function toHtml(): string
    {
        return $this->getRenderer()->toHtml();
    }

    public function __toString(): string
    {
        return $this->toHtml();
    }
}
