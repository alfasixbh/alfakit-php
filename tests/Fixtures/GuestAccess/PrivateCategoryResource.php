<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\GuestAccess;

use AlfaSix\Alfakit\Forms\Components\TextInput;
use AlfaSix\Alfakit\Resources\Pages\ListRecords;
use AlfaSix\Alfakit\Resources\Resource;
use AlfaSix\Alfakit\Schemas\Schema;
use AlfaSix\Alfakit\Tables\Columns\TextColumn;
use AlfaSix\Alfakit\Tables\Table;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Category;

/** Not guest-accessible (and without a policy, so everyone signed in may use it). */
class PrivateCategoryResource extends Resource
{
    protected static ?string $model = Category::class;

    protected static ?string $slug = 'private-categories';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([TextInput::make('name')]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('name')]);
    }

    public static function getPages(): array
    {
        return ['index' => ListPrivateCategories::route('/')];
    }
}

class ListPrivateCategories extends ListRecords
{
    protected static string $resource = PrivateCategoryResource::class;
}
