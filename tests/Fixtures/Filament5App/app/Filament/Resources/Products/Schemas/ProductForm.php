<?php

declare(strict_types=1);

namespace App\Filament\Resources\Products\Schemas;

use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\MarkdownEditor;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Flex;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Operation;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Str;

class ProductForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Group::make()
                    ->schema([
                        Section::make()
                            ->schema([
                                TextInput::make('name')
                                    ->required()
                                    ->maxLength(255)
                                    ->live(onBlur: true)
                                    ->afterStateUpdated(function (string $operation, ?string $state, Set $set): void {
                                        if ($operation !== 'create') {
                                            return;
                                        }

                                        $set('slug', Str::slug((string) $state));
                                    }),
                                TextInput::make('slug')
                                    ->disabled()
                                    ->dehydrated()
                                    ->required()
                                    ->unique(ignoreRecord: true),
                                RichEditor::make('description')
                                    ->columnSpanFull()
                                    ->toolbarButtons([
                                        ['bold', 'italic', 'underline', 'link'],
                                        ['h2', 'h3', 'bulletList', 'orderedList'],
                                        ['undo', 'redo'],
                                    ]),
                            ])
                            ->columns(2),
                        Section::make('Images')
                            ->icon(Heroicon::OutlinedPhoto)
                            ->schema([
                                FileUpload::make('image')
                                    ->image()
                                    ->imageEditor()
                                    ->directory('products')
                                    ->hiddenLabel(),
                            ])
                            ->collapsible(),
                        Section::make('Pricing')
                            ->schema([
                                TextInput::make('price')
                                    ->numeric()
                                    ->prefix('$')
                                    ->rules(['regex:/^\d{1,6}(\.\d{0,2})?$/'])
                                    ->required(),
                                TextInput::make('old_price')
                                    ->label('Compare at price')
                                    ->numeric()
                                    ->prefix('$'),
                                TextInput::make('cost')
                                    ->label('Cost per item')
                                    ->helperText('Customers won\'t see this price.')
                                    ->numeric()
                                    ->prefix('$'),
                            ])
                            ->columns(3),
                        Tabs::make('Details')
                            ->tabs([
                                Tab::make('Inventory')
                                    ->icon(Heroicon::OutlinedArchiveBox)
                                    ->schema([
                                        TextInput::make('sku')
                                            ->label('SKU (Stock Keeping Unit)')
                                            ->unique(ignoreRecord: true)
                                            ->maxLength(255)
                                            ->required(),
                                        TextInput::make('barcode')
                                            ->label('Barcode (ISBN, UPC, GTIN, etc.)')
                                            ->maxLength(255),
                                        TextInput::make('qty')
                                            ->label('Quantity')
                                            ->numeric()
                                            ->rules(['integer', 'min:0'])
                                            ->required(),
                                        TextInput::make('security_stock')
                                            ->helperText('The safety stock is the limit stock for your products which alerts you if the product stock will soon be out of stock.')
                                            ->numeric()
                                            ->rules(['integer', 'min:0'])
                                            ->required(),
                                    ])
                                    ->columns(2),
                                Tab::make('Shipping')
                                    ->schema([
                                        Checkbox::make('backorder')
                                            ->label('This product can be returned'),
                                        ToggleButtons::make('type')
                                            ->options([
                                                'deliverable' => 'Deliverable',
                                                'downloadable' => 'Downloadable',
                                            ])
                                            ->icons([
                                                'deliverable' => Heroicon::OutlinedTruck,
                                                'downloadable' => Heroicon::OutlinedArrowDownTray,
                                            ])
                                            ->inline()
                                            ->default('deliverable'),
                                    ]),
                                Tab::make('Attributes')
                                    ->schema([
                                        KeyValue::make('attributes')
                                            ->keyLabel('Attribute')
                                            ->valueLabel('Value')
                                            ->reorderable(),
                                        ColorPicker::make('color'),
                                        MarkdownEditor::make('care_instructions')
                                            ->visibleOn(Operation::Edit),
                                    ]),
                            ]),
                    ])
                    ->columnSpan(['lg' => 2]),
                Group::make()
                    ->schema([
                        Section::make('Status')
                            ->schema([
                                Toggle::make('is_visible')
                                    ->label('Visible')
                                    ->helperText('This product will be hidden from all sales channels.')
                                    ->default(true),
                                Toggle::make('featured'),
                                DatePicker::make('published_at')
                                    ->label('Availability')
                                    ->default(now())
                                    ->required(),
                            ]),
                        Section::make('Associations')
                            ->schema([
                                Select::make('brand_id')
                                    ->relationship('brand', 'name')
                                    ->searchable()
                                    ->preload()
                                    ->createOptionForm([
                                        TextInput::make('name')->required(),
                                    ])
                                    ->hiddenOn(Operation::View),
                                TagsInput::make('tags')
                                    ->suggestions(['new', 'sale', 'limited'])
                                    ->separator(','),
                            ]),
                        Flex::make([
                            TextInput::make('weight')
                                ->numeric()
                                ->suffix('kg')
                                ->visible(fn (Get $get): bool => $get('type') === 'deliverable'),
                        ])->from('md'),
                        Grid::make(1),
                    ])
                    ->columnSpan(['lg' => 1]),
            ])
            ->columns(3);
    }
}
