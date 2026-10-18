<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\TableParity;

use AlfaSix\Alfakit\Resources\Resource;
use AlfaSix\Alfakit\Tables\Columns\TextColumn;
use AlfaSix\Alfakit\Tables\Table;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Post;
use Closure;

class TpPostResource extends Resource
{
    protected static ?string $model = Post::class;

    protected static ?string $slug = 'posts';

    /** The table each test configures: `fn (Table $table): Table`. */
    public static ?Closure $table = null;

    public static function table(Table $table): Table
    {
        $table->columns([TextColumn::make('title')])->defaultSort('id');

        return static::$table === null ? $table : (static::$table)($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => TpListPosts::route('/'),
        ];
    }
}
