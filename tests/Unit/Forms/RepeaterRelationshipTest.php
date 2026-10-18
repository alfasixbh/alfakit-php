<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Forms\Components\Repeater;
use AlfaSix\Alfakit\Forms\Components\Select;
use AlfaSix\Alfakit\Forms\Components\TextInput;
use AlfaSix\Alfakit\Tests\Fixtures\FormsMorph\Order;
use AlfaSix\Alfakit\Tests\Fixtures\FormsMorph\OrderProduct;
use AlfaSix\Alfakit\Tests\Fixtures\FormsMorph\Product;
use AlfaSix\Alfakit\Tests\Fixtures\FormsMorph\Tables;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

beforeEach(fn () => Tables::create());

function orderLines(?Closure $configure = null): Repeater
{
    $repeater = Repeater::make('products')->relationship()->orderColumn('sort')->schema([
        Select::make('product_id')->relationship('product', 'name')->required(),
        TextInput::make('quantity')->integer()->minValue(1)->required(),
    ]);

    return $configure === null ? $repeater : $configure($repeater);
}

/**
 * @return array<int, array{0: string, 1: int, 2: int}>
 */
function pivotRows(Order $order): array
{
    return OrderProduct::query()->where('order_id', $order->id)->orderBy('sort')->get()
        ->map(fn (OrderProduct $row): array => [Product::query()->find($row->product_id)?->name, $row->quantity, $row->sort])
        ->all();
}

it('fills a BelongsToMany repeater from pivot rows in order', function (): void {
    [$pen, $ink] = [Product::query()->create(['name' => 'Pen']), Product::query()->create(['name' => 'Ink'])];
    $order = Order::query()->create(['number' => 'A1']);
    $order->products()->attach([$pen->id => ['quantity' => 2, 'sort' => 1], $ink->id => ['quantity' => 5, 'sort' => 0]]);

    $repeater = orderLines();
    $schema = schema([$repeater], record: $order, operation: 'edit')->fill();
    $items = $schema->getRawState()['products'];

    expect(array_column($items, 'product_id'))->toBe([$ink->id, $pen->id])
        ->and(array_column($items, 'quantity'))->toBe([5, 2])
        // Items are pivot records: the select options come from the pivot's relationship.
        ->and($repeater->toSchema()['items'][0]['schema'][0]['options'])->toHaveCount(2)
        ->and($schema->getState())->toBe([]);
});

it('creates, updates, reorders and detaches pivot rows', function (): void {
    [$pen, $ink, $pad] = [Product::query()->create(['name' => 'Pen']), Product::query()->create(['name' => 'Ink']), Product::query()->create(['name' => 'Pad'])];
    $order = Order::query()->create(['number' => 'A1']);
    $order->products()->attach([$pen->id => ['quantity' => 1, 'sort' => 0], $ink->id => ['quantity' => 1, 'sort' => 1]]);

    $schema = schema([orderLines()], record: $order, operation: 'edit')->fill();
    $schema->applyClientState(['products' => [
        ['product_id' => $pad->id, 'quantity' => 3],
        ['product_id' => $ink->id, 'quantity' => 4],
    ]]);
    $schema->validate();
    $schema->saveRelationships();

    expect(pivotRows($order))->toBe([['Pad', 3, 0], ['Ink', 4, 1]]);
});

it('attaches pivot rows on create forms after the owner is saved', function (): void {
    [$pen, $ink] = [Product::query()->create(['name' => 'Pen']), Product::query()->create(['name' => 'Ink'])];

    $schema = schema([TextInput::make('number'), orderLines()], model: Order::class)->fill();
    $schema->applyClientState(['number' => 'B2', 'products' => [
        ['product_id' => $ink->id, 'quantity' => 2],
        ['product_id' => $pen->id, 'quantity' => 1],
    ]]);

    $order = Order::query()->create($schema->validate());
    $schema->record($order)->saveRelationships();

    expect(pivotRows($order))->toBe([['Ink', 2, 0], ['Pen', 1, 1]]);
});

it('requires a distinct related record per BelongsToMany item', function (): void {
    $pen = Product::query()->create(['name' => 'Pen']);
    $order = Order::query()->create(['number' => 'A1']);

    $schema = schema([orderLines()], record: $order, operation: 'edit')->fill();
    $schema->applyClientState(['products' => [
        ['product_id' => $pen->id, 'quantity' => 1],
        ['product_id' => $pen->id, 'quantity' => 2],
        ['product_id' => 999, 'quantity' => 2],
    ]]);

    try {
        $schema->validate();
        $this->fail('expected a validation error');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKeys(['products.0.product_id', 'products.1.product_id', 'products.2.product_id']);
    }
});

it('runs mutate and after hooks for pivot items, and skips items mutated to null', function (): void {
    [$pen, $ink, $pad] = [Product::query()->create(['name' => 'Pen']), Product::query()->create(['name' => 'Ink']), Product::query()->create(['name' => 'Pad'])];
    $order = Order::query()->create(['number' => 'A1']);
    $order->products()->attach([$pen->id => ['quantity' => 1, 'sort' => 0], $ink->id => ['quantity' => 1, 'sort' => 1]]);
    $log = new ArrayObject;

    $repeater = orderLines(fn (Repeater $repeater): Repeater => $repeater
        ->mutateRelationshipDataBeforeFillUsing(fn (array $data): array => [...$data, 'quantity' => $data['quantity'] * 10])
        ->mutateRelationshipDataBeforeSaveUsing(fn (array $data): array => [...$data, 'quantity' => intdiv((int) $data['quantity'], 10)])
        ->mutateRelationshipDataBeforeCreateUsing(fn (array $data): ?array => (int) $data['quantity'] > 50 ? null : $data)
        ->afterCreate(function (array $data, Model $record) use ($log): void {
            $log[] = "created {$record->getAttribute('name')}";
        })
        ->afterUpdate(function (array $data, Model $record) use ($log): void {
            $log[] = "updated {$record->getAttribute('product_id')} to {$data['quantity']}";
        })
        ->afterDelete(function (Model $record) use ($log): void {
            $log[] = "deleted {$record->getAttribute('product_id')}";
        }));

    $schema = schema([$repeater], record: $order, operation: 'edit')->fill();

    expect(array_column($schema->getRawState()['products'], 'quantity'))->toBe([10, 10]);

    $big = Product::query()->create(['name' => 'Big']);

    // Pen updated, Pad attached, Big skipped (mutated to null), Ink detached.
    $schema->applyClientState(['products' => [
        ['product_id' => $pen->id, 'quantity' => 70],
        ['product_id' => $pad->id, 'quantity' => 4],
        ['product_id' => $big->id, 'quantity' => 99],
    ]]);
    $schema->validate();
    $schema->saveRelationships();

    expect(pivotRows($order))->toBe([['Pen', 7, 0], ['Pad', 4, 1]])
        ->and($log->getArrayCopy())->toBe(["updated {$pen->id} to 7", 'created Pad', "deleted {$ink->id}"]);
});

it('only manages the records modifyRecordsUsing keeps', function (): void {
    $pen = Product::query()->create(['name' => 'Pen']);
    $ink = Product::query()->create(['name' => 'Ink']);
    $order = Order::query()->create(['number' => 'A1']);
    OrderProduct::query()->create(['order_id' => $order->id, 'product_id' => $pen->id, 'quantity' => 1, 'sort' => 0]);
    OrderProduct::query()->create(['order_id' => $order->id, 'product_id' => $ink->id, 'quantity' => 9, 'sort' => 1]);

    $repeater = Repeater::make('smallLines')
        ->relationship('orderProducts', modifyRecordsUsing: fn (Collection $records): Collection => $records->where('quantity', '<', 5))
        ->orderColumn('sort')
        ->schema([Select::make('product_id')->relationship('product', 'name'), TextInput::make('quantity')->integer()]);

    $schema = schema([$repeater], record: $order, operation: 'edit')->fill();

    expect($schema->getRawState()['smallLines'])->toHaveCount(1);

    // Deleting the visible item leaves the filtered-out one alone.
    $schema->applyClientState(['smallLines' => []]);
    $schema->saveRelationships();

    expect(OrderProduct::query()->pluck('quantity')->all())->toBe([9]);
});

it('saves a HasMany to a pivot model with hooks, keeping orderColumn order', function (): void {
    [$pen, $ink] = [Product::query()->create(['name' => 'Pen']), Product::query()->create(['name' => 'Ink'])];
    $order = Order::query()->create(['number' => 'A1']);
    $first = OrderProduct::query()->create(['order_id' => $order->id, 'product_id' => $pen->id, 'quantity' => 1, 'sort' => 0]);
    $events = [];

    $repeater = Repeater::make('orderProducts')->relationship()->orderColumn('sort')
        ->schema([Select::make('product_id')->relationship('product', 'name')->required(), TextInput::make('quantity')->integer()])
        ->mutateRelationshipDataBeforeSaveUsing(fn (array $data): ?array => null)
        ->afterCreate(function (array $data, OrderProduct $record) use (&$events): void {
            $events[] = 'created '.$record->product_id;
        })
        ->afterDelete(function (OrderProduct $record) use (&$events): void {
            $events[] = 'deleted '.$record->id;
        });

    $schema = schema([$repeater], record: $order, operation: 'edit')->fill();
    $schema->applyClientState(['orderProducts' => [
        ['product_id' => $ink->id, 'quantity' => 3],
        ['id' => $first->id, 'product_id' => $pen->id, 'quantity' => 8],
    ]]);
    $schema->validate();
    $schema->saveRelationships();

    // The existing row was skipped (mutate returned null), the new one created first.
    expect(OrderProduct::query()->orderBy('id')->get(['product_id', 'quantity', 'sort'])->toArray())->toBe([
        ['product_id' => $pen->id, 'quantity' => 1, 'sort' => 0],
        ['product_id' => $ink->id, 'quantity' => 3, 'sort' => 0],
    ])->and($events)->toBe(["created {$ink->id}"]);

    $schema = schema([$repeater->flushChildSchema()], record: $order->fresh(), operation: 'edit')->fill();
    $schema->applyClientState(['orderProducts' => []]);
    $schema->saveRelationships();

    expect(OrderProduct::query()->count())->toBe(0)
        ->and($events)->toHaveCount(3);
});
