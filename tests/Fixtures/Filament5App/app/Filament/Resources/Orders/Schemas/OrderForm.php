<?php

declare(strict_types=1);

namespace App\Filament\Resources\Orders\Schemas;

use App\Enums\OrderStatus;
use App\Models\Product;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\MarkdownEditor;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Operation;

class OrderForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Order')
                    ->schema(static::getDetailsComponents())
                    ->columns(2),
                Section::make('Order items')
                    ->headerActions([
                        Action::make('reset')
                            ->modalHeading('Are you sure?')
                            ->modalDescription('All existing items will be removed from the order.')
                            ->requiresConfirmation()
                            ->color('danger')
                            ->action(fn (Set $set) => $set('items', [])),
                    ])
                    ->schema([
                        static::getItemsRepeater(),
                    ]),
            ])
            ->columns(1);
    }

    /**
     * @return array<\Filament\Schemas\Components\Component>
     */
    public static function getDetailsComponents(): array
    {
        return [
            TextInput::make('number')
                ->default('OR-'.random_int(100000, 999999))
                ->disabled()
                ->dehydrated()
                ->required()
                ->maxLength(32)
                ->unique(ignoreRecord: true),
            Select::make('customer_id')
                ->relationship('customer', 'name')
                ->searchable()
                ->required()
                ->createOptionForm([
                    TextInput::make('name')->required()->maxLength(255),
                    TextInput::make('email')->label('Email address')->required()->email()->maxLength(255)->unique(),
                    TextInput::make('phone')->maxLength(255),
                ])
                ->createOptionAction(fn (Action $action): Action => $action->modalHeading('Create customer')->modalSubmitActionLabel('Create customer')->modalWidth('lg')),
            ToggleButtons::make('status')
                ->inline()
                ->options(OrderStatus::class)
                ->required(),
            Select::make('currency')
                ->searchable()
                ->options(['usd' => 'US Dollar', 'eur' => 'Euro', 'gbp' => 'Pound sterling'])
                ->default('usd')
                ->required(),
            Radio::make('shipping_method')
                ->options(['standard' => 'Standard', 'express' => 'Express'])
                ->descriptions(['standard' => '3 to 5 days', 'express' => 'Next day']),
            DateTimePicker::make('shipped_at')
                ->visibleOn(Operation::Edit)
                ->seconds(false),
            Section::make('Address')
                ->schema([
                    TextInput::make('street'),
                    TextInput::make('city'),
                    Select::make('country')
                        ->options(['us' => 'United States', 'gb' => 'United Kingdom', 'de' => 'Germany'])
                        ->searchable(),
                ])
                ->columns(3)
                ->columnSpanFull(),
            MarkdownEditor::make('notes')
                ->columnSpanFull(),
        ];
    }

    public static function getItemsRepeater(): Repeater
    {
        return Repeater::make('items')
            ->relationship()
            ->schema([
                Select::make('product_id')
                    ->label('Product')
                    ->options(Product::query()->pluck('name', 'id'))
                    ->required()
                    ->reactive()
                    ->afterStateUpdated(fn ($state, Set $set) => $set('unit_price', Product::query()->whereKey($state)->value('price') ?? 0))
                    ->distinct()
                    ->disableOptionsWhenSelectedInSiblingRepeaterItems()
                    ->columnSpan(['md' => 5])
                    ->searchable(),
                TextInput::make('qty')
                    ->label('Quantity')
                    ->numeric()
                    ->default(1)
                    ->columnSpan(['md' => 2])
                    ->required(),
                TextInput::make('unit_price')
                    ->label('Unit price')
                    ->disabled()
                    ->dehydrated()
                    ->numeric()
                    ->required()
                    ->columnSpan(['md' => 3]),
                Hidden::make('sort'),
            ])
            ->extraItemActions([
                Action::make('openProduct')
                    ->tooltip('Open product')
                    ->icon('heroicon-m-arrow-top-right-on-square')
                    ->url(function (array $arguments, Repeater $component): ?string {
                        $itemData = $component->getRawItemState($arguments['item']);
                        $product = Product::find($itemData['product_id'] ?? null);

                        return $product === null ? null : route('filament.admin.resources.products.edit', ['record' => $product]);
                    }, shouldOpenInNewTab: true),
            ])
            ->orderColumn('sort')
            ->defaultItems(1)
            ->hiddenLabel()
            ->columns(['md' => 10])
            ->live()
            ->afterStateUpdated(function (Get $get, Set $set): void {
                $total = collect($get('items') ?? [])->sum(fn (array $item): float => (float) ($item['qty'] ?? 0) * (float) ($item['unit_price'] ?? 0));
                $set('total_price', round($total, 2));
            })
            ->required();
    }
}
