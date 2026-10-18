<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\Tenancy\Check;

use AlfaSix\Alfakit\Actions\AttachAction;
use AlfaSix\Alfakit\Forms\Components\CheckboxList;
use AlfaSix\Alfakit\Forms\Components\Select;
use AlfaSix\Alfakit\Forms\Components\TextInput;
use AlfaSix\Alfakit\Resources\RelationManagers\RelationManager;
use AlfaSix\Alfakit\Resources\Resource;
use AlfaSix\Alfakit\Schemas\Schema;
use AlfaSix\Alfakit\Tables\Columns\TextColumn;
use AlfaSix\Alfakit\Tables\Filters\SelectFilter;
use AlfaSix\Alfakit\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\DB;

class InvoiceResource extends Resource
{
    protected static ?string $model = Invoice::class;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('number')->required(),
            Select::make('team_id')->relationship('team'),
            Select::make('currency_id')->relationship('currency', 'code'),
            CheckboxList::make('tags')->relationship('tags'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([TextColumn::make('number')])
            ->filters([SelectFilter::make('currency')->relationship('currency', 'code')]);
    }

    public static function getRelations(): array
    {
        return [TagsRelationManager::class];
    }

    public static function getEloquentQuery(): Builder
    {
        // Dropping only the soft-delete scope is fine.
        return parent::getEloquentQuery()->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    public static function totals(): int
    {
        return (int) DB::table('invoices')->sum('total');
    }
}

class TagsRelationManager extends RelationManager
{
    protected static string $relationship = 'tags';

    public function table(Table $table): Table
    {
        return $table
            ->columns([TextColumn::make('name')])
            ->headerActions([AttachAction::make()]);
    }
}
