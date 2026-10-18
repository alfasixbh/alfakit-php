<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\Tenancy\Check;

use AlfaSix\Alfakit\Forms\Components\TextInput;
use AlfaSix\Alfakit\Resources\Resource;
use AlfaSix\Alfakit\Schemas\Schema;
use AlfaSix\Alfakit\Tables\Columns\TextColumn;
use AlfaSix\Alfakit\Tables\Table;

/** Shared reference data: deliberately not scoped. */
class CurrencyResource extends Resource
{
    protected static ?string $model = Currency::class;

    protected static bool $isScopedToTenant = false;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([TextInput::make('code')]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('code')]);
    }
}
