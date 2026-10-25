<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\Undo;

use AlfaSix\Alfakit\Actions\Action;
use AlfaSix\Alfakit\Actions\DeleteAction;
use AlfaSix\Alfakit\Actions\DeleteBulkAction;
use AlfaSix\Alfakit\Actions\EditAction;
use AlfaSix\Alfakit\Forms\Components\TextInput;
use AlfaSix\Alfakit\Resources\Resource;
use AlfaSix\Alfakit\Schemas\Schema;
use AlfaSix\Alfakit\Tables\Columns\TextColumn;
use AlfaSix\Alfakit\Tables\Table;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Post;
use Illuminate\Database\Eloquent\Model;

class UndoPostResource extends Resource
{
    protected static ?string $model = Post::class;

    protected static ?string $slug = 'undo-posts';

    /** Tests flip this to refuse the policy check on undo. */
    public static bool $canDelete = true;

    public static bool $canUpdate = true;

    public static bool $canPublish = true;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('title')->required(),
            TextInput::make('status'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([TextColumn::make('title')])
            ->recordActions([
                EditAction::make()->form([TextInput::make('title')->required(), TextInput::make('status')])->undoable(),
                DeleteAction::make()->undoable(),
                Action::make('publish')
                    ->authorize(fn (): bool => static::$canPublish)
                    ->action(function (Post $record, Action $action): void {
                        $action->undoData(['previous' => $record->status]);
                        $record->update(['status' => 'published']);
                    })
                    ->undoable(fn (Post $record, array $undoData) => $record->update(['status' => $undoData['previous']]), seconds: 5),
                Action::make('plain')->action(fn (Post $record) => $record->update(['status' => 'plain'])),
            ])
            ->bulkActions([DeleteBulkAction::make()->undoable()]);
    }

    public static function can(string $action, ?Model $record = null): bool
    {
        return match ($action) {
            'delete', 'deleteAny' => static::$canDelete,
            'update' => static::$canUpdate,
            default => true,
        };
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUndoPosts::route('/'),
            'edit' => EditUndoPost::route('/{record}/edit'),
        ];
    }
}
