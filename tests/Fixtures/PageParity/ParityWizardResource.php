<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\PageParity;

use AlfaSix\Alfakit\Resources\Resource;
use AlfaSix\Alfakit\Tables\Columns\TextColumn;
use AlfaSix\Alfakit\Tables\Table;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Post;

class ParityWizardResource extends Resource
{
    protected static ?string $model = Post::class;

    protected static ?string $slug = 'wizard-posts';

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('title')]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListWizardPosts::route('/'),
            'create' => Pages\CreateWizardPost::route('/create'),
        ];
    }
}
