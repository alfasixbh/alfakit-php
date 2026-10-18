<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\Parity3\Pages;

use AlfaSix\Alfakit\Resources\Pages\ManageRelatedRecords;
use AlfaSix\Alfakit\Tables\Columns\TextColumn;
use AlfaSix\Alfakit\Tables\Table;
use AlfaSix\Alfakit\Tests\Fixtures\Parity3\P3PostResource;

class ManageP3PostComments extends ManageRelatedRecords
{
    protected static string $resource = P3PostResource::class;

    protected static string $relationship = 'comments';

    public function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('author_name')]);
    }
}
