<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\ActionsParity;

use AlfaSix\Alfakit\Pages\Page;
use AlfaSix\Alfakit\Tables\Columns\SelectColumn;
use AlfaSix\Alfakit\Tables\Columns\Summarizers\Range;
use AlfaSix\Alfakit\Tables\Columns\Summarizers\Sum;
use AlfaSix\Alfakit\Tables\Columns\TextColumn;
use AlfaSix\Alfakit\Tables\Filters\Filter;
use AlfaSix\Alfakit\Tables\Filters\SelectFilter;
use AlfaSix\Alfakit\Tables\Table;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Post;
use Illuminate\Database\Eloquent\Builder;

class ParityTablePage extends Page
{
    protected static ?string $slug = 'parity-table';

    public function table(Table $table): Table
    {
        return $table
            ->query(Post::query())
            ->defaultSort('id')
            ->paginationPageOptions([2, 5])
            ->defaultPaginationPageOption(2)
            ->columns([
                TextColumn::make('title')
                    ->formatStateUsing(fn (string $state): string => strtoupper($state))
                    ->description(fn (Post $record): string => "Slug {$record->slug}")
                    ->extraCellAttributes(['class' => 'font-bold text-sm', 'data-kind' => 'title']),
                TextColumn::make('rating')->summarize([Sum::make('total'), Range::make()]),
                TextColumn::make('slug')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('content')->hidden(),
                SelectColumn::make('status')->options(['draft' => 'Draft', 'live' => 'Live']),
            ])
            ->filters([
                Filter::make('published')->query(fn (Builder $query): Builder => $query->where('is_published', true)),
                SelectFilter::make('status')->options(['draft' => 'Draft', 'live' => 'Live'])->default(['value' => 'draft']),
                Filter::make('secret')->hidden(),
            ]);
    }
}
