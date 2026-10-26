<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\Tenancy;

use AlfaSix\Alfakit\Actions\CreateAction;
use AlfaSix\Alfakit\Forms\Components\TextInput;
use AlfaSix\Alfakit\Resources\Pages\ListRecords;
use AlfaSix\Alfakit\Resources\Resource;
use AlfaSix\Alfakit\Schemas\Schema;
use AlfaSix\Alfakit\Tables\Columns\TextColumn;
use AlfaSix\Alfakit\Tables\Table;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Project;

/** A simple (modal CRUD) resource on the tenancy panel: creating goes through CreateAction. */
class SimpleProjectResource extends Resource
{
    protected static ?string $model = Project::class;

    protected static ?string $slug = 'simple-projects';

    protected static bool $shouldRegisterNavigation = false;

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
            'index' => ManageSimpleProjects::route('/'),
        ];
    }
}

class ManageSimpleProjects extends ListRecords
{
    protected static string $resource = SimpleProjectResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->form(fn () => static::getResource()::form(Schema::make())->getComponents(withHidden: true))];
    }
}
