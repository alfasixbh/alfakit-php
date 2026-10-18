<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\GuestAccess;

use AlfaSix\Alfakit\Resources\Pages\ListRecords;
use AlfaSix\Alfakit\Resources\Resource;
use AlfaSix\Alfakit\Tables\Columns\TextColumn;
use AlfaSix\Alfakit\Tables\Table;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Project;

/** A tenant's public project list (guest access on a tenancy panel). */
class PublicProjectResource extends Resource
{
    protected static ?string $model = Project::class;

    protected static ?string $slug = 'public-projects';

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('name')]);
    }

    public static function getPages(): array
    {
        return ['index' => ListPublicProjects::route('/')];
    }
}

class ListPublicProjects extends ListRecords
{
    protected static string $resource = PublicProjectResource::class;

    protected static bool $isGuestAccessible = true;
}
