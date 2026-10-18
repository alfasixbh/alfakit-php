<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\ActionsResources;

use AlfaSix\Alfakit\Forms\Components\TextInput;
use AlfaSix\Alfakit\Resources\Resource;
use AlfaSix\Alfakit\Schemas\Schema;
use AlfaSix\Alfakit\Tests\Fixtures\Models\User;

class AuthorResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $slug = 'authors';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([TextInput::make('name')->required()]);
    }

    public static function getRelations(): array
    {
        return [AuthorResource\RelationManagers\PostsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => AuthorResource\Pages\ListAuthors::route('/'),
            'edit' => AuthorResource\Pages\EditAuthor::route('/{record}/edit'),
        ];
    }
}
