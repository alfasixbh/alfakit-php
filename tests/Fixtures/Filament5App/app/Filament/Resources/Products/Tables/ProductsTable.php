<?php

namespace App\Filament\Resources\Products\Tables;

use App\Filament\Exports\ProductExporter;
use App\Filament\Imports\ProductImporter;
use App\Models\Product;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ExportAction;
use Filament\Actions\ExportBulkAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\ImportAction;
use Filament\Actions\ReplicateAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ColorColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\Summarizers\Average;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\QueryBuilder;
use Filament\Tables\Filters\QueryBuilder\Constraints\BooleanConstraint;
use Filament\Tables\Filters\QueryBuilder\Constraints\DateConstraint;
use Filament\Tables\Filters\QueryBuilder\Constraints\NumberConstraint;
use Filament\Tables\Filters\QueryBuilder\Constraints\TextConstraint;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class ProductsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('image')
                    ->label('Image')
                    ->circular(),
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->weight(FontWeight::Medium)
                    ->description(fn (Product $record): ?string => $record->sku),
                TextColumn::make('brand.name')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),
                ToggleColumn::make('is_visible')
                    ->label('Visibility')
                    ->sortable(),
                TextColumn::make('price')
                    ->money('usd')
                    ->sortable()
                    ->summarize(Average::make()->money('usd')),
                TextColumn::make('qty')
                    ->label('Quantity')
                    ->numeric()
                    ->sortable()
                    ->summarize(Sum::make())
                    ->color(fn (int $state): string => $state < 10 ? 'danger' : 'gray'),
                ColorColumn::make('color')
                    ->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('featured')
                    ->boolean()
                    ->trueIcon(Heroicon::OutlinedStar)
                    ->falseIcon('heroicon-o-minus'),
                TextColumn::make('tags')
                    ->badge()
                    ->separator(','),
                TextColumn::make('published_at')
                    ->label('Publish date')
                    ->date()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('name')
            ->groups([
                Group::make('brand.name')->label('Brand')->collapsible(),
            ])
            ->filters([
                TrashedFilter::make(),
                SelectFilter::make('brand')
                    ->relationship('brand', 'name')
                    ->searchable()
                    ->preload()
                    ->multiple(),
                TernaryFilter::make('is_visible')
                    ->label('Visibility')
                    ->boolean()
                    ->trueLabel('Only visible')
                    ->falseLabel('Only hidden')
                    ->native(false),
                Filter::make('low_stock')
                    ->query(fn (Builder $query): Builder => $query->where('qty', '<', 10))
                    ->toggle(),
                Filter::make('published_at')
                    ->schema([
                        DatePicker::make('published_from'),
                        DatePicker::make('published_until'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['published_from'] ?? null, fn (Builder $query, $date): Builder => $query->whereDate('published_at', '>=', $date))
                            ->when($data['published_until'] ?? null, fn (Builder $query, $date): Builder => $query->whereDate('published_at', '<=', $date));
                    }),
                QueryBuilder::make()
                    ->constraints([
                        TextConstraint::make('name'),
                        TextConstraint::make('sku')->label('SKU'),
                        NumberConstraint::make('price')->icon(Heroicon::OutlinedCurrencyDollar),
                        NumberConstraint::make('qty')->label('Quantity'),
                        BooleanConstraint::make('is_visible')->label('Visibility'),
                        DateConstraint::make('published_at'),
                    ]),
            ], layout: FiltersLayout::AboveContentCollapsible)
            ->deferFilters()
            ->persistFiltersInSession()
            ->headerActions([
                ImportAction::make()->importer(ProductImporter::class),
                ExportAction::make()->exporter(ProductExporter::class),
            ])
            ->recordActions([
                ActionGroup::make([
                    ViewAction::make(),
                    EditAction::make(),
                    ReplicateAction::make()
                        ->excludeAttributes(['slug', 'sku'])
                        ->mutateRecordDataUsing(function (array $data): array {
                            $data['name'] .= ' (copy)';

                            return $data;
                        }),
                    Action::make('restock')
                        ->icon(Heroicon::OutlinedArrowPath)
                        ->color('success')
                        ->schema([
                            TextInput::make('amount')->numeric()->required()->minValue(1),
                        ])
                        ->action(function (Product $record, array $data): void {
                            $record->increment('qty', (int) $data['amount']);

                            Notification::make()
                                ->title('Restocked')
                                ->success()
                                ->send();
                        }),
                    DeleteAction::make(),
                ]),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('hide')
                        ->icon('heroicon-o-eye-slash')
                        ->requiresConfirmation()
                        ->action(fn (Collection $records) => $records->each->update(['is_visible' => false]))
                        ->deselectRecordsAfterCompletion(),
                    ExportBulkAction::make()->exporter(ProductExporter::class),
                    DeleteBulkAction::make(),
                    ForceDeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ])
            ->striped()
            ->paginated([10, 25, 50, 100]);
    }
}
