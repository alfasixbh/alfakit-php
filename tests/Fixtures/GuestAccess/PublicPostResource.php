<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\GuestAccess;

use AlfaSix\Alfakit\Forms\Components\TextInput;
use AlfaSix\Alfakit\Resources\Pages\CreateRecord;
use AlfaSix\Alfakit\Resources\Pages\ListRecords;
use AlfaSix\Alfakit\Resources\Pages\ViewRecord;
use AlfaSix\Alfakit\Resources\Resource;
use AlfaSix\Alfakit\Schemas\Schema;
use AlfaSix\Alfakit\Tables\Columns\TextColumn;
use AlfaSix\Alfakit\Tables\Table;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Post;

/** Every page of this resource opens to guests; `GuestPostPolicy` decides the rest. */
class PublicPostResource extends Resource
{
    protected static ?string $model = Post::class;

    protected static ?string $slug = 'public-posts';

    protected static ?string $recordTitleAttribute = 'title';

    protected static bool $isGuestAccessible = true;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([TextInput::make('title')->required(), TextInput::make('slug')->required()]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('title')]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPublicPosts::route('/'),
            'create' => CreatePublicPost::route('/create'),
            'view' => ViewPublicPost::route('/{record}'),
        ];
    }
}

class ListPublicPosts extends ListRecords
{
    protected static string $resource = PublicPostResource::class;
}

class CreatePublicPost extends CreateRecord
{
    protected static string $resource = PublicPostResource::class;
}

class ViewPublicPost extends ViewRecord
{
    protected static string $resource = PublicPostResource::class;
}
