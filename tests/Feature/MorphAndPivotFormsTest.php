<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Tests\Fixtures\FormsMorph\Note;
use AlfaSix\Alfakit\Tests\Fixtures\FormsMorph\NoteForm;
use AlfaSix\Alfakit\Tests\Fixtures\FormsMorph\Order;
use AlfaSix\Alfakit\Tests\Fixtures\FormsMorph\OrderForm;
use AlfaSix\Alfakit\Tests\Fixtures\FormsMorph\Product;
use AlfaSix\Alfakit\Tests\Fixtures\FormsMorph\Tables;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

beforeEach(fn () => Tables::create());

it('saves a BelongsToMany repeater from a standalone form submit', function (): void {
    Route::middleware('web')->get('/new-order', fn () => Inertia::render('NewOrder', ['form' => OrderForm::make()]));
    $pen = Product::query()->create(['name' => 'Pen']);
    $ink = Product::query()->create(['name' => 'Ink']);

    $token = inertiaProps(inertiaGet($this, '/new-order'))['form']['token'];

    inertiaPost($this, '/_alfakit/actions', ['token' => $token, 'action' => 'submit', 'data' => [
        'number' => 'C3',
        'products' => [['product_id' => (string) $ink->id, 'quantity' => '2'], ['product_id' => (string) $pen->id, 'quantity' => '1']],
    ]])->assertRedirect();

    $order = Order::query()->where('number', 'C3')->firstOrFail();

    expect($order->products()->withPivot('quantity')->orderByPivot('sort')->get()->map(fn (Product $product): array => [$product->name, $product->pivot->quantity])->all())
        ->toBe([['Ink', 2], ['Pen', 1]]);
});

it('saves and validates a MorphToSelect from a standalone form submit', function (): void {
    Route::middleware('web')->get('/new-note', fn () => Inertia::render('NewNote', ['form' => NoteForm::make()]));
    $pen = Product::query()->create(['name' => 'Pen']);

    $token = inertiaProps(inertiaGet($this, '/new-note'))['form']['token'];

    inertiaPost($this, '/_alfakit/actions', ['token' => $token, 'action' => 'submit', 'data' => ['notable_type' => Product::class, 'notable_id' => 999]])
        ->assertJsonValidationErrors(['notable_id']);

    inertiaPost($this, '/_alfakit/actions', ['token' => $token, 'action' => 'submit', 'data' => ['notable_type' => Product::class, 'notable_id' => (string) $pen->id]])
        ->assertRedirect();

    expect(Note::query()->sole()->notable?->is($pen))->toBeTrue();
});
