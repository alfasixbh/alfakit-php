<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\PageParity;

use AlfaSix\Alfakit\Actions\Action;
use AlfaSix\Alfakit\Actions\EditAction;
use AlfaSix\Alfakit\Forms\Components\TextInput;
use AlfaSix\Alfakit\Forms\Components\Toggle;
use AlfaSix\Alfakit\Infolists\Components\TextEntry;
use AlfaSix\Alfakit\Resources\Resource;
use AlfaSix\Alfakit\Schemas\Components\Section;
use AlfaSix\Alfakit\Schemas\Schema;
use AlfaSix\Alfakit\Tables\Columns\TextColumn;
use AlfaSix\Alfakit\Tables\Table;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Post;

class ParityPostResource extends Resource
{
    protected static ?string $model = Post::class;

    protected static ?string $slug = 'parity-posts';

    protected static ?string $recordTitleAttribute = 'title';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Content')->key('content-section')->schema([
                TextInput::make('title')->required()->maxLength(100),
                TextInput::make('slug')->required(),
            ])->footerActions([
                Action::make('saveContent')
                    ->formId('form')
                    ->action(fn (Pages\EditParityPost $page) => $page->saveFormComponentOnly('content-section')),
            ]),
            Section::make('Status')->key('status-section')->schema([
                TextInput::make('status')->required()->rules(['in:draft,review,published']),
                Toggle::make('is_published'),
            ]),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('title'),
            TextEntry::make('status'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([TextColumn::make('title'), TextColumn::make('status')])
            ->recordActions([
                EditAction::make(),
                Action::make('publish')
                    ->requiresConfirmation()
                    ->action(fn (Post $record) => $record->update(['status' => 'published'])),
                Action::make('ping')->action(fn () => null),
            ]);
    }

    public static function getRelations(): array
    {
        return [ParityCommentsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListParityPosts::route('/'),
            'create' => Pages\CreateParityPost::route('/create'),
            'view' => Pages\ViewParityPost::route('/{record}'),
            'edit' => Pages\EditParityPost::route('/{record}/edit'),
        ];
    }
}
