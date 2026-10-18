<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\Parity3\RelationManagers;

use AlfaSix\Alfakit\Resources\RelationManagers\RelationManager;
use AlfaSix\Alfakit\Tables\Columns\TextColumn;
use AlfaSix\Alfakit\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class P3TagsRelationManager extends RelationManager
{
    protected static string $relationship = 'tags';

    protected static bool $isLazy = false;

    protected static bool $isBadgeDeferred = true;

    public static int $badgeCalls = 0;

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        static::$badgeCalls++;

        return '42';
    }

    public function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('name')]);
    }
}
