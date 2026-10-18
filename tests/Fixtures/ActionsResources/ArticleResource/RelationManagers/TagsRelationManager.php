<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\ActionsResources\ArticleResource\RelationManagers;

use AlfaSix\Alfakit\Actions\DetachBulkAction;
use AlfaSix\Alfakit\Resources\RelationManagers\RelationManager;
use AlfaSix\Alfakit\Tables\Columns\TextColumn;
use AlfaSix\Alfakit\Tables\Table;

class TagsRelationManager extends RelationManager
{
    protected static string $relationship = 'tags';

    protected static bool $isLazy = false;

    public function table(Table $table): Table
    {
        return $table
            ->columns([TextColumn::make('name')])
            ->bulkActions([DetachBulkAction::make()]);
    }
}
