<?php

namespace App\Filament\Resources\Orders\Tables;

use App\Enums\OrderStatus;
use App\Models\Order;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Forms\Components\DatePicker;
use Filament\Notifications\Notification;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\Indicator;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class OrdersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('number')
                    ->searchable()
                    ->sortable()
                    ->copyable(),
                TextColumn::make('customer.name')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('status')
                    ->badge(),
                TextColumn::make('currency')
                    ->getStateUsing(fn (Order $record): string => strtoupper((string) $record->currency))
                    ->searchable()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('total_price')
                    ->searchable()
                    ->sortable()
                    ->money('usd')
                    ->summarize([
                        Sum::make()->money('usd'),
                    ]),
                TextColumn::make('shipping_price')
                    ->label('Shipping cost')
                    ->money('usd')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('created_at')
                    ->label('Order date')
                    ->date()
                    ->toggleable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                TrashedFilter::make(),
                SelectFilter::make('status')
                    ->options(OrderStatus::class)
                    ->multiple(),
                Filter::make('created_at')
                    ->schema([
                        DatePicker::make('created_from')
                            ->placeholder(fn ($state): string => 'Dec 18, '.now()->subYear()->format('Y')),
                        DatePicker::make('created_until')
                            ->placeholder(fn ($state): string => now()->format('M d, Y')),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['created_from'] ?? null, fn (Builder $query, $date): Builder => $query->whereDate('created_at', '>=', $date))
                            ->when($data['created_until'] ?? null, fn (Builder $query, $date): Builder => $query->whereDate('created_at', '<=', $date));
                    })
                    ->indicateUsing(function (array $data): array {
                        $indicators = [];

                        if ($data['created_from'] ?? null) {
                            $indicators[] = Indicator::make('Order from '.$data['created_from'])->removeField('created_from');
                        }

                        if ($data['created_until'] ?? null) {
                            $indicators[] = Indicator::make('Order until '.$data['created_until'])->removeField('created_until');
                        }

                        return $indicators;
                    }),
            ])
            ->recordActions([
                EditAction::make(),
                Action::make('markShipped')
                    ->label('Mark shipped')
                    ->icon('heroicon-o-truck')
                    ->visible(fn (Order $record): bool => $record->status === OrderStatus::Processing)
                    ->requiresConfirmation()
                    ->modalWidth(Width::Medium)
                    ->action(function (Order $record): void {
                        $record->update(['status' => OrderStatus::Shipped, 'shipped_at' => now()]);

                        Notification::make()->title('Order shipped')->success()->sendToDatabase(auth()->user());
                    }),
            ], position: RecordActionsPosition::BeforeColumns)
            ->groupedBulkActions([
                DeleteBulkAction::make()
                    ->action(function (): void {
                        Notification::make()
                            ->title('Now, now, don\'t be cheeky, leave some records for others to play with!')
                            ->warning()
                            ->send();
                    }),
                ForceDeleteBulkAction::make(),
                RestoreBulkAction::make(),
            ])
            ->groups([
                Group::make('created_at')
                    ->label('Order date')
                    ->date()
                    ->collapsible(),
            ]);
    }
}
