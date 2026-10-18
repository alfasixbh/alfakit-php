<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\ActionsResources\ArticleResource\RelationManagers;

use AlfaSix\Alfakit\Actions\CreateAction;
use AlfaSix\Alfakit\Actions\EditAction;
use AlfaSix\Alfakit\Forms\Components\TextInput;
use AlfaSix\Alfakit\Resources\RelationManagers\RelationManager;
use AlfaSix\Alfakit\Schemas\Schema;
use AlfaSix\Alfakit\Tables\Columns\TextColumn;
use AlfaSix\Alfakit\Tables\Table;
use AlfaSix\Alfakit\Tests\Fixtures\ActionsResources\ArticleCommentResource;

class CommentsRelationManager extends RelationManager
{
    protected static string $relationship = 'comments';

    protected static ?string $relatedResource = ArticleCommentResource::class;

    protected static bool $isLazy = false;

    // A form on the manager must not turn the linked create / edit actions back into modals.
    public function form(Schema $schema): Schema
    {
        return $schema->components([TextInput::make('author_name')->required()]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([TextColumn::make('author_name')])
            ->headerActions([CreateAction::make()])
            ->recordActions([EditAction::make()]);
    }
}
