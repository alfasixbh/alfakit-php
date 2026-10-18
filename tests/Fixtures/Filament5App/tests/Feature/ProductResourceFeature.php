<?php

use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Filament\Resources\Products\Pages\ListProducts;
use App\Models\Product;
use App\Models\User;
use Filament\Actions\DeleteAction;

use function Pest\Livewire\livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create(['email' => 'admin@acme.test']));
});

it('lists products', function () {
    $products = Product::factory()->count(3)->create();

    livewire(ListProducts::class)
        ->assertCanSeeTableRecords($products)
        ->searchTable($products->first()->name)
        ->assertCanSeeTableRecords($products->take(1));
});

it('creates a product', function () {
    livewire(CreateProduct::class)
        ->fillForm(['name' => 'Mug', 'sku' => 'mug-1', 'price' => 10, 'qty' => 5, 'security_stock' => 1])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Product::query()->where('sku', 'MUG-1')->exists())->toBeTrue();
});

it('deletes a product from the table', function () {
    $product = Product::factory()->create();

    livewire(ListProducts::class)
        ->callTableAction(DeleteAction::class, $product);

    $this->assertSoftDeleted($product);
});
