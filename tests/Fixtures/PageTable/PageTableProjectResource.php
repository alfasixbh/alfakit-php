<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\PageTable;

use AlfaSix\Alfakit\Resources\Resource;
use AlfaSix\Alfakit\Tables\Columns\TextColumn;
use AlfaSix\Alfakit\Tables\Table;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Project;

class PageTableProjectResource extends Resource
{
    protected static ?string $model = Project::class;

    protected static ?string $slug = 'pt-projects';

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('name')->searchable()]);
    }

    public static function getPages(): array
    {
        return ['index' => ListPageTableProjects::route('/')];
    }
}
