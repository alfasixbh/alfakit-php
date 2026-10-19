<?php

declare(strict_types=1);

namespace App\Filament\Pages\Tenancy;

use App\Models\Shop;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Tenancy\RegisterTenant;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class RegisterShop extends RegisterTenant
{
    public static function getLabel(): string
    {
        return 'Register shop';
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')->required()->maxLength(255),
            ]);
    }

    protected function handleRegistration(array $data): Model
    {
        $shop = Shop::create([...$data, 'slug' => Str::slug($data['name'])]);

        $shop->members()->attach(auth()->user());

        return $shop;
    }
}
