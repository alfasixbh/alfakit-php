<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Forms\Components\TextInput;
use AlfaSix\Alfakit\Tables\Columns\TextColumn;
use AlfaSix\Alfakit\Tables\Filters\Filter;
use AlfaSix\Alfakit\Tables\Filters\SelectFilter;
use AlfaSix\Alfakit\Tables\Filters\TernaryFilter;
use AlfaSix\Alfakit\Tables\Table;
use AlfaSix\Alfakit\Tables\TableState;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * @return array<int, array<string, mixed>>
 */
function customFilterRows(): array
{
    return [
        ['id' => 1, 'name' => 'Basic', 'tier' => 'free', 'featured' => true, 'price' => 0, 'archived_at' => null],
        ['id' => 2, 'name' => 'Pro', 'tier' => 'paid', 'featured' => false, 'price' => 20, 'archived_at' => null],
        ['id' => 3, 'name' => 'Team', 'tier' => 'paid', 'featured' => true, 'price' => 50, 'archived_at' => '2026-01-01'],
        ['id' => 4, 'name' => 'Legacy', 'tier' => 'legacy', 'featured' => 0, 'price' => 5, 'archived_at' => '2025-01-01'],
    ];
}

/**
 * @param  array<string, mixed>  $filters
 * @param  array<mixed>|Closure|null  $records
 * @return array<int, int>
 */
function customFilterKeys(array $filters, array $definitions, array|Closure|null $records = null): array
{
    $schema = Table::make()
        ->records($records ?? fn (): array => customFilterRows())
        ->columns([TextColumn::make('name')])
        ->filters($definitions)
        ->state(TableState::fromRequest(Request::create('/', 'GET', ['filters' => $filters])))
        ->toSchema();

    return array_map(intval(...), array_column($schema['records'], 'key'));
}

it('applies a select filter to custom rows by attribute', function (): void {
    $filter = SelectFilter::make('tier')->options(['free' => 'Free', 'paid' => 'Paid']);

    expect(customFilterKeys(['tier' => ['value' => 'paid']], [$filter]))->toBe([2, 3])
        ->and(customFilterKeys([], [$filter]))->toBe([1, 2, 3, 4])
        ->and(customFilterKeys(['plan' => ['values' => ['free', 'legacy']]], [SelectFilter::make('plan')->attribute('tier')->multiple()->options(['free' => 'Free', 'legacy' => 'Legacy'])]))->toBe([1, 4]);
});

it('applies a ternary filter to custom rows', function (): void {
    expect(customFilterKeys(['featured' => ['value' => '1']], [TernaryFilter::make('featured')]))->toBe([1, 3])
        ->and(customFilterKeys(['featured' => ['value' => '0']], [TernaryFilter::make('featured')]))->toBe([2, 4])
        ->and(customFilterKeys(['archived' => ['value' => '1']], [TernaryFilter::make('archived')->attribute('archived_at')->nullable()]))->toBe([3, 4])
        ->and(customFilterKeys(['archived' => ['value' => '0']], [
            TernaryFilter::make('archived')->queries(
                true: fn (Collection $records): Collection => $records->whereNotNull('archived_at'),
                false: fn (Collection $records): Collection => $records->whereNull('archived_at'),
            ),
        ]))->toBe([1, 2]);
});

it('passes custom rows and form data to a filter query closure', function (): void {
    $minimum = Filter::make('price')
        ->form([TextInput::make('min')])
        ->query(fn (Collection $records, array $data): Collection => $records->filter(fn (array $row): bool => $row['price'] >= (int) $data['min']));
    $featured = Filter::make('featured')->query(fn ($query) => $query->where('featured', true));

    expect(customFilterKeys(['price' => ['min' => '20']], [$minimum]))->toBe([2, 3])
        ->and(customFilterKeys(['featured' => ['isActive' => true]], [$featured]))->toBe([1, 3])
        ->and(customFilterKeys(['featured' => ['isActive' => false]], [$featured]))->toBe([1, 2, 3, 4]);
});

it('leaves rows alone for query closures written for Eloquent', function (): void {
    $filter = Filter::make('featured')->query(fn (Builder $query): Builder => $query->where('featured', true));

    expect(customFilterKeys(['featured' => ['isActive' => true]], [$filter]))->toBe([1, 2, 3, 4]);
});

it('lets a records closure that asks for $filters filter the rows itself', function (): void {
    $received = null;
    $records = function (array $filters) use (&$received): array {
        $received = $filters;

        return customFilterRows();
    };

    expect(customFilterKeys(['tier' => ['value' => 'paid']], [SelectFilter::make('tier')->options(['paid' => 'Paid'])], $records))->toBe([1, 2, 3, 4])
        ->and($received)->toBe(['tier' => ['value' => 'paid']]);
});

it('filters static rows and keeps string keys, before search and pagination', function (): void {
    $rows = collect(customFilterRows())->keyBy(fn (array $row): string => strtolower($row['name']))->all();

    $schema = Table::make()
        ->records($rows)
        ->columns([TextColumn::make('name')->searchable()])
        ->filters([SelectFilter::make('tier')->options(['paid' => 'Paid'])])
        ->paginated()
        ->state(TableState::fromRequest(Request::create('/', 'GET', ['filters' => ['tier' => ['value' => 'paid']], 'search' => 'te'])))
        ->toSchema();

    expect(array_column($schema['records'], 'key'))->toBe(['team'])
        ->and($schema['pagination']['total'])->toBe(1);
});
