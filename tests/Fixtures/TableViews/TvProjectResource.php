<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\TableViews;

use AlfaSix\Alfakit\Resources\Pages\ListRecords;
use AlfaSix\Alfakit\Resources\Resource;
use AlfaSix\Alfakit\Tables\Columns\TextColumn;
use AlfaSix\Alfakit\Tables\Columns\TextInputColumn;
use AlfaSix\Alfakit\Tables\Table;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Project;

/**
 * A tenant-scoped resource with saved views and a spreadsheet, for the tenancy tests of
 * saved table views and spreadsheet mode.
 */
class TvProjectResource extends Resource
{
    protected static ?string $model = Project::class;

    protected static ?string $slug = 'projects';

    public static function table(Table $table): Table
    {
        return $table
            ->columns([TextInputColumn::make('name')->rules(['required']), TextColumn::make('id')->sortable()])
            ->savedViews()
            ->canManageSharedViews()
            ->spreadsheet();
    }

    public static function getPages(): array
    {
        return ['index' => ListTvProjects::route('/')];
    }
}

class ListTvProjects extends ListRecords
{
    protected static string $resource = TvProjectResource::class;
}
