<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\Standalone;

use AlfaSix\Alfakit\Forms\Components\FileUpload;
use AlfaSix\Alfakit\Schemas\Schema;
use AlfaSix\Alfakit\Standalone\Form;

/** A guest form with an attachment. */
class UploadForm extends Form
{
    /** @var array<int, array<string, mixed>> */
    public static array $submitted = [];

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            FileUpload::make('attachment')->disk('public')->directory('attachments')->image()->required(),
        ]);
    }

    public function submit(array $data): mixed
    {
        static::$submitted[] = $data;

        return back();
    }
}
