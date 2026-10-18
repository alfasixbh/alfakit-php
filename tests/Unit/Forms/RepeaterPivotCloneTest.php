<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Forms\Components\Repeater;
use AlfaSix\Alfakit\Forms\Components\Select;
use AlfaSix\Alfakit\Forms\Components\TextInput;
use AlfaSix\Alfakit\Tests\Fixtures\FormsMorph\Order;
use AlfaSix\Alfakit\Tests\Fixtures\FormsMorph\OrderProduct;
use AlfaSix\Alfakit\Tests\Fixtures\FormsMorph\Product;
use AlfaSix\Alfakit\Tests\Fixtures\FormsMorph\Tables;

beforeEach(fn () => Tables::create());

it('clones a BelongsToMany item without its related record, so another one can be picked', function (): void {
    [$pen, $ink] = [Product::query()->create(['name' => 'Pen']), Product::query()->create(['name' => 'Ink'])];
    $order = Order::query()->create(['number' => 'A1']);
    $order->products()->attach([$pen->id => ['quantity' => 2, 'sort' => 0]]);

    $repeater = Repeater::make('products')->relationship()->orderColumn('sort')->cloneable()->schema([
        Select::make('product_id')->relationship('product', 'name')->required(),
        TextInput::make('quantity')->integer()->required(),
    ]);
    $schema = schema([$repeater], record: $order, operation: 'edit')->fill();

    $repeater->cloneItem(array_key_first($schema->getRawState()['products']));
    $items = array_values($schema->getRawState()['products']);

    expect($items)->toHaveCount(2)
        ->and($items[1])->toMatchArray(['product_id' => null, 'quantity' => 2]);

    // Picking another product attaches it with the copied pivot data.
    $schema->applyClientState(['products' => [$items[0], [...$items[1], 'product_id' => $ink->id]]]);
    $schema->validate();
    $schema->saveRelationships();

    expect(OrderProduct::query()->orderBy('sort')->get(['product_id', 'quantity'])->toArray())->toBe([
        ['product_id' => $pen->id, 'quantity' => 2],
        ['product_id' => $ink->id, 'quantity' => 2],
    ]);
});
