<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\CommandPalette;

use AlfaSix\Alfakit\Forms\Components\Textarea;
use AlfaSix\Alfakit\Forms\Components\TextInput;
use AlfaSix\Alfakit\Resources\Pages\CreateRecord;
use AlfaSix\Alfakit\Resources\Pages\EditRecord;
use AlfaSix\Alfakit\Resources\Pages\ListRecords;
use AlfaSix\Alfakit\Resources\Pages\ViewRecord;
use AlfaSix\Alfakit\Resources\Resource;
use AlfaSix\Alfakit\Schemas\Schema;
use AlfaSix\Alfakit\Tables\Columns\TextColumn;
use AlfaSix\Alfakit\Tables\Table;

class VersionedPostResource extends Resource
{
    protected static ?string $model = VersionedPost::class;

    protected static ?string $slug = 'versioned-posts';

    protected static ?string $navigationIcon = 'file-text';

    protected static ?string $recordTitleAttribute = 'title';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('title')->required(),
            TextInput::make('slug')->required(),
            Textarea::make('content'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('title')]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListVersionedPosts::route('/'),
            'create' => CreateVersionedPost::route('/create'),
            'view' => ViewVersionedPost::route('/{record}'),
            'edit' => EditVersionedPost::route('/{record}/edit'),
        ];
    }
}

class ListVersionedPosts extends ListRecords
{
    protected static string $resource = VersionedPostResource::class;
}

class CreateVersionedPost extends CreateRecord
{
    protected static string $resource = VersionedPostResource::class;
}

class ViewVersionedPost extends ViewRecord
{
    protected static string $resource = VersionedPostResource::class;
}

class EditVersionedPost extends EditRecord
{
    protected static string $resource = VersionedPostResource::class;
}
