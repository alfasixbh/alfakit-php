<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Forms\Components;

use AlfaSix\Alfakit\Support\Concerns\HasTemporaryUrlExpiry;
use AlfaSix\Alfakit\Support\TemporaryUpload;
use AlfaSix\Alfakit\Support\TemporaryUrls;
use AlfaSix\Alfakit\Support\Url;
use Closure;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * File uploads happen in two steps: the renderer streams files to the panel's upload
 * endpoint, which stores them on the temporary disk and returns a `tmp:` token. When the
 * form is submitted, tokens are moved to their final disk and directory.
 */
class FileUpload extends Field
{
    use HasTemporaryUrlExpiry;

    public const TEMPORARY_PREFIX = TemporaryUpload::PREFIX;

    /**
     * The default lifetime of signed preview and download URLs of private files.
     *
     * @deprecated Use `config('alfakit.temporary_file_url_expiry_minutes')` or `temporaryUrlExpiry()`.
     */
    public const TEMPORARY_URL_MINUTES = 30;

    /** Detected types `mimeTypeMap()` may override with the type of the file's extension. */
    public const GENERIC_MIME_TYPES = ['application/octet-stream', 'application/zip', 'text/plain', 'application/x-empty'];

    /**
     * Short names shown in the drop zone for common accepted types; other types fall back
     * to their upper-cased subtype (`application/x-rar` is "RAR").
     */
    protected const FILE_TYPE_LABELS = [
        'image/png' => 'PNG',
        'image/jpeg' => 'JPG',
        'image/jpg' => 'JPG',
        'image/pjpeg' => 'JPG',
        'image/webp' => 'WebP',
        'image/gif' => 'GIF',
        'image/svg+xml' => 'SVG',
        'image/x-icon' => 'ICO',
        'image/vnd.microsoft.icon' => 'ICO',
        'image/avif' => 'AVIF',
        'image/heic' => 'HEIC',
        'image/bmp' => 'BMP',
        'image/tiff' => 'TIFF',
        'application/pdf' => 'PDF',
        'application/zip' => 'ZIP',
        'application/json' => 'JSON',
        'text/plain' => 'TXT',
        'text/csv' => 'CSV',
        'text/markdown' => 'Markdown',
        'application/msword' => 'DOC',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'DOCX',
        'application/vnd.ms-excel' => 'XLS',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'XLSX',
        'application/vnd.oasis.opendocument.text' => 'ODT',
        'application/vnd.oasis.opendocument.spreadsheet' => 'ODS',
        'application/vnd.oasis.opendocument.presentation' => 'ODP',
        'application/vnd.ms-powerpoint' => 'PPT',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'PPTX',
        'audio/mpeg' => 'MP3',
        'video/mp4' => 'MP4',
        'video/quicktime' => 'MOV',
    ];

    protected string|Closure|null $disk = null;

    protected string|Closure|null $directory = null;

    protected string|Closure|null $visibility = null;

    protected bool|Closure $isMultiple = false;

    protected bool|Closure $isImage = false;

    protected bool|Closure $isAvatar = false;

    /** @var array<string>|Closure|null */
    protected array|Closure|null $acceptedFileTypes = null;

    protected int|Closure|null $maxSize = null;

    protected int|Closure|null $minSize = null;

    protected int|Closure|null $maxFiles = null;

    protected int|Closure|null $minFiles = null;

    protected bool|Closure $shouldPreserveFilenames = false;

    protected ?Closure $getUploadedFileNameForStorageUsing = null;

    protected bool|Closure $isDownloadable = false;

    protected bool|Closure $isOpenable = false;

    protected bool|Closure $isPreviewable = true;

    protected bool|Closure $isReorderable = false;

    protected bool|Closure $isDeletable = true;

    protected bool|Closure $shouldDeleteRemovedFiles = false;

    protected string|Closure $panelLayout = 'compact';

    protected int|string|Closure|null $imagePreviewHeight = null;

    protected string|Closure|null $imageCropAspectRatio = null;

    protected string|Closure|null $imageResizeMode = null;

    protected int|Closure|null $imageResizeTargetWidth = null;

    protected int|Closure|null $imageResizeTargetHeight = null;

    protected string|Closure|null $uploadingMessage = null;

    protected bool|Closure $shouldStoreFiles = true;

    protected bool|Closure $hasImageEditor = false;

    /** @var array<string|null>|Closure|null */
    protected array|Closure|null $imageEditorAspectRatios = null;

    protected bool|Closure $hasCircleCropper = false;

    protected int|Closure|null $imageEditorMode = null;

    protected string|Closure|null $imageEditorEmptyFillColor = null;

    protected int|string|Closure|null $imageEditorViewportWidth = null;

    protected int|string|Closure|null $imageEditorViewportHeight = null;

    /** @var array<string>|string|Closure|null */
    protected array|string|Closure|null $imageAspectRatio = null;

    /** @var array{minWidth: int|Closure|null, minHeight: int|Closure|null, maxWidth: int|Closure|null, maxHeight: int|Closure|null} */
    protected array $imageDimensions = ['minWidth' => null, 'minHeight' => null, 'maxWidth' => null, 'maxHeight' => null];

    protected bool|Closure $shouldOrientImagesFromExif = false;

    protected bool|Closure $isPasteable = true;

    protected int|Closure|null $maxParallelUploads = null;

    protected string|Closure|null $fileNamesStatePath = null;

    protected bool|Closure $shouldPreventFilePathTampering = true;

    protected ?Closure $allowFilePathUsing = null;

    protected string|Closure|null $panelAspectRatio = null;

    protected string|Closure|null $loadingIndicatorPosition = null;

    protected string|Closure|null $removeUploadedFileButtonPosition = null;

    protected string|Closure|null $uploadButtonPosition = null;

    protected string|Closure|null $uploadProgressIndicatorPosition = null;

    protected bool|Closure $shouldAppendFiles = true;

    protected ?Closure $getOpenableFileUrlUsing = null;

    protected ?Closure $getDownloadableFileUrlUsing = null;

    protected bool|Closure $shouldFetchFileInformation = true;

    /** @var array<string, string>|Closure */
    protected array|Closure $mimeTypeMap = [];

    /**
     * Original names of the files stored by the last `dehydrateState()`, keyed by path
     * (`storeFileNamesIn()`).
     *
     * @var array<string, string>|null
     */
    protected ?array $dehydratedFileNames = null;

    /**
     * Stored paths the field held before the request's data was applied. Only these (and
     * the record's) may be submitted back; anything else must be a fresh upload.
     *
     * @var array<string>
     */
    protected array $hydratedFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        // `$component`, not `$this`: Repeater items dehydrate clones of the field.
        $this->dehydrateStateUsing(static fn (FileUpload $component, mixed $state): mixed => $component->storeUploadedFiles($state));
    }

    public function disk(string|Closure|null $disk): static
    {
        $this->disk = $disk;

        return $this;
    }

    public function directory(string|Closure|null $directory): static
    {
        $this->directory = $directory;

        return $this;
    }

    public function visibility(string|Closure|null $visibility): static
    {
        $this->visibility = $visibility;

        return $this;
    }

    public function multiple(bool|Closure $condition = true): static
    {
        $this->isMultiple = $condition;

        return $this;
    }

    public function image(bool|Closure $condition = true): static
    {
        $this->isImage = $condition;

        return $this;
    }

    public function avatar(): static
    {
        $this->isAvatar = true;

        return $this->image()->imageCropAspectRatio('1:1')->panelLayout('circle');
    }

    /**
     * @param  array<string>|Closure|null  $types  MIME types, e.g. `['image/png', 'application/pdf']`.
     */
    public function acceptedFileTypes(array|Closure|null $types): static
    {
        $this->acceptedFileTypes = $types;

        return $this;
    }

    /**
     * Maximum size per file in kilobytes.
     */
    public function maxSize(int|Closure|null $kilobytes): static
    {
        $this->maxSize = $kilobytes;

        return $this;
    }

    public function minSize(int|Closure|null $kilobytes): static
    {
        $this->minSize = $kilobytes;

        return $this;
    }

    public function maxFiles(int|Closure|null $count): static
    {
        $this->maxFiles = $count;

        return $this;
    }

    public function minFiles(int|Closure|null $count): static
    {
        $this->minFiles = $count;

        return $this;
    }

    public function preserveFilenames(bool|Closure $condition = true): static
    {
        $this->shouldPreserveFilenames = $condition;

        return $this;
    }

    /**
     * `fn (string $originalName, string $extension): string`
     */
    public function getUploadedFileNameForStorageUsing(?Closure $callback): static
    {
        $this->getUploadedFileNameForStorageUsing = $callback;

        return $this;
    }

    public function downloadable(bool|Closure $condition = true): static
    {
        $this->isDownloadable = $condition;

        return $this;
    }

    public function openable(bool|Closure $condition = true): static
    {
        $this->isOpenable = $condition;

        return $this;
    }

    public function previewable(bool|Closure $condition = true): static
    {
        $this->isPreviewable = $condition;

        return $this;
    }

    public function reorderable(bool|Closure $condition = true): static
    {
        $this->isReorderable = $condition;

        return $this;
    }

    public function deletable(bool|Closure $condition = true): static
    {
        $this->isDeletable = $condition;

        return $this;
    }

    /**
     * Delete previously stored files from disk when they are removed from the field.
     */
    public function deleteRemovedFiles(bool|Closure $condition = true): static
    {
        $this->shouldDeleteRemovedFiles = $condition;

        return $this;
    }

    public function panelLayout(string|Closure $layout): static
    {
        $this->panelLayout = $layout;

        return $this;
    }

    /**
     * Height of image previews in pixels (`250` or Filament's `'250px'`).
     */
    public function imagePreviewHeight(int|string|Closure|null $height): static
    {
        $this->imagePreviewHeight = $height;

        return $this;
    }

    public function imageCropAspectRatio(string|Closure|null $ratio): static
    {
        $this->imageCropAspectRatio = $ratio;

        return $this;
    }

    public function imageResizeMode(string|Closure|null $mode): static
    {
        $this->imageResizeMode = $mode;

        return $this;
    }

    public function imageResizeTargetWidth(int|Closure|null $width): static
    {
        $this->imageResizeTargetWidth = $width;

        return $this;
    }

    public function imageResizeTargetHeight(int|Closure|null $height): static
    {
        $this->imageResizeTargetHeight = $height;

        return $this;
    }

    /**
     * Filament v4 name for {@see self::imageCropAspectRatio()}. Without an argument it uses
     * the first `imageAspectRatio()`.
     */
    public function automaticallyCropImagesToAspectRatio(string|Closure|null $ratio = null): static
    {
        return $this->imageCropAspectRatio($ratio ?? fn (): ?string => $this->getImageAspectRatios()[0] ?? null);
    }

    /**
     * Filament v4 name for {@see self::imageResizeMode()} (`'force'`, `'cover'` or `'contain'`).
     */
    public function automaticallyResizeImagesMode(string|Closure|null $mode): static
    {
        return $this->imageResizeMode($mode);
    }

    /**
     * Filament v4 name for {@see self::imageResizeTargetWidth()}.
     */
    public function automaticallyResizeImagesToWidth(int|string|Closure|null $width): static
    {
        return $this->imageResizeTargetWidth(is_string($width) ? (int) $width : $width);
    }

    /**
     * Filament v4 name for {@see self::imageResizeTargetHeight()}.
     */
    public function automaticallyResizeImagesToHeight(int|string|Closure|null $height): static
    {
        return $this->imageResizeTargetHeight(is_string($height) ? (int) $height : $height);
    }

    public function uploadingMessage(string|Closure|null $message): static
    {
        $this->uploadingMessage = $message;

        return $this;
    }

    /**
     * Open a crop / zoom / rotate / flip editor (with numeric position, size and rotation
     * inputs) for each image before it is uploaded, and an "Edit" button on uploaded images
     * that replaces the file with the edited one.
     */
    public function imageEditor(bool|Closure $condition = true): static
    {
        $this->hasImageEditor = $condition;

        return $this;
    }

    /**
     * The aspect ratios the image editor offers, e.g. `['16:9', '4:3', '1:1', null]`;
     * `null` is free cropping. Defaults to `imageAspectRatio()`, else free cropping.
     *
     * @param  array<string|null>|Closure|null  $ratios
     */
    public function imageEditorAspectRatios(array|Closure|null $ratios): static
    {
        $this->imageEditorAspectRatios = $ratios;

        return $this;
    }

    /**
     * Filament's name for {@see self::imageEditorAspectRatios()}.
     *
     * @param  array<string|null>|Closure|null  $ratios
     */
    public function imageEditorAspectRatioOptions(array|Closure|null $ratios): static
    {
        return $this->imageEditorAspectRatios($ratios);
    }

    /**
     * Cropper.js-style view mode: `0` lets the crop box leave the image (the empty space
     * is transparent or `imageEditorEmptyFillColor()`); `1` (default) keeps it inside the
     * image; `2` also stops the image being zoomed out below fitting the editor; `3` makes
     * the image always fill the editor.
     */
    public function imageEditorMode(int|Closure|null $mode): static
    {
        $this->imageEditorMode = $mode;

        return $this;
    }

    /**
     * Colour of the empty space around the image in the editor output (`'#000000'`);
     * transparent by default. Only visible with `imageEditorMode(0)`.
     */
    public function imageEditorEmptyFillColor(string|Closure|null $color): static
    {
        $this->imageEditorEmptyFillColor = $color;

        return $this;
    }

    /**
     * With `imageEditorViewportHeight()`: the crop ratio the editor opens with
     * (`1920` × `1080` opens a 16:9 crop).
     */
    public function imageEditorViewportWidth(int|string|Closure|null $width): static
    {
        $this->imageEditorViewportWidth = $width;

        return $this;
    }

    public function imageEditorViewportHeight(int|string|Closure|null $height): static
    {
        $this->imageEditorViewportHeight = $height;

        return $this;
    }

    /**
     * Crop images with a round viewport (square output), for avatars.
     */
    public function circleCropper(bool|Closure $condition = true): static
    {
        $this->hasCircleCropper = $condition;

        return $this;
    }

    /**
     * Only accept images with this aspect ratio (or one of these), e.g. `'16:9'` or
     * `['16:9', '4:3']`. Checked on the server against the file's pixels.
     *
     * @param  array<string>|string|Closure|null  $ratio
     */
    public function imageAspectRatio(array|string|Closure|null $ratio): static
    {
        $this->imageAspectRatio = $ratio;

        return $this;
    }

    /**
     * Pixel limits for uploaded images, checked on the server against the file's pixels.
     */
    public function imageDimensions(
        int|Closure|null $minWidth = null,
        int|Closure|null $minHeight = null,
        int|Closure|null $maxWidth = null,
        int|Closure|null $maxHeight = null,
    ): static {
        $this->imageDimensions = ['minWidth' => $minWidth, 'minHeight' => $minHeight, 'maxWidth' => $maxWidth, 'maxHeight' => $maxHeight];

        return $this;
    }

    /**
     * Rotate JPEG photos upright from their EXIF orientation when they are stored (with
     * GD and the `exif` extension; otherwise the browser re-encodes them before upload).
     */
    public function orientImagesFromExif(bool|Closure $condition = true): static
    {
        $this->shouldOrientImagesFromExif = $condition;

        return $this;
    }

    /**
     * Accept files pasted from the clipboard while the drop zone has focus (default on).
     */
    public function pasteable(bool|Closure $condition = true): static
    {
        $this->isPasteable = $condition;

        return $this;
    }

    /**
     * How many files of one selection upload at the same time (default 1: one after the
     * other, in the order they were picked).
     */
    public function maxParallelUploads(int|Closure|null $count): static
    {
        $this->maxParallelUploads = $count;

        return $this;
    }

    /**
     * Save the original names of the uploaded files to another attribute of the same
     * record (or Repeater item), e.g. `attachment_file_names`, while the field stores
     * generated names. Multiple fields store `[path => original name]` (cast the
     * attribute to `array`), single fields the name. The names are shown in the field.
     */
    public function storeFileNamesIn(string|Closure|null $statePath): static
    {
        $this->fileNamesStatePath = $statePath;

        return $this;
    }

    /**
     * Stored paths submitted back must be ones the field was filled with or the record
     * holds; anything else is rejected (`validationMessages(['tampered' => …])`). This is
     * on by default. `allowFilePathUsing` (`fn (string $file): bool`) allows more paths,
     * e.g. a shared library of files; `preventFilePathTampering(false)` accepts any path.
     */
    public function preventFilePathTampering(bool|Closure $condition = true, ?Closure $allowFilePathUsing = null): static
    {
        $this->shouldPreventFilePathTampering = $condition;
        $this->allowFilePathUsing = $allowFilePathUsing;

        return $this;
    }

    /**
     * Aspect ratio of the drop zone panel, e.g. `'2:1'`.
     */
    public function panelAspectRatio(string|Closure|null $ratio): static
    {
        $this->panelAspectRatio = $ratio;

        return $this;
    }

    /**
     * Where the upload spinner sits in a file's row: `'left'` (the inline start) or
     * `'right'` (the end, default). AlfaKit shows one indicator; see
     * `uploadProgressIndicatorPosition()`.
     */
    public function loadingIndicatorPosition(string|Closure|null $position): static
    {
        $this->loadingIndicatorPosition = $position;

        return $this;
    }

    /**
     * Where the remove button sits in a file's row: `'left'` (the inline start) or
     * `'right'` (the end, default).
     */
    public function removeUploadedFileButtonPosition(string|Closure|null $position): static
    {
        $this->removeUploadedFileButtonPosition = $position;

        return $this;
    }

    /**
     * Alignment of the drop zone's content: `'left'` (start), `'center'` (default) or
     * `'right'` (end).
     */
    public function uploadButtonPosition(string|Closure|null $position): static
    {
        $this->uploadButtonPosition = $position;

        return $this;
    }

    /**
     * Where the upload progress spinner sits in a file's row: `'left'` (the inline start)
     * or `'right'` (the end, default). Takes precedence over `loadingIndicatorPosition()`.
     */
    public function uploadProgressIndicatorPosition(string|Closure|null $position): static
    {
        $this->uploadProgressIndicatorPosition = $position;

        return $this;
    }

    /**
     * Add new uploads after the existing files (the default); `appendFiles(false)` puts
     * them first.
     */
    public function appendFiles(bool|Closure $condition = true): static
    {
        $this->shouldAppendFiles = $condition;

        return $this;
    }

    /**
     * The URL the "open" button links to, `fn (string $file): ?string`; null keeps the
     * preview URL.
     */
    public function getOpenableFileUrlUsing(?Closure $callback): static
    {
        $this->getOpenableFileUrlUsing = $callback;

        return $this;
    }

    /**
     * The URL the "download" button links to, `fn (string $file): ?string`; null keeps
     * the preview URL.
     */
    public function getDownloadableFileUrlUsing(?Closure $callback): static
    {
        $this->getDownloadableFileUrlUsing = $callback;

        return $this;
    }

    /**
     * Look up the size of stored files on the disk when the form is rendered and flag
     * files that no longer exist (default on). `fetchFileInformation(false)` skips the
     * disk calls (useful on slow remote disks); files then show their name only.
     */
    public function fetchFileInformation(bool|Closure $condition = true): static
    {
        $this->shouldFetchFileInformation = $condition;

        return $this;
    }

    /**
     * MIME types for extensions the server can't detect, `['skp' => 'application/vnd.sketchup.skp']`.
     * A file with such an extension whose content is detected as a generic type
     * (`application/octet-stream`, `application/zip`, `text/plain`) is treated as the
     * mapped type by `acceptedFileTypes()` and keeps its extension when stored.
     *
     * @param  array<string, string>|Closure  $map
     */
    public function mimeTypeMap(array|Closure $map): static
    {
        $this->mimeTypeMap = $map;

        return $this;
    }

    /**
     * Skip moving temporary files on submit; the state will hold the `tmp:` tokens.
     */
    public function storeFiles(bool|Closure $condition = true): static
    {
        $this->shouldStoreFiles = $condition;

        return $this;
    }

    // ---------------------------------------------------------------- Resolution

    public function isMultiple(): bool
    {
        return (bool) $this->evaluate($this->isMultiple);
    }

    public function getDiskName(): string
    {
        return $this->evaluate($this->disk) ?? config('alfakit.default_filesystem_disk', config('filesystems.default'));
    }

    public function getDisk(): Filesystem
    {
        return Storage::disk($this->getDiskName());
    }

    public function getDirectory(): ?string
    {
        // `Panel::tenantUploadDirectory()` keeps each tenant's files in its own folder.
        return alfakit()->getCurrentOrDefaultPanel()->applyTenantUploadDirectory($this->evaluate($this->directory));
    }

    public function getVisibility(): string
    {
        if (($visibility = $this->evaluate($this->visibility)) !== null) {
            return $visibility;
        }

        return TemporaryUrls::defaultVisibility($this->getDiskName());
    }

    /**
     * @return array<string>|null
     */
    public function getAcceptedFileTypes(): ?array
    {
        $types = $this->evaluate($this->acceptedFileTypes);

        if ($types === null && $this->evaluate($this->isImage)) {
            return ['image/*'];
        }

        return $types;
    }

    /**
     * The accepted types as short, deduplicated names for the drop zone hint:
     * `['image/png', 'image/jpeg', 'image/x-icon']` is "PNG, JPG, ICO" and `image/*` is "Images".
     */
    public function getAcceptedFileTypesLabel(): ?string
    {
        $types = $this->getAcceptedFileTypes();

        if ($types === null || $types === []) {
            return null;
        }

        $extensions = array_flip(array_reverse($this->getMimeTypeMap(), true));
        $labels = [];

        foreach ($types as $type) {
            $type = strtolower(trim((string) $type));

            if ($type !== '') {
                $labels[] = self::fileTypeLabel($type, $extensions);
            }
        }

        return $labels === [] ? null : implode(', ', array_unique($labels));
    }

    /**
     * @param  array<string, string>  $extensions  MIME type => extension from `mimeTypeMap()`
     */
    protected static function fileTypeLabel(string $type, array $extensions): string
    {
        if (isset(self::FILE_TYPE_LABELS[$type])) {
            return self::FILE_TYPE_LABELS[$type];
        }

        if (isset($extensions[$type])) {
            return strtoupper($extensions[$type]);
        }

        // An extension such as `.csv`.
        if (! str_contains($type, '/')) {
            return strtoupper(ltrim($type, '.'));
        }

        [$group, $subtype] = explode('/', $type, 2);

        if ($subtype === '*') {
            return match ($group) {
                'image' => __('alfakit::forms.file_upload.types.images'),
                'video' => __('alfakit::forms.file_upload.types.videos'),
                'audio' => __('alfakit::forms.file_upload.types.audio'),
                'text' => __('alfakit::forms.file_upload.types.text'),
                default => strtoupper($group),
            };
        }

        // `application/vnd.sketchup.skp` is "SKP", `image/x-tga` is "TGA", `image/svg+xml` is "SVG".
        $subtype = Str::before($subtype, '+');
        $subtype = Str::afterLast($subtype, '.');

        return strtoupper(str_starts_with($subtype, 'x-') ? substr($subtype, 2) : $subtype);
    }

    /**
     * @return list<string>
     */
    public function getImageAspectRatios(): array
    {
        $ratios = $this->evaluate($this->imageAspectRatio);

        return array_values(array_filter(Arr::wrap($ratios), static fn (mixed $ratio): bool => is_string($ratio) && self::parseAspectRatio($ratio) !== null));
    }

    /**
     * @return array{minWidth: int|null, minHeight: int|null, maxWidth: int|null, maxHeight: int|null}
     */
    public function getImageDimensions(): array
    {
        return array_map(fn (int|Closure|null $value): ?int => ($value = $this->evaluate($value)) === null ? null : (int) $value, $this->imageDimensions);
    }

    /**
     * @return array{aspectRatios: list<string|null>, circle: bool, mode?: int, emptyFillColor?: string, viewportWidth?: int, viewportHeight?: int}|null
     */
    public function getImageEditor(): ?array
    {
        if (! $this->evaluate($this->hasImageEditor)) {
            return null;
        }

        $circle = (bool) $this->evaluate($this->hasCircleCropper);
        $ratios = $this->evaluate($this->imageEditorAspectRatios);

        if ($circle) {
            $ratios = ['1:1'];
        } elseif ($ratios === null) {
            $ratios = $this->getImageAspectRatios() ?: [null];
        }

        $mode = $this->evaluate($this->imageEditorMode);
        $fill = $this->evaluate($this->imageEditorEmptyFillColor);
        $viewportWidth = (int) $this->evaluate($this->imageEditorViewportWidth);
        $viewportHeight = (int) $this->evaluate($this->imageEditorViewportHeight);
        $hasViewport = $viewportWidth > 0 && $viewportHeight > 0;

        return [
            'aspectRatios' => array_values(array_map(static fn (mixed $ratio): ?string => is_string($ratio) && self::parseAspectRatio($ratio) !== null ? $ratio : null, $ratios)),
            'circle' => $circle,
        ] + array_filter([
            'mode' => is_numeric($mode) && in_array((int) $mode, [0, 1, 2, 3], true) ? (int) $mode : null,
            // A CSS colour goes into a canvas `fillStyle`; anything with markup characters is dropped.
            'emptyFillColor' => is_string($fill) && preg_match('/^[#\w\s(),.%\/-]+$/', $fill) === 1 ? $fill : null,
            'viewportWidth' => $hasViewport ? $viewportWidth : null,
            'viewportHeight' => $hasViewport ? $viewportHeight : null,
        ], static fn (mixed $value): bool => $value !== null);
    }

    public function shouldOrientImagesFromExif(): bool
    {
        return (bool) $this->evaluate($this->shouldOrientImagesFromExif);
    }

    /**
     * Whether the server can rotate JPEGs from their EXIF data (GD and `exif`).
     */
    public static function canOrientImagesOnServer(): bool
    {
        return function_exists('exif_read_data') && function_exists('imagecreatefromstring') && function_exists('imagerotate');
    }

    /** `'16:9'`, `'16/9'` or `'1.5'` as width ÷ height. */
    public static function parseAspectRatio(string $ratio): ?float
    {
        $parts = preg_split('/[:\/]/', trim($ratio)) ?: [];
        $width = (float) ($parts[0] ?? 0);
        $height = (float) ($parts[1] ?? 1);

        return $width > 0 && $height > 0 ? $width / $height : null;
    }

    public static function getTemporaryDisk(): Filesystem
    {
        return TemporaryUpload::disk();
    }

    public static function getTemporaryDirectory(): string
    {
        return TemporaryUpload::directory();
    }

    public function getMaxParallelUploads(): ?int
    {
        $count = $this->evaluate($this->maxParallelUploads);

        return $count === null ? null : max(1, (int) $count);
    }

    /**
     * The `storeFileNamesIn()` attribute, relative to this field's parent (a sibling).
     */
    public function getFileNamesStatePath(): ?string
    {
        $path = $this->evaluate($this->fileNamesStatePath);

        return is_string($path) && $path !== '' ? $path : null;
    }

    /**
     * The original file names known for the current files, keyed by path: the names the
     * record (or Repeater item) holds in the `storeFileNamesIn()` attribute.
     *
     * @return array<string, string>
     */
    public function getStoredFileNames(): array
    {
        $attribute = $this->getFileNamesStatePath();

        if ($attribute === null) {
            return [];
        }

        $record = $this->getRecord();
        $names = $record !== null && array_key_exists($attribute, $record->getAttributes())
            ? $record->getAttribute($attribute)
            : ($this->hasContainer() ? $this->getContainer()->getStateAt($this->getSiblingStatePath($attribute)) : null);

        if (is_string($names) && Str::isJson($names)) {
            $names = json_decode($names, true);
        }

        if (is_string($names)) {
            // A single field stores the name of its one file.
            $file = $this->hydratedFiles[0] ?? null;

            return $file !== null && $names !== '' ? [$file => $names] : [];
        }

        return is_array($names)
            ? array_filter($names, static fn (mixed $name, mixed $path): bool => is_string($name) && is_string($path), ARRAY_FILTER_USE_BOTH)
            : [];
    }

    /**
     * The absolute state path of an attribute next to this field (same parent).
     */
    public function getSiblingStatePath(string $attribute): string
    {
        $path = $this->getStatePath();

        return str_contains($path, '.') ? Str::beforeLast($path, '.').'.'.$attribute : $attribute;
    }

    public function shouldPreventFilePathTampering(): bool
    {
        return (bool) $this->evaluate($this->shouldPreventFilePathTampering);
    }

    /**
     * Whether a stored path (not a temporary upload) may be submitted for this field: one
     * it was filled with or the record holds, or one `allowFilePathUsing` accepts.
     */
    public function isFilePathAllowed(string $file): bool
    {
        if (! $this->shouldPreventFilePathTampering() || in_array($file, $this->getExistingFiles(), true)) {
            return true;
        }

        return $this->allowFilePathUsing !== null && (bool) $this->evaluate($this->allowFilePathUsing, ['file' => $file, 'path' => $file]);
    }

    /**
     * @return array<string, string> Lower-case extension (without the dot) → MIME type
     */
    public function getMimeTypeMap(): array
    {
        $map = [];

        foreach ((array) $this->evaluate($this->mimeTypeMap) as $extension => $type) {
            if (is_string($extension) && is_string($type) && $type !== '') {
                $map[strtolower(ltrim($extension, '.'))] = strtolower($type);
            }
        }

        return $map;
    }

    /**
     * The MIME type a temporary upload counts as: the detected one, or the
     * `mimeTypeMap()` type of its original extension when the server only detected a
     * generic type.
     */
    public function getUploadMimeType(TemporaryUpload $upload): string
    {
        $mime = strtolower($upload->mimeType());
        $mapped = $this->getMimeTypeMap()[$this->getOriginalExtension($upload)] ?? null;

        return $mapped !== null && in_array($mime, self::GENERIC_MIME_TYPES, true) ? $mapped : $mime;
    }

    protected function getOriginalExtension(TemporaryUpload $upload): string
    {
        return strtolower((string) pathinfo($upload->originalName(), PATHINFO_EXTENSION));
    }

    /**
     * The extension a temporary upload is stored with: the one derived from its detected
     * type, or its original one when `mimeTypeMap()` maps it.
     */
    protected function getUploadExtension(TemporaryUpload $upload): string
    {
        $original = $this->getOriginalExtension($upload);

        if (isset($this->getMimeTypeMap()[$original]) && $this->getUploadMimeType($upload) !== strtolower($upload->mimeType()) && preg_match('/^[a-z0-9]{1,10}$/', $original) === 1) {
            return $original;
        }

        return $upload->extension();
    }

    public function getOpenableFileUrl(string $path): ?string
    {
        $url = $this->getOpenableFileUrlUsing === null ? null : $this->evaluate($this->getOpenableFileUrlUsing, ['file' => $path, 'path' => $path]);

        return Url::sanitize($url);
    }

    public function getDownloadableFileUrl(string $path): ?string
    {
        $url = $this->getDownloadableFileUrlUsing === null ? null : $this->evaluate($this->getDownloadableFileUrlUsing, ['file' => $path, 'path' => $path]);

        return Url::sanitize($url);
    }

    /**
     * Size and existence of a stored file, read from its disk (`fetchFileInformation()`).
     *
     * @return array{size?: int, missing?: true}
     */
    protected function getStoredFileInformation(string $path): array
    {
        if (! $this->evaluate($this->shouldFetchFileInformation)) {
            return [];
        }

        try {
            $disk = $this->getDisk();

            if (! $disk->exists($path)) {
                return ['missing' => true];
            }

            return ['size' => $disk->size($path)];
        } catch (Throwable) {
            return [];
        }
    }

    // ---------------------------------------------------------------- State

    protected function getDefaultStateWhenEmpty(): mixed
    {
        return $this->isMultiple() ? [] : null;
    }

    public function hydrateState(bool $useDefaults): void
    {
        parent::hydrateState($useDefaults);

        $files = $this->normalizeFiles($this->getState());

        $this->hydratedFiles = array_values(array_filter($files, static fn (mixed $file): bool => is_string($file) && ! TemporaryUpload::isKey($file)));

        $this->state($files);
    }

    /**
     * Renderers may send a single upload as one path (or an empty string); the server
     * validates and stores a list for single and multiple fields alike.
     */
    public function normalizeClientState(): void
    {
        $this->state($this->normalizeFiles($this->getState()));
    }

    /**
     * Internally the field always works with a list of paths.
     *
     * @return array<int, mixed>
     */
    protected function normalizeFiles(mixed $state): array
    {
        if (is_string($state) && Str::isJson($state)) {
            $state = json_decode($state, true);
        }

        return array_values(array_filter(Arr::wrap($state ?? []), static fn (mixed $file): bool => filled($file)));
    }

    /**
     * Stored paths that may be submitted back unchanged: what the field was filled with
     * and what the record holds. A client can't point the field at someone else's file.
     *
     * @return array<string>
     */
    public function getExistingFiles(): array
    {
        $original = $this->getRecord()?->getOriginal($this->getName());

        if (is_string($original) && Str::isJson($original)) {
            $original = json_decode($original, true);
        }

        $recordFiles = array_filter(Arr::wrap($original ?? []), static fn (mixed $file): bool => is_string($file) && $file !== '');

        return array_values(array_unique([...$this->hydratedFiles, ...$recordFiles]));
    }

    /**
     * Why an uploaded file can't be used by this field, or null when it can.
     */
    public function getTemporaryUploadError(TemporaryUpload $upload): ?string
    {
        $size = (int) ceil($upload->size() / 1024);

        if (($max = $this->evaluate($this->maxSize)) !== null && $size > $max) {
            return __('alfakit::forms.file_upload.too_large');
        }

        if (($min = $this->evaluate($this->minSize)) !== null && $size < $min) {
            return __('alfakit::forms.file_upload.too_small');
        }

        if (! $this->acceptsFile($upload)) {
            return __('alfakit::forms.file_upload.invalid_type');
        }

        return $this->getImageDimensionsError($upload);
    }

    /**
     * `imageDimensions()` and `imageAspectRatio()`, checked against the file's pixels
     * like Laravel's `dimensions` rule (same ratio tolerance).
     */
    protected function getImageDimensionsError(TemporaryUpload $upload): ?string
    {
        $limits = $this->getImageDimensions();
        $ratios = $this->getImageAspectRatios();

        if ($ratios === [] && array_filter($limits, static fn (?int $limit): bool => $limit !== null) === []) {
            return null;
        }

        $contents = TemporaryUpload::disk()->get($upload->path());
        $size = is_string($contents) && $contents !== '' ? @getimagesizefromstring($contents) : false;

        if ($size === false || $size[0] <= 0 || $size[1] <= 0) {
            return __('alfakit::forms.file_upload.invalid_dimensions');
        }

        [$width, $height] = $size;

        if (($limits['minWidth'] !== null && $width < $limits['minWidth'])
            || ($limits['minHeight'] !== null && $height < $limits['minHeight'])
            || ($limits['maxWidth'] !== null && $width > $limits['maxWidth'])
            || ($limits['maxHeight'] !== null && $height > $limits['maxHeight'])) {
            return __('alfakit::forms.file_upload.invalid_dimensions');
        }

        if ($ratios === []) {
            return null;
        }

        $precision = 1 / (max($width, $height) + 1);

        foreach ($ratios as $ratio) {
            if (abs((float) self::parseAspectRatio($ratio) - $width / $height) <= $precision) {
                return null;
            }
        }

        return __('alfakit::forms.file_upload.invalid_aspect_ratio');
    }

    protected function acceptsFile(TemporaryUpload $upload): bool
    {
        $mime = $this->getUploadMimeType($upload);

        if ($this->evaluate($this->isImage) && ! str_starts_with($mime, 'image/')) {
            return false;
        }

        $accepted = $this->getAcceptedFileTypes();

        if ($accepted === null || $accepted === []) {
            return true;
        }

        foreach ($accepted as $type) {
            $type = strtolower(trim($type));

            $matches = match (true) {
                str_starts_with($type, '.') => in_array($type, ['.'.$upload->extension(), '.'.$this->getUploadExtension($upload)], true),
                str_ends_with($type, '/*') => str_starts_with($mime, substr($type, 0, -1)),
                default => $mime === $type,
            };

            if ($matches) {
                return true;
            }
        }

        return false;
    }

    /**
     * Move temporary uploads to their permanent location and return the stored path(s).
     *
     * @return string|array<string>|null
     */
    protected function storeUploadedFiles(mixed $state): string|array|null
    {
        $files = array_values(array_filter(Arr::wrap($state ?? []), static fn (mixed $file): bool => filled($file)));
        $stored = [];
        $knownNames = $this->getStoredFileNames();
        $names = [];

        foreach ($files as $file) {
            if (! is_string($file)) {
                continue;
            }

            if (! TemporaryUpload::isKey($file)) {
                if ($this->isFilePathAllowed($file)) {
                    $stored[] = $file;
                    $names[$file] = $knownNames[$file] ?? basename($file);
                }

                continue;
            }

            $upload = TemporaryUpload::find($file);

            if ($upload === null || $this->getTemporaryUploadError($upload) !== null) {
                continue;
            }

            if (! $this->evaluate($this->shouldStoreFiles)) {
                $stored[] = $file;
                $names[$file] = $upload->originalName();

                continue;
            }

            $originalName = $upload->originalName();
            $path = $this->moveTemporaryFile($upload);

            if ($path !== null) {
                $stored[] = $path;
                $names[$path] = $originalName;
            }
        }

        $this->dehydratedFileNames = $names;

        if ($this->evaluate($this->shouldDeleteRemovedFiles)) {
            $this->deleteRemovedStoredFiles($stored);
        }

        if ($this->isMultiple()) {
            return $stored;
        }

        return $stored[0] ?? null;
    }

    /**
     * `storeFileNamesIn()`: the original names of the files the last dehydration kept,
     * `[path => name]` (multiple) or the name (single).
     *
     * @return array<string, mixed>
     */
    public function getAdditionalDehydratedState(): array
    {
        $attribute = $this->getFileNamesStatePath();

        if ($attribute === null || $this->dehydratedFileNames === null) {
            return [];
        }

        if ($this->isMultiple()) {
            return [$attribute => $this->dehydratedFileNames];
        }

        return [$attribute => array_values($this->dehydratedFileNames)[0] ?? null];
    }

    protected function moveTemporaryFile(TemporaryUpload $upload): ?string
    {
        $temporaryDisk = TemporaryUpload::disk();
        $fullTemporaryPath = $upload->path();
        $originalName = $upload->originalName();
        // The extension was derived from the detected type when the file was uploaded.
        $extension = $this->getUploadExtension($upload);

        $fileName = $this->resolveStoredFileName($originalName, $extension, $upload);
        $directory = trim((string) $this->getDirectory(), '/');
        $targetPath = ($directory !== '' ? $directory.'/' : '').$fileName;

        $oriented = $this->shouldOrientImagesFromExif() ? $this->orientFromExif($upload) : null;

        if ($oriented !== null) {
            $this->getDisk()->put($targetPath, $oriented, ['visibility' => $this->getVisibility()]);
            $upload->delete();

            return $targetPath;
        }

        $stream = $temporaryDisk->readStream($fullTemporaryPath);

        if ($stream === null) {
            return null;
        }

        $this->getDisk()->writeStream($targetPath, $stream, ['visibility' => $this->getVisibility()]);

        if (is_resource($stream)) {
            fclose($stream);
        }

        $upload->delete();

        return $targetPath;
    }

    /**
     * The JPEG re-encoded upright when its EXIF orientation says it is rotated or
     * mirrored; null when it is upright already, not a JPEG, or GD/exif are missing.
     */
    protected function orientFromExif(TemporaryUpload $upload): ?string
    {
        if (! self::canOrientImagesOnServer() || ! in_array(strtolower($upload->mimeType()), ['image/jpeg', 'image/pjpeg'], true)) {
            return null;
        }

        $contents = TemporaryUpload::disk()->get($upload->path());

        if (! is_string($contents) || $contents === '') {
            return null;
        }

        $exif = @exif_read_data('data://image/jpeg;base64,'.base64_encode($contents));
        $orientation = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;

        if ($orientation < 2 || $orientation > 8) {
            return null;
        }

        $image = @imagecreatefromstring($contents);

        if ($image === false) {
            return null;
        }

        // EXIF orientations: 2 mirror, 3 rotate 180, 4 flip, 5 transpose, 6 rotate 90 CW,
        // 7 transverse, 8 rotate 90 CCW. Mirror first, then turn (`imagerotate()` turns
        // counter-clockwise).
        if (in_array($orientation, [2, 4, 5, 7], true)) {
            imageflip($image, $orientation === 4 ? IMG_FLIP_VERTICAL : IMG_FLIP_HORIZONTAL);
        }

        $angle = match ($orientation) {
            3 => 180,
            6, 7 => 270,
            5, 8 => 90,
            default => 0,
        };

        if ($angle !== 0) {
            $rotated = imagerotate($image, $angle, 0);

            if ($rotated === false) {
                return null;
            }

            $image = $rotated;
        }

        ob_start();
        imagejpeg($image, null, 90);

        return (string) ob_get_clean();
    }

    /**
     * The stored file's name. `getUploadedFileNameForStorageUsing()` closures receive
     * `$file` (also `$upload`: the upload, with `getClientOriginalName()` as in Filament),
     * `$originalName` and `$extension` (the extension of the detected type).
     */
    protected function resolveStoredFileName(string $originalName, string $extension, ?TemporaryUpload $upload = null): string
    {
        if ($this->getUploadedFileNameForStorageUsing !== null) {
            return (string) $this->evaluate($this->getUploadedFileNameForStorageUsing, [
                'originalName' => $originalName,
                'extension' => $extension,
                'file' => $upload,
                'upload' => $upload,
            ]);
        }

        if ($this->evaluate($this->shouldPreserveFilenames)) {
            $base = Str::slug(pathinfo($originalName, PATHINFO_FILENAME));

            return $extension !== '' ? "{$base}.{$extension}" : $base;
        }

        $name = (string) Str::ulid();

        return $extension !== '' ? "{$name}.{$extension}" : $name;
    }

    /**
     * @param  array<string>  $keptFiles
     */
    protected function deleteRemovedStoredFiles(array $keptFiles): void
    {
        $record = $this->getRecord();

        if ($record === null || ! $record->exists) {
            return;
        }

        $original = $record->getOriginal($this->getName());

        if (is_string($original) && Str::isJson($original)) {
            $original = json_decode($original, true);
        }

        foreach (array_filter(Arr::wrap($original ?? [])) as $file) {
            if (is_string($file) && ! in_array($file, $keptFiles, true)) {
                $this->getDisk()->delete($file);
            }
        }
    }

    public function getFileUrl(string $path): ?string
    {
        if (str_starts_with($path, self::TEMPORARY_PREFIX)) {
            return null;
        }

        try {
            // Private files (an S3 bucket without public access) need a signed URL.
            // Only http(s) and relative URLs reach the browser (no `javascript:` links).
            if ($this->getVisibility() === 'private') {
                return Url::sanitize($this->getDisk()->temporaryUrl($path, $this->getTemporaryUrlExpiration()));
            }

            return Url::sanitize($this->getDisk()->url($path));
        } catch (Throwable) {
            // E.g. a private file on a local disk without `serve`: no preview or link.
            return null;
        }
    }

    protected function getTypeValidationRules(): array
    {
        $rules = [];

        if ($this->isMultiple()) {
            $rules[] = 'array';

            if (($min = $this->evaluate($this->minFiles)) !== null) {
                $rules[] = "min:{$min}";
            }

            if (($max = $this->evaluate($this->maxFiles)) !== null) {
                $rules[] = "max:{$max}";
            }
        } else {
            $rules[] = 'array';
            $rules[] = 'max:1';
        }

        return $rules;
    }

    public function getValidationRulesByPath(): array
    {
        $rules = parent::getValidationRulesByPath();

        if ($rules !== []) {
            $rules[$this->getStatePath().'.*'] = ['string', function (string $attribute, mixed $value, Closure $fail): void {
                if (! is_string($value)) {
                    return;
                }

                if (! TemporaryUpload::isKey($value)) {
                    if (! $this->isFilePathAllowed($value)) {
                        $fail($this->getValidationMessages()['tampered'] ?? __('alfakit::forms.file_upload.unavailable'));
                    }

                    return;
                }

                $upload = TemporaryUpload::find($value);

                if ($upload === null) {
                    $fail(__('alfakit::forms.file_upload.unavailable'));

                    return;
                }

                if (($error = $this->getTemporaryUploadError($upload)) !== null) {
                    $fail($error);
                }
            }];
        }

        return $rules;
    }

    protected function getSchemaProps(): array
    {
        $files = Arr::wrap($this->getState() ?? []);
        $names = $this->getStoredFileNames();
        $mimeTypeMap = $this->getMimeTypeMap();
        $aspectRatio = $this->evaluate($this->panelAspectRatio);
        $previewHeight = $this->evaluate($this->imagePreviewHeight);

        return [
            'multiple' => $this->isMultiple() ?: null,
            'image' => $this->evaluate($this->isImage) ?: null,
            'avatar' => $this->evaluate($this->isAvatar) ?: null,
            'acceptedFileTypes' => $this->getAcceptedFileTypes(),
            'acceptedFileTypesLabel' => $this->getAcceptedFileTypesLabel(),
            'maxSize' => $this->evaluate($this->maxSize),
            'minSize' => $this->evaluate($this->minSize),
            'maxFiles' => $this->isMultiple() ? $this->evaluate($this->maxFiles) : 1,
            'minFiles' => $this->evaluate($this->minFiles),
            'downloadable' => $this->evaluate($this->isDownloadable) ?: null,
            'openable' => $this->evaluate($this->isOpenable) ?: null,
            'previewable' => (bool) $this->evaluate($this->isPreviewable),
            'reorderable' => $this->evaluate($this->isReorderable) ?: null,
            'deletable' => (bool) $this->evaluate($this->isDeletable),
            'panelLayout' => $this->evaluate($this->panelLayout),
            'panelAspectRatio' => is_string($aspectRatio) && self::parseAspectRatio($aspectRatio) !== null ? $aspectRatio : null,
            'imagePreviewHeight' => is_numeric($previewHeight) || (is_string($previewHeight) && is_numeric(rtrim($previewHeight, 'px')))
                ? (int) rtrim((string) $previewHeight, 'px')
                : null,
            'imageCropAspectRatio' => $this->evaluate($this->imageCropAspectRatio),
            'imageResizeMode' => $this->evaluate($this->imageResizeMode),
            'imageResizeTargetWidth' => $this->evaluate($this->imageResizeTargetWidth),
            'imageResizeTargetHeight' => $this->evaluate($this->imageResizeTargetHeight),
            'uploadingMessage' => $this->evaluate($this->uploadingMessage),
            'imageEditor' => $this->getImageEditor(),
            'imageAspectRatio' => $this->getImageAspectRatios() ?: null,
            'imageDimensions' => array_filter($this->getImageDimensions(), static fn (?int $limit): bool => $limit !== null) ?: null,
            // Without GD/exif on the server the browser re-encodes JPEGs upright instead.
            'orientImagesFromExif' => $this->shouldOrientImagesFromExif() && ! self::canOrientImagesOnServer() ? true : null,
            'pasteable' => (bool) $this->evaluate($this->isPasteable),
            'maxParallelUploads' => $this->getMaxParallelUploads(),
            // What the temporary upload endpoint accepts for this field (its size limit may
            // exceed `alfakit.uploads.max_size_kb`); types are left to the save when
            // `mimeTypeMap()` may override the detected type.
            'uploadPolicy' => \AlfaSix\Alfakit\Support\UploadPolicy::token(
                is_numeric($maxSize = $this->evaluate($this->maxSize)) ? (int) $maxSize : null,
                $mimeTypeMap === [] ? $this->getAcceptedFileTypes() : null,
            ),
            // `appendFiles(false)`: new uploads go first.
            'appendFiles' => $this->evaluate($this->shouldAppendFiles) ? null : false,
            // Extensions the browser may not recognise, added to the picker's `accept`.
            'mimeTypeMap' => $mimeTypeMap ?: null,
            'positions' => array_filter([
                'removeButton' => self::normalizePosition($this->evaluate($this->removeUploadedFileButtonPosition)),
                'progressIndicator' => self::normalizePosition($this->evaluate($this->uploadProgressIndicatorPosition) ?? $this->evaluate($this->loadingIndicatorPosition)),
                'uploadButton' => self::normalizePosition($this->evaluate($this->uploadButtonPosition), allowCenter: true),
            ]) ?: null,
            'files' => array_values(array_map(function (string $path) use ($names): array {
                // A live refresh after an upload sends temporary keys back: show the
                // uploader's own file name and size, not the key.
                $upload = TemporaryUpload::isKey($path) ? TemporaryUpload::find($path) : null;
                $information = $upload === null ? $this->getStoredFileInformation($path) : [];
                // Subclasses (media library) build their own URLs: sanitise here too.
                $url = Url::sanitize($this->getFileUrl($path));
                $openUrl = $url === null ? null : $this->getOpenableFileUrl($path);
                $downloadUrl = $url === null ? null : $this->getDownloadableFileUrl($path);

                return array_filter([
                    'path' => $path,
                    'name' => $upload?->originalName() ?? $names[$path] ?? basename($path),
                    'size' => $upload?->size() ?? ($information['size'] ?? null),
                    'url' => $url,
                    'openUrl' => $openUrl !== $url ? $openUrl : null,
                    'downloadUrl' => $downloadUrl !== $url ? $downloadUrl : null,
                    'missing' => $information['missing'] ?? null,
                    'temporary' => str_starts_with($path, self::TEMPORARY_PREFIX) ?: null,
                ], static fn (mixed $value): bool => $value !== null);
            }, array_filter($files, 'is_string'))),
        ];
    }

    /**
     * FilePond's positions (`'left'`, `'right bottom'`, `'center'`…) as logical sides:
     * `start`, `end` or (with `$allowCenter`) `center`.
     */
    protected static function normalizePosition(mixed $position, bool $allowCenter = false): ?string
    {
        if (! is_string($position) || $position === '') {
            return null;
        }

        $position = strtolower($position);

        return match (true) {
            str_contains($position, 'left'), str_contains($position, 'start') => 'start',
            str_contains($position, 'right'), str_contains($position, 'end') => 'end',
            $allowCenter && str_contains($position, 'center') => 'center',
            default => null,
        };
    }

    /** Changes once per choice: `live()` refreshes without waiting. */
    public function getDefaultLiveDebounce(): int
    {
        return 0;
    }
}
