<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\Panel\Pages;

use AlfaSix\Alfakit\Actions\Action;
use AlfaSix\Alfakit\Actions\BulkAction;
use AlfaSix\Alfakit\Pages\Page;
use AlfaSix\Alfakit\Tables\Columns\TextColumn;
use AlfaSix\Alfakit\Tables\Columns\TextInputColumn;
use AlfaSix\Alfakit\Tables\Columns\ToggleColumn;
use AlfaSix\Alfakit\Tables\Table;

/**
 * A page whose table lists non-Eloquent rows (`records()`), with record and bulk actions
 * and inline-editable columns that save through `updateStateUsing()`.
 */
class PlansPage extends Page
{
    protected static ?string $slug = 'plans';

    protected static bool $shouldRegisterNavigation = false;

    /** @var array<string, array<string, mixed>> */
    public static array $plans = [];

    /** @var array<int, array<string, mixed>> */
    public static array $runs = [];

    public static function reset(): void
    {
        static::$plans = [
            'basic' => ['name' => 'Basic', 'price' => 10, 'active' => true],
            'pro' => ['name' => 'Pro', 'price' => 30, 'active' => false],
            'team' => ['name' => 'Team', 'price' => 20, 'active' => true],
        ];
        static::$runs = [];
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (): array => static::$plans)
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextInputColumn::make('price')
                    ->rules(['integer', 'min:0'])
                    ->updateStateUsing(function (array $record, mixed $state): void {
                        static::$plans[array_search($record, static::$plans, true)]['price'] = (int) $state;
                    }),
                ToggleColumn::make('active'),
            ])
            ->recordActions([
                Action::make('archive')
                    ->hidden(fn (array $record): bool => ! $record['active'])
                    ->action(function (array $record): void {
                        static::$runs[] = ['archive' => $record['name']];
                    }),
            ])
            ->bulkActions([
                BulkAction::make('export')->action(function (\Illuminate\Support\Collection $records): void {
                    static::$runs[] = ['export' => $records->pluck('name')->all()];
                }),
            ]);
    }
}
