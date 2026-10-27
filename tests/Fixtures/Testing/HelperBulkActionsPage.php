<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\Testing;

use AlfaSix\Alfakit\Actions\BulkAction;
use AlfaSix\Alfakit\Forms\Components\Textarea;
use AlfaSix\Alfakit\Forms\Components\TextInput;
use AlfaSix\Alfakit\Pages\Page;
use AlfaSix\Alfakit\Schemas\Components\Utilities\Set;
use AlfaSix\Alfakit\Tables\Columns\TextColumn;
use AlfaSix\Alfakit\Tables\Table;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Post;
use Illuminate\Support\Collection;

/** A table page whose bulk action has a modal form (an approval comment). */
class HelperBulkActionsPage extends Page
{
    protected static ?string $slug = 'helper-bulk-actions';

    /** @var array<int, array<string, mixed>> */
    public static array $runs = [];

    public function table(Table $table): Table
    {
        return $table
            ->query(Post::query())
            ->columns([TextColumn::make('title')->searchable()])
            ->bulkActions([
                BulkAction::make('approveSelected')
                    ->form([
                        Textarea::make('comment')->maxLength(100),
                        TextInput::make('summary')
                            ->live()
                            ->afterStateUpdated(fn (Set $set, ?string $state) => $set('comment', 'Re: '.$state)),
                    ])
                    ->action(function (Collection $records, array $data): void {
                        $records->each->update(['status' => 'published']);
                        static::$runs[] = ['ids' => $records->modelKeys(), 'data' => $data];
                    }),
            ]);
    }
}
