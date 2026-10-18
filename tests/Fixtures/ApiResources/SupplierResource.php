<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\ApiResources;

use AlfaSix\Alfakit\Actions\DeleteAction;
use AlfaSix\Alfakit\Actions\DeleteBulkAction;
use AlfaSix\Alfakit\Actions\EditAction;
use AlfaSix\Alfakit\Forms\Components\TextInput;
use AlfaSix\Alfakit\Infolists\Components\TextEntry;
use AlfaSix\Alfakit\Resources\ApiResource;
use AlfaSix\Alfakit\Resources\Pages\Page;
use AlfaSix\Alfakit\Schemas\Schema;
use AlfaSix\Alfakit\Tables\Columns\TextColumn;
use AlfaSix\Alfakit\Tables\Filters\SelectFilter;
use AlfaSix\Alfakit\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class SupplierResource extends ApiResource
{
    protected static ?string $dataSource = SupplierSource::class;

    protected static ?string $recordTitleAttribute = 'name';

    public static bool $canCreate = true;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(50),
            TextInput::make('country')->required(),
            TextInput::make('rating')->integer()->minValue(1)->maxValue(5),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('name'),
            TextEntry::make('country'),
            TextEntry::make('rating'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('country')->searchable(),
                TextColumn::make('rating')->sortable(),
            ])
            ->filters([
                SelectFilter::make('country')->options(['Bahrain' => 'Bahrain', 'France' => 'France', 'Lebanon' => 'Lebanon']),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()])
            ->bulkActions([DeleteBulkAction::make()])
            ->paginationPageOptions([2, 10]);
    }

    public static function getRelations(): array
    {
        return [ContactsRelationManager::class];
    }

    public static function canCreate(): bool
    {
        return static::$canCreate;
    }

    // Locked suppliers can't be deleted.
    public static function canDelete(Model $record): bool
    {
        return ! $record['locked'];
    }

    public static function getRecordSubNavigation(Page $page): array
    {
        return $page->generateNavigationItems([Pages\ViewSupplier::class, Pages\EditSupplier::class]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSuppliers::route('/'),
            'create' => Pages\CreateSupplier::route('/create'),
            'view' => Pages\ViewSupplier::route('/{record}'),
            'edit' => Pages\EditSupplier::route('/{record}/edit'),
        ];
    }
}
