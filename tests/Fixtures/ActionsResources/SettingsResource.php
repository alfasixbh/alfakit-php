<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\ActionsResources;

use AlfaSix\Alfakit\Forms\Components\TextInput;
use AlfaSix\Alfakit\Resources\Resource;
use AlfaSix\Alfakit\Schemas\Schema;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Category;

class SettingsResource extends Resource
{
    protected static ?string $model = Category::class;

    protected static ?string $slug = 'settings';

    protected static ?string $navigationLabel = 'Settings';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required(),
            TextInput::make('slug')->required(),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => SettingsResource\Pages\ManageSettings::route('/')];
    }
}
