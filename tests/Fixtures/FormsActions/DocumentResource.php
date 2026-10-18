<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\FormsActions;

use AlfaSix\Alfakit\Actions\BulkAction;
use AlfaSix\Alfakit\Forms\Components\Select;
use AlfaSix\Alfakit\Forms\Components\TextInput;
use AlfaSix\Alfakit\Resources\Resource;
use AlfaSix\Alfakit\Schemas\Components\Utilities\Get;
use AlfaSix\Alfakit\Schemas\Components\Utilities\Set;
use AlfaSix\Alfakit\Schemas\Schema;
use AlfaSix\Alfakit\Tables\Columns\TextColumn;
use AlfaSix\Alfakit\Tables\Filters\SelectFilter;
use AlfaSix\Alfakit\Tables\Table;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Post;
use Illuminate\Support\Enumerable;

class DocumentResource extends Resource
{
    protected static ?string $model = Post::class;

    protected static ?string $slug = 'documents';

    /** @var array<int, array<string, mixed>> */
    public static array $runs = [];

    public static function form(Schema $schema): Schema
    {
        return $schema->components([TextInput::make('title')->required()]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([TextColumn::make('title')->searchable()])
            ->filters([SelectFilter::make('author')->relationship('author', 'name')->searchable()])
            ->bulkActions([
                BulkAction::make('assign')
                    ->form(fn (Enumerable $records): array => [
                        Select::make('kind')
                            ->options(['a' => 'A', 'b' => 'B'])
                            ->live()
                            ->afterStateUpdated(fn (Set $set) => $set('note', 'selected '.$records->count())),
                        TextInput::make('note')->visible(fn (Get $get): bool => $get('kind') === 'b'),
                        Select::make('author')
                            ->searchable()
                            ->getSearchResultsUsing(fn (string $search): array => ['x' => "{$search} for ".$records->count()]),
                    ])
                    ->extraModalFooterActions([
                        BulkAction::make('nested')
                            ->form(fn (Enumerable $records): array => [
                                Select::make('kind')
                                    ->options(['a' => 'A', 'b' => 'B'])
                                    ->live()
                                    ->afterStateUpdated(fn (Set $set) => $set('echo', 'nested '.$records->count())),
                                TextInput::make('echo')->visible(fn (Get $get): bool => $get('kind') === 'b'),
                            ])
                            ->action(function (Enumerable $records, array $data): void {
                                static::$runs[] = ['nested' => [$records->count(), $data]];
                            }),
                    ])
                    ->action(function (Enumerable $records, array $data): void {
                        static::$runs[] = ['assign' => [$records->count(), $data]];
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => DocumentResource\Pages\ListDocuments::route('/'),
        ];
    }
}
