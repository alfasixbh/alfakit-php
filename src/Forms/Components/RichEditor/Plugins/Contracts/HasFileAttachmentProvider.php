<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Forms\Components\RichEditor\Plugins\Contracts;

use AlfaSix\Alfakit\Forms\Components\RichEditor\FileAttachmentProviders\Contracts\FileAttachmentProvider;

/**
 * A rich content plugin that stores the editor's image attachments itself (e.g. in a media
 * library). Used when neither the editor nor its rich content attribute sets a provider.
 */
interface HasFileAttachmentProvider
{
    public function getFileAttachmentProvider(): ?FileAttachmentProvider;
}
