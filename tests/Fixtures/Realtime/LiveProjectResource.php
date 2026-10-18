<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\Realtime;

use AlfaSix\Alfakit\Forms\Components\TextInput;
use AlfaSix\Alfakit\Resources\Pages\EditRecord;
use AlfaSix\Alfakit\Resources\Pages\ListRecords;
use AlfaSix\Alfakit\Resources\Resource;
use AlfaSix\Alfakit\Schemas\Schema;
use AlfaSix\Alfakit\Tables\Columns\TextColumn;
use AlfaSix\Alfakit\Tables\Table;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Project;

class LiveProjectResource extends Resource
{
    protected static ?string $model = Project::class;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([TextInput::make('name')->required()]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('name')])->live();
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLiveProjects::route('/'),
            'edit' => EditLiveProject::route('/{record}/edit'),
        ];
    }
}

class ListLiveProjects extends ListRecords
{
    protected static string $resource = LiveProjectResource::class;
}

class EditLiveProject extends EditRecord
{
    protected static string $resource = LiveProjectResource::class;
}
