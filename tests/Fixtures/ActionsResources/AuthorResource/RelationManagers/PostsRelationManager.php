<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\ActionsResources\AuthorResource\RelationManagers;

use AlfaSix\Alfakit\Actions\DissociateBulkAction;
use AlfaSix\Alfakit\Resources\RelationManagers\RelationManager;
use AlfaSix\Alfakit\Tables\Columns\TextColumn;
use AlfaSix\Alfakit\Tables\Table;

class PostsRelationManager extends RelationManager
{
    protected static string $relationship = 'posts';

    protected static bool $isLazy = false;

    public function table(Table $table): Table
    {
        return $table
            ->columns([TextColumn::make('title')])
            ->bulkActions([DissociateBulkAction::make()]);
    }
}
