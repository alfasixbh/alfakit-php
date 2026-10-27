<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\Tenancy\Check;

use AlfaSix\Alfakit\Forms\Components\Repeater;
use AlfaSix\Alfakit\Forms\Components\Select;
use AlfaSix\Alfakit\Forms\Components\TextInput;
use AlfaSix\Alfakit\Resources\Resource;
use AlfaSix\Alfakit\Schemas\Schema;
use AlfaSix\Alfakit\Tables\Columns\TextColumn;
use AlfaSix\Alfakit\Tables\Table;

/** Relationship fields inside a relationship repeater belong to the repeater's model. */
class InvoiceLinesResource extends Resource
{
    protected static ?string $model = Invoice::class;

    protected static ?string $slug = 'invoice-lines';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('number')->required(),
            Repeater::make('lines')->relationship()->schema([
                Select::make('currency_id')->relationship('currency', 'code'),
                Select::make('note_id')->relationship('note', 'body'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('number')]);
    }
}
