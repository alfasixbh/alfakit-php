<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Forms\Components;

use AlfaSix\Alfakit\Forms\Components\RichEditor\Concerns\HasEditorLayout;
use AlfaSix\Alfakit\Forms\Components\RichEditor\Concerns\HasRichContentExtensions;
use AlfaSix\Alfakit\Forms\Components\RichEditor\FileAttachmentProviders\Contracts\FileAttachmentProvider;
use AlfaSix\Alfakit\Forms\Components\RichEditor\FileAttachmentProviders\DiskFileAttachmentProvider;
use AlfaSix\Alfakit\Forms\Components\RichEditor\FileAttachmentUrls;
use AlfaSix\Alfakit\Forms\Components\RichEditor\MentionLabels;
use AlfaSix\Alfakit\Forms\Components\RichEditor\Plugins\Contracts\HasFileAttachmentProvider;
use AlfaSix\Alfakit\Support\Concerns\HasTemporaryUrlExpiry;
use AlfaSix\Alfakit\Support\Html;
use AlfaSix\Alfakit\Support\TemporaryUpload;
use AlfaSix\Alfakit\Support\Url;
use Closure;
use Illuminate\Database\Eloquent\Model;

class RichEditor extends Field
{
    use Concerns\CanBeTranslatable {
        hydrateState as hydrateTranslatableState;
    }
    use HasEditorLayout;
    use HasRichContentExtensions;
    use HasTemporaryUrlExpiry;

    /** The floating toolbar of grids when `floatingToolbars()` sets none (and `grid` is in the toolbar). */
    public const DEFAULT_GRID_FLOATING_TOOLBAR = ['gridAddColumnBefore', 'gridAddColumnAfter', 'gridDeleteColumn', 'gridDelete'];

    /** @var array<string|RichEditor\ToolbarButtonGroup>|Closure */
    protected array|Closure $toolbarButtons = [
        'bold', 'italic', 'underline', 'strike', 'link', '|',
        'h2', 'h3', 'blockquote', 'codeBlock', '|',
        'bulletList', 'orderedList', '|',
        'attachFiles', 'undo', 'redo',
    ];

    /** @var array<string>|Closure */
    protected array|Closure $disabledToolbarButtons = [];

    protected string|Closure|null $fileAttachmentsDisk = null;

    protected string|Closure|null $fileAttachmentsDirectory = null;

    protected string|Closure|null $fileAttachmentsVisibility = null;

    /** @var array<string>|Closure|null */
    protected array|Closure|null $fileAttachmentsAcceptedFileTypes = null;

    protected int|Closure|null $fileAttachmentsMaxSize = null;

    /** @var array<string, string>|Closure|null */
    protected array|Closure|null $textColors = null;

    protected bool|Closure|null $hasCustomTextColors = null;

    protected int|Closure|null $minHeight = null;

    protected int|Closure|null $maxHeight = null;

    protected bool|Closure $shouldPreventFileAttachmentTampering = true;

    protected ?Closure $allowFileAttachmentPathUsing = null;

    /** @var array<string>|Closure|null */
    protected array|Closure|null $linkProtocols = null;

    protected FileAttachmentProvider|Closure|null $fileAttachmentProvider = null;

    /** New attachments wait for the record (`isExistingRecordRequiredToSaveNewFileAttachments()`). */
    protected bool $hasDeferredFileAttachments = false;

    /** @var list<string>|null Attachment URLs of the last dehydrated content, for `cleanUpFileAttachments()`. */
    protected ?array $dehydratedFileAttachmentUrls = null;

    /**
     * Button names, `'|'` separators and `ToolbarButtonGroup`s (dropdowns). Nested arrays are
     * separated groups, as in Filament: `[['bold', 'italic'], ['h2', 'h3']]`.
     *
     * @param  array<string|RichEditor\ToolbarButtonGroup|array<string|RichEditor\ToolbarButtonGroup>>|Closure  $buttons
     */
    public function toolbarButtons(array|Closure $buttons): static
    {
        $this->toolbarButtons = $buttons;

        return $this;
    }

    /**
     * @param  array<string>|Closure  $buttons
     */
    public function disableToolbarButtons(array|Closure $buttons): static
    {
        $this->disabledToolbarButtons = $buttons;

        return $this;
    }

    public function disableAllToolbarButtons(): static
    {
        return $this->toolbarButtons([]);
    }

    public function fileAttachmentsDisk(string|Closure|null $disk): static
    {
        $this->fileAttachmentsDisk = $disk;

        return $this;
    }

    public function fileAttachmentsDirectory(string|Closure|null $directory): static
    {
        $this->fileAttachmentsDirectory = $directory;

        return $this;
    }

    public function fileAttachmentsVisibility(string|Closure|null $visibility): static
    {
        $this->fileAttachmentsVisibility = $visibility;

        return $this;
    }

    /**
     * MIME types an inserted image may have (`image/*` wildcards work). Checked on the
     * server against the detected type when the form is saved. SVG is never accepted:
     * it can carry script.
     *
     * @param  array<string>|Closure|null  $types
     */
    public function fileAttachmentsAcceptedFileTypes(array|Closure|null $types): static
    {
        $this->fileAttachmentsAcceptedFileTypes = $types;

        return $this;
    }

    /**
     * Maximum size of an inserted image in kilobytes.
     */
    public function fileAttachmentsMaxSize(int|Closure|null $kilobytes): static
    {
        $this->fileAttachmentsMaxSize = $kilobytes;

        return $this;
    }

    /**
     * The palette of the `textColor` toolbar button: CSS colour => label, e.g.
     * `['#dc2626' => 'Red']`. Defaults to {@see self::getDefaultTextColors()}.
     *
     * @param  array<string, string>|Closure|null  $colors
     */
    public function textColors(array|Closure|null $colors): static
    {
        $this->textColors = $colors;

        return $this;
    }

    /**
     * Let users pick any colour, not just the palette.
     */
    public function customTextColors(bool|Closure $condition = true): static
    {
        $this->hasCustomTextColors = $condition;

        return $this;
    }

    public function minHeight(int|Closure|null $pixels): static
    {
        $this->minHeight = $pixels;

        return $this;
    }

    public function maxHeight(int|Closure|null $pixels): static
    {
        $this->maxHeight = $pixels;

        return $this;
    }

    /**
     * When the form is saved, images that point at a file on the attachments disk are
     * kept only when the record's content (or the form's server-filled state) already
     * referenced that file, or when it was uploaded while editing. Others are removed, so
     * a crafted request can't make the content reference (and get signed URLs for)
     * someone else's attachment. This is on by default, like
     * `FileUpload::preventFilePathTampering()`. `allowFilePathUsing`
     * (`fn (string $file): bool`, the disk path) allows more files, e.g. a shared library;
     * `preventFileAttachmentTampering(false)` keeps every image.
     */
    public function preventFileAttachmentTampering(bool|Closure $condition = true, ?Closure $allowFilePathUsing = null): static
    {
        $this->shouldPreventFileAttachmentTampering = $condition;
        $this->allowFileAttachmentPathUsing = $allowFilePathUsing;

        return $this;
    }

    public function shouldPreventFileAttachmentTampering(): bool
    {
        return (bool) $this->evaluate($this->shouldPreventFileAttachmentTampering);
    }

    /**
     * Whether the content may reference the attachment at disk path `$file`: one the
     * stored content already references, or one `allowFilePathUsing` accepts.
     *
     * @param  array<string>|null  $originalPaths
     */
    public function isFileAttachmentPathAuthorized(string $file, ?array $originalPaths = null): bool
    {
        if (! $this->shouldPreventFileAttachmentTampering() || in_array($file, $originalPaths ?? $this->getOriginalFileAttachmentPaths(), true)) {
            return true;
        }

        return $this->allowFileAttachmentPathUsing !== null && (bool) $this->evaluate($this->allowFileAttachmentPathUsing, ['file' => $file, 'path' => $file]);
    }

    /**
     * Disk paths of the attachments the stored content references: the record's original
     * attribute and the form's server-filled state (which also covers Repeater items,
     * relationships and translations).
     *
     * @return list<string>
     */
    public function getOriginalFileAttachmentPaths(): array
    {
        $urls = $this->getFileAttachmentUrls();
        $record = $this->getRecord();
        $paths = $urls->paths($this->hasContainer() ? $this->getContainer()->getServerStateAt('') : null);

        if ($record !== null) {
            array_push($paths, ...$urls->paths($record->getOriginal($this->getName())));
        }

        return array_values(array_unique($paths));
    }

    /**
     * Remove images referencing attachments the content may not reference
     * ({@see self::preventFileAttachmentTampering()}). Only state sent by the browser is
     * checked: state the server filled itself is trusted.
     */
    protected function dropTamperedFileAttachments(mixed $state): mixed
    {
        if (! $this->shouldPreventFileAttachmentTampering() || ! $this->hasContainer() || $this->getContainer()->getServerStateAt('') === null) {
            return $state;
        }

        $original = null;

        return $this->getFileAttachmentUrls()->filter($state, function (string $path) use (&$original): bool {
            return $this->isFileAttachmentPathAuthorized($path, $original ??= $this->getOriginalFileAttachmentPaths());
        });
    }

    /**
     * The attachments disk and directory, whatever the visibility.
     */
    protected function getFileAttachmentUrls(): FileAttachmentUrls
    {
        return new FileAttachmentUrls($this->getFileAttachmentsDiskName(), $this->getFileAttachmentsDirectory(), $this->getTemporaryUrlExpiryMinutes());
    }

    /**
     * URL schemes links may use (`['https', 'mailto']`). Links with another scheme lose
     * their `href` when the content is saved, the editor won't create them, and
     * `RichContentRenderer` drops them. Relative links are always allowed. Null uses the
     * rich content attribute's, else the sanitiser's (http, https, mailto, tel).
     *
     * @param  array<string>|Closure|null  $protocols
     */
    public function linkProtocols(array|Closure|null $protocols): static
    {
        $this->linkProtocols = $protocols;

        return $this;
    }

    /**
     * @return list<string>|null
     */
    public function getLinkProtocols(): ?array
    {
        $protocols = $this->evaluate($this->linkProtocols);

        if (is_array($protocols)) {
            return RichEditor\RichContentRenderer::normalizeLinkProtocols($protocols);
        }

        return $this->hasContainer() ? $this->getRichContentAttribute()?->getLinkProtocols() : null;
    }

    /**
     * Remove the `href` of links whose scheme `linkProtocols()` doesn't allow (HTML and
     * JSON link marks).
     */
    protected function dropDisallowedLinks(mixed $state): mixed
    {
        $protocols = $this->getLinkProtocols();

        if ($protocols === null) {
            return $state;
        }

        if (is_string($state)) {
            return (string) preg_replace_callback('/<a\b[^>]*>/i', static function (array $tag) use ($protocols): string {
                if (preg_match('/\shref\s*=\s*(["\'])(.*?)\1/is', $tag[0], $href) !== 1 || Url::isSafe(html_entity_decode($href[2], ENT_QUOTES | ENT_HTML5), $protocols)) {
                    return $tag[0];
                }

                return str_replace($href[0], '', $tag[0]);
            }, $state);
        }

        if (! is_array($state)) {
            return $state;
        }

        // Translatable HTML: one string per locale.
        if (! $this->isJson()) {
            return array_map(fn (mixed $value): mixed => is_string($value) ? $this->dropDisallowedLinks($value) : $value, $state);
        }

        return $this->dropDisallowedJsonLinks($state, $protocols);
    }

    /**
     * @param  array<mixed>  $node
     * @param  list<string>  $protocols
     * @return array<mixed>
     */
    private function dropDisallowedJsonLinks(array $node, array $protocols): array
    {
        if (is_array($node['marks'] ?? null)) {
            $node['marks'] = array_values(array_filter($node['marks'], static fn (mixed $mark): bool => ! is_array($mark)
                || ($mark['type'] ?? null) !== 'link'
                || (is_string($mark['attrs']['href'] ?? null) && Url::isSafe($mark['attrs']['href'], $protocols))));
        }

        foreach ($node as $key => $value) {
            if ($key !== 'attrs' && $key !== 'marks' && is_array($value)) {
                $node[$key] = $this->dropDisallowedJsonLinks($value, $protocols);
            }
        }

        return $node;
    }

    /**
     * Store inserted images with a custom provider (e.g. a media library) instead of the
     * attachments disk. Null uses the rich content attribute's, then a plugin's
     * (`HasFileAttachmentProvider`), then the disk.
     */
    public function fileAttachmentProvider(FileAttachmentProvider|Closure|null $provider): static
    {
        $this->fileAttachmentProvider = $provider;

        return $this;
    }

    /**
     * The custom provider: the editor's, the rich content attribute's or a plugin's. Null
     * means the attachments disk ({@see self::getDefaultFileAttachmentProvider()}).
     */
    public function getFileAttachmentProvider(): ?FileAttachmentProvider
    {
        $provider = $this->evaluate($this->fileAttachmentProvider);

        if ($provider instanceof FileAttachmentProvider) {
            return $provider;
        }

        $attribute = $this->hasContainer() ? $this->getRichContentAttribute() : null;

        if (($provider = $attribute?->getFileAttachmentProvider()) !== null) {
            return $provider;
        }

        foreach ($this->getPlugins() as $plugin) {
            if ($plugin instanceof HasFileAttachmentProvider && ($provider = $plugin->getFileAttachmentProvider()) !== null) {
                return $attribute !== null ? $provider->attribute($attribute) : $provider;
            }
        }

        return null;
    }

    public function getDefaultFileAttachmentProvider(): FileAttachmentProvider
    {
        return DiskFileAttachmentProvider::make(
            $this->getFileAttachmentsDiskName(),
            $this->getFileAttachmentsDirectory(),
            $this->getFileAttachmentsVisibility(),
            $this->getTemporaryUrlExpiryMinutes(),
        );
    }

    public function getFileAttachmentsDiskName(): string
    {
        return $this->evaluate($this->fileAttachmentsDisk)
            ?? $this->getRichContentAttribute()?->getFileAttachmentsDisk()
            ?? config('alfakit.default_filesystem_disk', config('filesystems.default'));
    }

    public function getFileAttachmentsDirectory(): ?string
    {
        // `Panel::tenantUploadDirectory()` keeps each tenant's files in its own folder.
        return alfakit()->getCurrentOrDefaultPanel()->applyTenantUploadDirectory(
            $this->evaluate($this->fileAttachmentsDirectory) ?? $this->getRichContentAttribute()?->getFileAttachmentsDirectory(),
        );
    }

    public function getFileAttachmentsVisibility(): string
    {
        if (($visibility = $this->evaluate($this->fileAttachmentsVisibility) ?? $this->getRichContentAttribute()?->getFileAttachmentsVisibility()) !== null) {
            return $visibility;
        }

        if (($visibility = $this->getFileAttachmentProvider()?->getDefaultFileAttachmentVisibility()) !== null) {
            return $visibility;
        }

        // Disks configured as private (`'visibility' => 'private'`) default to it.
        $config = (array) config("filesystems.disks.{$this->getFileAttachmentsDiskName()}", []);

        // As FileUpload: a local disk with no public `url` is private (signed URLs).
        if (isset($config['visibility']) && is_string($config['visibility'])) {
            return $config['visibility'] === 'private' ? 'private' : 'public';
        }

        return ($config['driver'] ?? null) === 'local' && empty($config['url']) ? 'private' : 'public';
    }

    /**
     * @return array<string>
     */
    public function getFileAttachmentsAcceptedFileTypes(): array
    {
        return $this->evaluate($this->fileAttachmentsAcceptedFileTypes) ?? ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];
    }

    public function getFileAttachmentsMaxSize(): int
    {
        return (int) ($this->evaluate($this->fileAttachmentsMaxSize) ?? config('alfakit.uploads.max_size_kb', 12288));
    }

    /**
     * Tailwind's 600 shades, which read on light and dark backgrounds alike.
     *
     * @return array<string, string>
     */
    public static function getDefaultTextColors(): array
    {
        $colors = [
            'red' => '#dc2626', 'orange' => '#ea580c', 'amber' => '#d97706', 'green' => '#16a34a',
            'teal' => '#0d9488', 'blue' => '#2563eb', 'indigo' => '#4f46e5', 'purple' => '#9333ea',
            'pink' => '#db2777', 'gray' => '#4b5563',
        ];

        $palette = [];

        foreach ($colors as $name => $color) {
            $palette[$color] = __("alfakit::forms.rich_editor.text_colors.{$name}");
        }

        return $palette;
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public function getTextColors(): array
    {
        $colors = [];

        foreach ($this->evaluate($this->textColors) ?? $this->getRichContentAttribute()?->getTextColors() ?? self::getDefaultTextColors() as $color => $label) {
            // Only colours the sanitiser keeps (`#rgb`, `#rrggbb`, `rgb()`, `hsl()`, names).
            if (Html::isSafeColor((string) $color)) {
                $colors[] = ['value' => (string) $color, 'label' => (string) $label];
            }
        }

        return $colors;
    }

    protected function getTypeValidationRules(): array
    {
        return [$this->isJson() ? 'array' : 'string', function (string $attribute, mixed $value, Closure $fail): void {
            if (($error = $this->findFileAttachmentError($value)) !== null) {
                $fail($error);
            }
        }];
    }

    /**
     * The first reason an image inserted while editing can't be stored (wrong type or too
     * large), or null. Unknown and expired keys are not errors: they are dropped on save.
     */
    protected function findFileAttachmentError(mixed $state): ?string
    {
        $content = is_string($state) ? $state : (json_encode($state) ?: '');

        if (! str_contains($content, TemporaryUpload::PREFIX)) {
            return null;
        }

        preg_match_all('/tmp:[0-9A-Za-z.]+/', $content, $matches);

        foreach (array_unique($matches[0]) as $key) {
            $upload = TemporaryUpload::find($key);

            if ($upload !== null && ($error = $this->getFileAttachmentError($upload)) !== null) {
                return $error;
            }
        }

        return null;
    }

    /**
     * Why an uploaded file can't be inserted as an image, or null when it can.
     */
    public function getFileAttachmentError(TemporaryUpload $upload): ?string
    {
        $mime = strtolower($upload->mimeType());

        // Only raster images: SVG can carry script.
        if (! str_starts_with($mime, 'image/') || str_starts_with($mime, 'image/svg')) {
            return __('alfakit::forms.file_upload.invalid_type');
        }

        $accepted = false;

        foreach ($this->getFileAttachmentsAcceptedFileTypes() as $type) {
            $type = strtolower(trim($type));

            if ($type === $mime || $type === '*/*' || (str_ends_with($type, '/*') && str_starts_with($mime, substr($type, 0, -1)))) {
                $accepted = true;

                break;
            }
        }

        if (! $accepted) {
            return __('alfakit::forms.file_upload.invalid_type');
        }

        if ((int) ceil($upload->size() / 1024) > $this->getFileAttachmentsMaxSize()) {
            return __('alfakit::forms.file_upload.too_large');
        }

        return null;
    }

    /**
     * Private attachments are stored with the disk's plain URL; the editor gets temporary
     * URLs it can load (see `FileAttachmentUrls`).
     */
    public function hydrateState(bool $useDefaults): void
    {
        $this->hydrateTranslatableState($useDefaults);
        $this->hydrateMentionLabels();

        if (($urls = $this->getPrivateFileAttachmentUrls()) !== null) {
            $this->getContainer()->setStateAt($this->getStatePath(), $urls->sign($this->getState()));
        }
    }

    /**
     * Mentions show their provider's current label in the editor
     * (`MentionProvider::getLabelsUsing()`), else the stored one.
     */
    protected function hydrateMentionLabels(): void
    {
        if ($this instanceof MarkdownEditor || ($providers = array_values($this->getMentionProviders())) === []) {
            return;
        }

        $state = $this->getState();
        $apply = static fn (mixed $content): mixed => is_string($content) || is_array($content)
            ? MentionLabels::for($providers, $content)->apply($content)
            : $content;

        // Translatable fields hold one HTML string per locale.
        $labelled = is_array($state) && ! $this->isJson()
            ? array_map($apply, $state)
            : $apply($state);

        if ($labelled !== $state) {
            $this->getContainer()->setStateAt($this->getStatePath(), $labelled);
        }
    }

    protected function getPrivateFileAttachmentUrls(): ?FileAttachmentUrls
    {
        if ($this->getFileAttachmentsVisibility() !== 'private') {
            return null;
        }

        return new FileAttachmentUrls($this->getFileAttachmentsDiskName(), $this->getFileAttachmentsDirectory(), $this->getTemporaryUrlExpiryMinutes());
    }

    /**
     * Move images inserted while editing (referenced by their `tmp:` upload key) to the
     * attachments disk and point them at the stored file's URL. Keys that are malformed,
     * expired, owned by someone else or not images are removed with their image, so the
     * saved content never references a temporary file.
     */
    public function storeFileAttachments(string $content): string
    {
        if (! str_contains($content, TemporaryUpload::PREFIX) || $this->shouldDeferFileAttachments()) {
            return $content;
        }

        /** @var array<string, string|null> $stored */
        $stored = [];
        $resolve = function (string $key) use (&$stored): ?string {
            return $stored[$key] ??= $this->storeFileAttachment($key);
        };

        // HTML: <img src="tmp:…">
        $content = (string) preg_replace_callback('/<img\b[^>]*>/i', static function (array $tag) use ($resolve): string {
            if (preg_match('/\bsrc\s*=\s*(["\'])(tmp:[^"\']*)\1/i', $tag[0], $src, PREG_OFFSET_CAPTURE) !== 1) {
                return $tag[0];
            }

            $url = $resolve(html_entity_decode($src[2][0], ENT_QUOTES | ENT_HTML5));

            if ($url === null) {
                return '';
            }

            return substr_replace($tag[0], htmlspecialchars($url, ENT_QUOTES | ENT_HTML5), $src[2][1], strlen($src[2][0]));
        }, $content);

        // Markdown: ![alt](tmp:…)
        return (string) preg_replace_callback('/!\[([^\]]*)\]\((tmp:[^)\s]*)\)/', static function (array $match) use ($resolve): string {
            $url = $resolve($match[2]);

            return $url === null ? '' : '!['.$match[1].']('.$url.')';
        }, $content);
    }

    protected function storeFileAttachment(string $key): ?string
    {
        $upload = TemporaryUpload::find($key);

        if ($upload === null) {
            return null;
        }

        if ($this->getFileAttachmentError($upload) !== null) {
            return null;
        }

        $url = ($this->getFileAttachmentProvider() ?? $this->getDefaultFileAttachmentProvider())->saveUploadedFileAttachment($upload);

        if ($url !== null) {
            $upload->delete();
        }

        return $url;
    }

    /**
     * A provider that needs the record (a media library) stores new images once it exists:
     * on a create form, right after the record is created ({@see self::saveRelationships()}).
     */
    protected function shouldDeferFileAttachments(): bool
    {
        $provider = $this->getFileAttachmentProvider();

        if ($provider === null || ! $provider->isExistingRecordRequiredToSaveNewFileAttachments()) {
            return false;
        }

        $record = $this->getRecord();

        if ($record instanceof Model && $record->exists) {
            return false;
        }

        return $this->hasDeferredFileAttachments = true;
    }

    /**
     * After the record was saved: store images deferred until it existed (and save their
     * URLs), then let the provider clean up attachments the content no longer references.
     */
    public function saveRelationships(): void
    {
        parent::saveRelationships();

        $record = $this->getRecord();
        $provider = $this->getFileAttachmentProvider();

        if ($this->hasDeferredFileAttachments && $record instanceof Model && $record->exists) {
            $this->hasDeferredFileAttachments = false;
            $name = (string) $this->getName();
            $content = $record->getAttribute($name);
            $stored = match (true) {
                is_string($content) && ! $this->isJson() => $this->storeFileAttachments($content),
                is_array($content) => $this->storeJsonFileAttachments($content),
                default => $content,
            };

            if ($stored !== $content) {
                $record->setAttribute($name, $stored)->saveQuietly();
                $this->dehydratedFileAttachmentUrls = $this->collectImageUrls($stored);
            }
        }

        if ($provider !== null && $this->dehydratedFileAttachmentUrls !== null) {
            $provider->cleanUpFileAttachments($this->dehydratedFileAttachmentUrls);
        }

        $this->dehydratedFileAttachmentUrls = null;
    }

    /**
     * With a custom provider, the image URLs of the content being saved, for
     * `cleanUpFileAttachments()` once the record is saved.
     */
    protected function rememberFileAttachmentUrls(mixed $content): void
    {
        if ($this->getFileAttachmentProvider() !== null) {
            $this->dehydratedFileAttachmentUrls = $this->collectImageUrls($content);
        }
    }

    /**
     * Every image URL the content references (HTML, Markdown and JSON image nodes).
     *
     * @return list<string>
     */
    protected function collectImageUrls(mixed $content): array
    {
        $urls = [];

        if (is_string($content)) {
            preg_match_all('/<img\b[^>]*\bsrc\s*=\s*(["\'])([^"\']*)\1/i', $content, $html);
            preg_match_all('/!\[[^\]]*\]\(([^)\s]+)\)/', $content, $markdown);

            foreach ([...$html[2], ...$markdown[1]] as $url) {
                $urls[] = html_entity_decode($url, ENT_QUOTES | ENT_HTML5);
            }
        } elseif (is_array($content)) {
            if (($content['type'] ?? null) === 'image' && is_string($content['attrs']['src'] ?? null)) {
                $urls[] = $content['attrs']['src'];
            }

            foreach ($content as $key => $value) {
                if ($key !== 'attrs') {
                    array_push($urls, ...$this->collectImageUrls($value));
                }
            }
        }

        return array_values(array_unique($urls));
    }

    protected function mutateDehydratedState(mixed $state): mixed
    {
        if ($state === null) {
            return null;
        }

        $state = $this->getPrivateFileAttachmentUrls()?->unsign($state) ?? $state;

        if ($this->isJson()) {
            // An uncast attribute holds the document as a JSON string.
            if (is_string($state) && is_array($decoded = json_decode($state, true))) {
                $state = $decoded;
            }

            $document = $this->dehydrateJsonContent($this->dropTamperedFileAttachments($state));
            $document = $document === null ? null : $this->dropDisallowedLinks($document);
            $this->rememberFileAttachmentUrls($document);

            return $document;
        }

        $state = $this->dropTamperedFileAttachments($state);

        $state = $this->dropDisallowedLinks($state);

        if (is_string($state)) {
            $state = $this->storeFileAttachments($state);
        }

        $this->rememberFileAttachmentUrls($state);

        $stripped = trim(strip_tags((string) $state));

        // Images and custom blocks have no text of their own.
        return $stripped === '' && ! str_contains((string) $state, '<img') && ! str_contains((string) $state, 'data-type="customBlock"') ? null : $state;
    }

    protected function getSchemaProps(): array
    {
        $disabled = $this->evaluate($this->disabledToolbarButtons) ?? [];
        $groups = [];
        $buttons = $this->serializeToolbarButtons($this->withExtensionToolbarButtons($this->evaluate($this->toolbarButtons) ?? []), $disabled, $groups);
        $floatingToolbars = $this->getFloatingToolbarsSchema($disabled, $groups);

        // Like Filament, grids get column controls next to them unless configured otherwise.
        if (in_array('grid', [...$buttons, ...array_merge([], ...array_column($groups, 'buttons'))], true) && ! isset($floatingToolbars['grid'])) {
            $gridButtons = array_values(array_diff(self::DEFAULT_GRID_FLOATING_TOOLBAR, $disabled));

            if ($gridButtons !== []) {
                $floatingToolbars = [...($floatingToolbars ?? []), 'grid' => $gridButtons];
            }
        }
        $allButtons = [...$buttons, ...array_merge([], ...array_values($floatingToolbars ?? [])), ...array_merge([], ...array_column($groups, 'buttons'))];
        $hasTextColor = in_array('textColor', $allButtons, true);
        $customTextColors = $this->evaluate($this->hasCustomTextColors) ?? $this->getRichContentAttribute()?->hasCustomTextColors() ?? false;

        return [
            'toolbarButtons' => $buttons,
            'toolbarButtonGroups' => $groups ?: null,
            'floatingToolbars' => $floatingToolbars,
            'fileAttachments' => [
                'disk' => $this->getFileAttachmentsDiskName(),
                'directory' => $this->getFileAttachmentsDirectory(),
                'acceptedFileTypes' => $this->getFileAttachmentsAcceptedFileTypes(),
                'maxSize' => $this->getFileAttachmentsMaxSize(),
                'uploadPolicy' => \AlfaSix\Alfakit\Support\UploadPolicy::token($this->getFileAttachmentsMaxSize(), $this->getFileAttachmentsAcceptedFileTypes()),
            ],
            'textColors' => $hasTextColor ? $this->getTextColors() : null,
            'customTextColors' => $hasTextColor ? ($customTextColors ?: null) : null,
            'linkProtocols' => $this->getLinkProtocols(),
            'minHeight' => $this->evaluate($this->minHeight),
            'maxHeight' => $this->evaluate($this->maxHeight),
            ...$this->getEditorLayoutSchemaProps(),
            ...$this->getRichContentSchemaProps(),
        ];
    }
}
