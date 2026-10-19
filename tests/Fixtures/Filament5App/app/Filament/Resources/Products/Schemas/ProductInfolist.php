<?php

declare(strict_types=1);

namespace App\Filament\Resources\Products\Schemas;

use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\TextSize;

class ProductInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Product')
                    ->schema([
                        TextEntry::make('name')
                            ->size(TextSize::Large)
                            ->weight(FontWeight::Bold),
                        TextEntry::make('brand.name')
                            ->badge(),
                        TextEntry::make('price')
                            ->money('usd'),
                        TextEntry::make('qty')
                            ->numeric()
                            ->color(fn (int $state): string => $state < 10 ? 'danger' : 'success'),
                        IconEntry::make('is_visible')
                            ->boolean(),
                        TextEntry::make('published_at')
                            ->date(),
                        TextEntry::make('description')
                            ->html()
                            ->columnSpanFull(),
                    ])
                    ->columns(3),
                Fieldset::make('Media')
                    ->schema([
                        ImageEntry::make('image')
                            ->imageHeight(160),
                        KeyValueEntry::make('attributes'),
                    ]),
            ]);
    }
}
