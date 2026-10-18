<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\Standalone;

use AlfaSix\Alfakit\Actions\Action;
use AlfaSix\Alfakit\Actions\BulkAction;
use AlfaSix\Alfakit\Standalone\Table;
use AlfaSix\Alfakit\Tables\Columns\TextColumn;
use AlfaSix\Alfakit\Tables\Table as TableBuilder;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Post;
use AlfaSix\Alfakit\Tests\Fixtures\Models\User;
use Illuminate\Database\Eloquent\Collection;

/** The posts of one author, with a record action and a bulk action. */
class PostsTable extends Table
{
    public User $author;

    /** @var array<int, mixed> */
    public static array $mounted = [];

    public function mount(mixed ...$parameters): void
    {
        static::$mounted[] = $parameters;
    }

    public function table(TableBuilder $table): TableBuilder
    {
        return $table
            ->query(Post::query()->where('author_id', $this->author->getKey()))
            ->columns([
                TextColumn::make('title')->searchable()->sortable(),
                TextColumn::make('status'),
            ])
            ->recordActions([
                Action::make('publish')->action(fn (Post $record) => $record->update(['status' => 'published'])),
            ])
            ->bulkActions([
                BulkAction::make('archive')->action(fn (Collection $records) => $records->each->update(['status' => 'archived'])),
            ]);
    }
}
