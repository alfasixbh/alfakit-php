<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\ImportExport;

use AlfaSix\Alfakit\Actions\Exports\ExportColumn;
use AlfaSix\Alfakit\Actions\Exports\Exporter;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Post;

class PostExporter extends Exporter
{
    protected static ?string $model = Post::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('id')->label('ID'),
            ExportColumn::make('title'),
            ExportColumn::make('author.name'),
            ExportColumn::make('is_published'),
            ExportColumn::make('comments_count')->counts('comments'),
            ExportColumn::make('tags.name')->label('Tags'),
            ExportColumn::make('status')->enabledByDefault(false),
        ];
    }
}
