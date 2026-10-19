<?php

declare(strict_types=1);

namespace App\Filament\Pages\Tenancy;

use Filament\Forms\Components\TextInput;
use Filament\Pages\Tenancy\EditTenantProfile;
use Filament\Schemas\Schema;

class EditShopProfile extends EditTenantProfile
{
    public static function getLabel(): string
    {
        return 'Shop profile';
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')->required()->maxLength(255),
                TextInput::make('slug')->required()->alphaDash(),
            ]);
    }
}
