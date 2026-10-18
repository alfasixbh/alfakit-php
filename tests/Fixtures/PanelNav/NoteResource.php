<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\PanelNav;

use AlfaSix\Alfakit\Forms\Components\TextInput;
use AlfaSix\Alfakit\Navigation\NavigationItem;
use AlfaSix\Alfakit\Navigation\SubNavigationPosition;
use AlfaSix\Alfakit\Resources\Pages\Page;
use AlfaSix\Alfakit\Resources\Resource;
use AlfaSix\Alfakit\Schemas\Schema;
use AlfaSix\Alfakit\Tables\Columns\TextColumn;
use AlfaSix\Alfakit\Tables\Table;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Post;

class NoteResource extends Resource
{
    protected static ?string $model = Post::class;

    protected static ?string $slug = 'notes';

    protected static ?string $navigationGroup = 'Content';

    protected static ?string $recordTitleAttribute = 'title';

    protected static ?SubNavigationPosition $subNavigationPosition = SubNavigationPosition::Top;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([TextInput::make('title')->required()]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('title')]);
    }

    public static function getRecordSubNavigation(Page $page): array
    {
        return [
            ...$page->generateNavigationItems([ViewNote::class, EditNote::class]),
            NavigationItem::make('Docs')->url('https://alfakit.dev')->group('Help'),
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListNotes::route('/'),
            'view' => ViewNote::route('/{record}'),
            'edit' => EditNote::route('/{record}/edit'),
        ];
    }
}
