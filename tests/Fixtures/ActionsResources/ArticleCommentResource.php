<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\ActionsResources;

use AlfaSix\Alfakit\Forms\Components\TextInput;
use AlfaSix\Alfakit\Resources\ParentResourceRegistration;
use AlfaSix\Alfakit\Resources\Resource;
use AlfaSix\Alfakit\Schemas\Schema;
use AlfaSix\Alfakit\Tables\Columns\TextColumn;
use AlfaSix\Alfakit\Tables\Table;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Comment;

class ArticleCommentResource extends Resource
{
    protected static ?string $model = Comment::class;

    protected static ?string $slug = 'comments';

    protected static ?string $recordTitleAttribute = 'author_name';

    public static function getParentResourceRegistration(): ?ParentResourceRegistration
    {
        return ArticleResource::asParent()->relationship('comments')->inverseRelationship('post');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('author_name')->required(),
            TextInput::make('body')->required(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('author_name')]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ArticleCommentResource\Pages\ListArticleComments::route('/'),
            'create' => ArticleCommentResource\Pages\CreateArticleComment::route('/create'),
            'edit' => ArticleCommentResource\Pages\EditArticleComment::route('/{record}/edit'),
        ];
    }
}
