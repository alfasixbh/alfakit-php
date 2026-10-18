<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\Parity3;

use AlfaSix\Alfakit\Forms\Components\TextInput;
use AlfaSix\Alfakit\Infolists\Components\TextEntry;
use AlfaSix\Alfakit\Resources\Resource;
use AlfaSix\Alfakit\Schemas\Components\Section;
use AlfaSix\Alfakit\Schemas\Components\Tabs;
use AlfaSix\Alfakit\Schemas\Components\Tabs\Tab;
use AlfaSix\Alfakit\Schemas\Schema;
use AlfaSix\Alfakit\Tables\Columns\TextColumn;
use AlfaSix\Alfakit\Tables\Table;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Post;

class P3PostResource extends Resource
{
    protected static ?string $model = Post::class;

    protected static ?string $slug = 'posts';

    protected static ?string $recordTitleAttribute = 'title';

    protected static ?string $navigationGroup = 'Content';

    /** @var array<array-key, mixed>|null Replaces `getRelations()` in a test. */
    public static ?array $relationsOverride = null;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('title')->required(),
            Section::make('Details')
                ->key('details')
                ->deferLoading()
                ->schema([TextInput::make('slug')->required()]),
            Tabs::make('More')->key('more')->tabs([
                Tab::make('Meta')->key('meta')
                    ->deferBadge()
                    ->badge(fn (?Post $record): int => (int) $record?->rating)
                    ->badgeColor('danger')
                    ->schema([TextInput::make('status')]),
                Tab::make('Later')->key('later')->deferLoading()->schema([TextInput::make('content')]),
            ]),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Summary')->key('summary')->deferLoading()->schema([TextEntry::make('title')]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('title')]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListP3Posts::route('/'),
            'view' => Pages\ViewP3Post::route('/{record}'),
            'edit' => Pages\EditP3Post::route('/{record}/edit'),
            'manageComments' => Pages\ManageP3PostComments::route('/{record}/comments'),
        ];
    }

    public static function getRelations(): array
    {
        return static::$relationsOverride ?? [
            'comments' => RelationManagers\P3CommentsRelationManager::make(['author' => 'Ann']),
            'tags' => RelationManagers\P3TagsRelationManager::class,
        ];
    }
}
