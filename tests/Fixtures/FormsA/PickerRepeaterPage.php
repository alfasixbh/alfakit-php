<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\FormsA;

use AlfaSix\Alfakit\Forms\Components\ModalTableSelect;
use AlfaSix\Alfakit\Forms\Components\Repeater;
use AlfaSix\Alfakit\Forms\Components\TextInput;
use AlfaSix\Alfakit\Pages\Page;
use AlfaSix\Alfakit\Schemas\Components\Utilities\Set;
use AlfaSix\Alfakit\Schemas\Schema;
use AlfaSix\Alfakit\Tables\Columns\TextColumn;
use AlfaSix\Alfakit\Tables\Filters\SelectFilter;
use AlfaSix\Alfakit\Tables\Table;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Post;
use AlfaSix\Alfakit\Tests\Fixtures\Models\User;

/**
 * A ModalTableSelect inside the items of a relationship repeater (like order lines), for
 * the picker, filter, refresh and validation endpoints with nested state paths.
 */
class PickerRepeaterPage extends Page
{
    protected static ?string $slug = 'picker-repeater';

    protected static ?string $title = 'Picker repeater';

    public function form(Schema $schema): Schema
    {
        return $schema->model(Post::class)->components([
            Repeater::make('comments')
                ->relationship()
                ->defaultItems(1)
                ->schema([
                    ModalTableSelect::make('author_name')
                        ->label('Author')
                        ->recordTitleAttribute('name')
                        ->required()
                        ->tableConfiguration(fn (Table $table): Table => $table
                            ->query(User::query()->where('name', '!=', 'Banned'))
                            ->columns([TextColumn::make('name')->searchable()])
                            ->filters([SelectFilter::make('email')->options(['admin@example.com' => 'Admin mail', 'zed@example.com' => 'Zed mail'])->searchable()]))
                        ->live(onBlur: true)
                        ->afterStateUpdated(fn (Set $set, mixed $state) => $set('body', 'Picked '.$state)),
                    TextInput::make('body'),
                ]),
        ]);
    }
}
