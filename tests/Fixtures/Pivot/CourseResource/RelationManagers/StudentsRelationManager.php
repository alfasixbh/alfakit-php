<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\Pivot\CourseResource\RelationManagers;

use AlfaSix\Alfakit\Actions\AttachAction;
use AlfaSix\Alfakit\Actions\CreateAction;
use AlfaSix\Alfakit\Actions\EditAction;
use AlfaSix\Alfakit\Actions\ViewAction;
use AlfaSix\Alfakit\Forms\Components\Select;
use AlfaSix\Alfakit\Forms\Components\TextInput;
use AlfaSix\Alfakit\Resources\RelationManagers\RelationManager;
use AlfaSix\Alfakit\Schemas\Schema;
use AlfaSix\Alfakit\Tables\Columns\TextColumn;
use AlfaSix\Alfakit\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class StudentsRelationManager extends RelationManager
{
    protected static string $relationship = 'students';

    protected static ?string $recordTitleAttribute = 'name';

    protected static bool $isLazy = false;

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required(),
            // `withPivot()` columns: saved to, and filled from, the pivot row.
            TextInput::make('role'),
            TextInput::make('seat')->numeric(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name'),
                TextColumn::make('role'),
                TextColumn::make('pivot.seat'),
            ])
            ->headerActions([
                CreateAction::make(),
                AttachAction::make()->form([TextInput::make('role')]),
                AttachAction::make('enrolAgain')
                    ->allowDuplicates()
                    ->recordSelectSearchColumns(['name', 'email'])
                    ->recordSelect(fn (Select $select): Select => $select->placeholder('Pick a student'))
                    ->recordSelectOptionsQuery(fn (Builder $query): Builder => $query->where('name', '!=', 'Hidden')),
                AttachAction::make('attachCustom')
                    ->schema(fn (AttachAction $action): array => [$action->getRecordSelect(), TextInput::make('seat')]),
            ])
            ->recordActions([
                EditAction::make(),
                ViewAction::make(),
            ]);
    }
}
