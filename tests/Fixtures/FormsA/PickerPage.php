<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\FormsA;

use AlfaSix\Alfakit\Forms\Components\ModalTableSelect;
use AlfaSix\Alfakit\Pages\Page;
use AlfaSix\Alfakit\Schemas\Schema;
use AlfaSix\Alfakit\Tables\Columns\TextColumn;
use AlfaSix\Alfakit\Tables\Filters\TernaryFilter;
use AlfaSix\Alfakit\Tables\Table;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Post;

/**
 * A page whose form holds ModalTableSelect fields, for the picker endpoint tests.
 */
class PickerPage extends Page
{
    protected static ?string $slug = 'picker';

    protected static ?string $title = 'Picker';

    public function form(Schema $schema): Schema
    {
        return $schema->model(Post::class)->components([
            ModalTableSelect::make('author_id')
                ->relationship('author', 'name')
                ->tableConfiguration(PickerAuthorsTable::class),
            ModalTableSelect::make('post_ids')
                ->multiple()
                ->maxItems(2)
                ->recordTitleAttribute('title')
                ->tableConfiguration(fn (Table $table): Table => $table
                    ->query(Post::query()->where('title', '!=', 'Secret'))
                    ->columns([TextColumn::make('title')->searchable()->sortable()])
                    ->filters([TernaryFilter::make('is_published')])
                    ->defaultPaginationPageOption(2)),
            ModalTableSelect::make('locked_id')->relationship('author', 'name')->disabled(),
            ModalTableSelect::make('hidden_id')->relationship('author', 'name')->hidden(),
        ]);
    }
}
