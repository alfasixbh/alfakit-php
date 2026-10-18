<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\ResourceTabs;

use AlfaSix\Alfakit\Resources\RelationManagers\RelationManager;
use AlfaSix\Alfakit\Tables\Columns\TextColumn;
use AlfaSix\Alfakit\Tables\Table;

class RtTagsRelationManager extends RelationManager
{
    protected static string $relationship = 'tags';

    protected static bool $isLazy = false;

    protected static ?string $icon = 'tag';

    protected static ?string $badge = '3';

    public function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('name')]);
    }
}
