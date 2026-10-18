<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\PageParity;

use AlfaSix\Alfakit\Actions\Action;
use AlfaSix\Alfakit\Tables\Columns\TextColumn;
use AlfaSix\Alfakit\Tables\Table;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Comment;
use Illuminate\Database\Eloquent\Model;

class ParityCommentsRelationManager extends \AlfaSix\Alfakit\Resources\RelationManagers\RelationManager
{
    protected static string $relationship = 'comments';

    protected static bool $isLazy = true;

    protected static ?string $badgeColor = 'danger';

    protected static ?string $badgeTooltip = 'Unanswered comments';

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        return (string) $ownerRecord->comments()->count();
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([TextColumn::make('author_name')->searchable(), TextColumn::make('body')])
            ->defaultSort('author_name')
            ->recordActions([
                Action::make('shout')->action(fn (Comment $record) => $record->update(['body' => strtoupper($record->body)])),
            ]);
    }

    public function isReadOnly(): bool
    {
        return false;
    }
}
