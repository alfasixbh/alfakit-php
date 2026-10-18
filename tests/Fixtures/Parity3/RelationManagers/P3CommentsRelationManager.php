<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\Parity3\RelationManagers;

use AlfaSix\Alfakit\Resources\RelationManagers\RelationManager;
use AlfaSix\Alfakit\Tables\Columns\TextColumn;
use AlfaSix\Alfakit\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class P3CommentsRelationManager extends RelationManager
{
    protected static string $relationship = 'comments';

    protected static bool $isLazy = false;

    /** Set through `make(['author' => …])` in `getRelations()`. */
    public ?string $author = null;

    public function table(Table $table): Table
    {
        return $table
            ->columns([TextColumn::make('author_name'), TextColumn::make('body')])
            ->modifyQueryUsing(fn (Builder $query): Builder => $this->author === null ? $query : $query->where('author_name', $this->author));
    }
}
