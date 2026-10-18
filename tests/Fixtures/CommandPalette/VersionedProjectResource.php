<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\CommandPalette;

use AlfaSix\Alfakit\Forms\Components\TextInput;
use AlfaSix\Alfakit\Resources\Pages\EditRecord;
use AlfaSix\Alfakit\Resources\Pages\ListRecords;
use AlfaSix\Alfakit\Resources\Resource;
use AlfaSix\Alfakit\Schemas\Schema;
use AlfaSix\Alfakit\Tables\Columns\TextColumn;
use AlfaSix\Alfakit\Tables\Table;

class VersionedProjectResource extends Resource
{
    protected static ?string $model = VersionedProject::class;

    protected static ?string $slug = 'versioned-projects';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $tenantOwnershipRelationshipName = 'team';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([TextInput::make('name')->required()]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('name')]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListVersionedProjects::route('/'),
            'edit' => EditVersionedProject::route('/{record}/edit'),
        ];
    }
}

class ListVersionedProjects extends ListRecords
{
    protected static string $resource = VersionedProjectResource::class;
}

class EditVersionedProject extends EditRecord
{
    protected static string $resource = VersionedProjectResource::class;
}
