<?php

declare(strict_types=1);

namespace App\Filament\Clusters\Settings\Resources\Brands;

use App\Filament\Clusters\Settings\Resources\Brands\Pages\ManageBrands;
use App\Filament\Clusters\Settings\SettingsCluster;
use App\Models\Brand;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\MarkdownEditor;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class BrandResource extends Resource
{
    protected static ?string $model = Brand::class;

    protected static ?string $cluster = SettingsCluster::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookmarkSquare;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')->required()->maxLength(255),
                TextInput::make('website')->url()->prefixIcon(Heroicon::OutlinedGlobeAlt),
                Toggle::make('is_visible')->label('Visible to customers')->default(true),
                MarkdownEditor::make('description')->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('website')->url(fn (Brand $record): ?string => $record->website, shouldOpenInNewTab: true),
                IconColumn::make('is_visible')->boolean()->label('Visible'),
                TextColumn::make('updated_at')->since()->label('Updated'),
            ])
            ->reorderable('sort')
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageBrands::route('/'),
        ];
    }
}
