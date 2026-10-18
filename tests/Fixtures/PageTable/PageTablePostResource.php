<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\PageTable;

use AlfaSix\Alfakit\Resources\Resource;
use AlfaSix\Alfakit\Tables\Columns\TextColumn;
use AlfaSix\Alfakit\Tables\Filters\SelectFilter;
use AlfaSix\Alfakit\Tables\Table;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Post;

class PageTablePostResource extends Resource
{
    protected static ?string $model = Post::class;

    protected static ?string $slug = 'pt-posts';

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')->searchable(),
                TextColumn::make('status')->badge()->enum(PostStatus::class),
            ])
            ->filters([SelectFilter::make('status')->options(PostStatus::class)])
            ->defaultPaginationPageOption(2);
    }

    public static function getPages(): array
    {
        return ['index' => ListPageTablePosts::route('/')];
    }
}
