<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\Portal;

use AlfaSix\Alfakit\Actions\EditAction;
use AlfaSix\Alfakit\Forms\Components\TextInput;
use AlfaSix\Alfakit\Resources\Resource;
use AlfaSix\Alfakit\Schemas\Schema;
use AlfaSix\Alfakit\Tables\Columns\TextColumn;
use AlfaSix\Alfakit\Tables\Table;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Post;

/**
 * A customer-portal resource: the panel's `scopeToUser()` limits it to the user's posts.
 */
class MyPostResource extends Resource
{
    protected static ?string $model = Post::class;

    protected static ?string $slug = 'my-posts';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('title')->required(),
            TextInput::make('slug')->required(),
            // An owner field a customer could change: saving keeps the signed-in owner.
            TextInput::make('author_id')->integer(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('title')])->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMyPosts::route('/'),
            'create' => Pages\CreateMyPost::route('/create'),
            'edit' => Pages\EditMyPost::route('/{record}/edit'),
        ];
    }
}
