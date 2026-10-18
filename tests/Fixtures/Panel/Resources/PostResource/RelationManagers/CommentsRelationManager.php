<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\Panel\Resources\PostResource\RelationManagers;

use AlfaSix\Alfakit\Resources\RelationManagers\RelationManager;
use AlfaSix\Alfakit\Tables\Columns\TextColumn;
use AlfaSix\Alfakit\Tables\Table;

class CommentsRelationManager extends RelationManager
{
    protected static string $relationship = 'comments';

    public function table(Table $table): Table
    {
        return $table
            ->columns([TextColumn::make('author_name')->sortable(), TextColumn::make('body')])
            ->defaultSort('author_name');
    }
}
