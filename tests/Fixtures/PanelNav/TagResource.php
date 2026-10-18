<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\PanelNav;

use AlfaSix\Alfakit\Resources\Resource;
use AlfaSix\Alfakit\Tables\Columns\TextColumn;
use AlfaSix\Alfakit\Tables\Table;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Tag;

class TagResource extends Resource
{
    protected static ?string $model = Tag::class;

    protected static ?string $cluster = Settings::class;

    protected static ?int $navigationSort = 3;

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('name')]);
    }

    public static function getPages(): array
    {
        return ['index' => ListTags::route('/')];
    }
}
