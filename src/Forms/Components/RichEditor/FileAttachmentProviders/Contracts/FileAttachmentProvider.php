<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Forms\Components\RichEditor\FileAttachmentProviders\Contracts;

use AlfaSix\Alfakit\Forms\Components\RichEditor\RichContentAttribute;
use AlfaSix\Alfakit\Support\TemporaryUpload;

/**
 * Where a RichEditor stores the images inserted while editing, e.g. a media library
 * instead of a disk directory. Set one with `RichEditor::fileAttachmentProvider()`,
 * `RichContentAttribute::fileAttachmentProvider()`, or from a plugin that implements
 * `Plugins\Contracts\HasFileAttachmentProvider`. Without one, `DiskFileAttachmentProvider`
 * stores files on `fileAttachmentsDisk()` / `fileAttachmentsDirectory()`.
 *
 * Unlike Filament's (which stores a `data-id`), AlfaKit content references an attachment
 * by its URL (`<img src>`): the "file" a provider returns and receives is that URL.
 */
interface FileAttachmentProvider
{
    /**
     * The rich content attribute the provider serves (`$model->registerRichContent()`).
     */
    public function attribute(RichContentAttribute $attribute): static;

    /**
     * Store an image uploaded while editing (already checked against the editor's accepted
     * types and maximum size). Returns the URL the content stores, or null to drop the
     * image. The temporary upload is deleted afterwards.
     */
    public function saveUploadedFileAttachment(TemporaryUpload $file): ?string;

    /**
     * The URL to display for an attachment the content stores (e.g. a temporary URL of a
     * private file), or null to show the stored URL. Used by `RichContentRenderer`.
     */
    public function getFileAttachmentUrl(mixed $file): ?string;

    /**
     * The visibility (`public` / `private`) attachments default to, or null for the disk's.
     */
    public function getDefaultFileAttachmentVisibility(): ?string;

    /**
     * Whether new attachments can only be stored once the record exists (a media library
     * attaches files to the model). On a create form they are then stored right after the
     * record is created, and the content is updated with their URLs.
     */
    public function isExistingRecordRequiredToSaveNewFileAttachments(): bool;

    /**
     * Called after the record was saved with the URLs the content still references, so
     * the provider can delete attachments that were removed.
     *
     * @param  array<string>  $exceptIds
     */
    public function cleanUpFileAttachments(array $exceptIds): void;
}
