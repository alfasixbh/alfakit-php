<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\ResourceTabs;

use AlfaSix\Alfakit\Forms\Components\TextInput;
use AlfaSix\Alfakit\Infolists\Components\TextEntry;
use AlfaSix\Alfakit\Resources\Resource;
use AlfaSix\Alfakit\Schemas\Schema;
use AlfaSix\Alfakit\Tables\Columns\TextColumn;
use AlfaSix\Alfakit\Tables\Table;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Post;

class RtPostResource extends Resource
{
    protected static ?string $model = Post::class;

    protected static ?string $slug = 'rt-posts';

    protected static ?string $modelLabel = 'eBook';

    public static bool $titleCase = true;

    public static function hasTitleCaseModelLabel(): bool
    {
        return static::$titleCase;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([TextInput::make('title')->required()]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([TextEntry::make('title')]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('title')]);
    }

    public static function getRelations(): array
    {
        return [RtCommentsRelationManager::class, RtTagsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListRtPosts::route('/'),
            'view' => Pages\ViewRtPost::route('/{record}'),
            'edit' => Pages\EditRtPost::route('/{record}/edit'),
        ];
    }
}
