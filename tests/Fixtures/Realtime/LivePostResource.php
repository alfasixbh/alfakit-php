<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\Realtime;

use AlfaSix\Alfakit\Forms\Components\TextInput;
use AlfaSix\Alfakit\Resources\Pages\EditRecord;
use AlfaSix\Alfakit\Resources\Pages\ListRecords;
use AlfaSix\Alfakit\Resources\Pages\ViewRecord;
use AlfaSix\Alfakit\Resources\Resource;
use AlfaSix\Alfakit\Schemas\Schema;
use AlfaSix\Alfakit\Tables\Columns\TextColumn;
use AlfaSix\Alfakit\Tables\Table;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Post;

class LivePostResource extends Resource
{
    protected static ?string $model = Post::class;

    protected static ?string $slug = 'live-posts';

    protected static ?string $recordTitleAttribute = 'title';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('title')->required(),
            TextInput::make('slug')->required(),
            TextInput::make('content'),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([\AlfaSix\Alfakit\Infolists\Components\TextEntry::make('title')]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('title')])->live(debounce: 250);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLivePosts::route('/'),
            'view' => ViewLivePost::route('/{record}'),
            'edit' => EditLivePost::route('/{record}/edit'),
        ];
    }
}

class ListLivePosts extends ListRecords
{
    protected static string $resource = LivePostResource::class;
}

class ViewLivePost extends ViewRecord
{
    protected static string $resource = LivePostResource::class;
}

class EditLivePost extends EditRecord
{
    protected static string $resource = LivePostResource::class;
}
