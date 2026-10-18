<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\Parity3;

use AlfaSix\Alfakit\Forms\Components\TextInput;
use AlfaSix\Alfakit\Resources\ParentResourceRegistration;
use AlfaSix\Alfakit\Resources\Resource;
use AlfaSix\Alfakit\Schemas\Schema;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Comment;

/** Nested under posts without an index: its records are listed on `manageComments`. */
class P3PostCommentResource extends Resource
{
    protected static ?string $model = Comment::class;

    protected static ?string $slug = 'comments';

    protected static ?string $recordTitleAttribute = 'author_name';

    public static function getParentResourceRegistration(): ?ParentResourceRegistration
    {
        return P3PostResource::asParent()->relationship('comments')->inverseRelationship('post')->page('manageComments');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([TextInput::make('author_name')->required()]);
    }

    public static function getPages(): array
    {
        return [
            'edit' => CommentPages\EditP3PostComment::route('/{record}/edit'),
        ];
    }
}
