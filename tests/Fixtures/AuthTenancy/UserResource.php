<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\AuthTenancy;

use AlfaSix\Alfakit\Actions\ImpersonateAction;
use AlfaSix\Alfakit\Resources\Pages\ListRecords;
use AlfaSix\Alfakit\Resources\Resource;
use AlfaSix\Alfakit\Tables\Columns\TextColumn;
use AlfaSix\Alfakit\Tables\Table;
use AlfaSix\Alfakit\Tests\Fixtures\Models\MfaUser;

class UserResource extends Resource
{
    protected static ?string $model = MfaUser::class;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $slug = 'users';

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('name')])->recordActions([ImpersonateAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ListUsers::route('/')];
    }
}

class ListUsers extends ListRecords
{
    protected static string $resource = UserResource::class;
}
