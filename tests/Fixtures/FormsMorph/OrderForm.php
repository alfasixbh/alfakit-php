<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\FormsMorph;

use AlfaSix\Alfakit\Forms\Components\Repeater;
use AlfaSix\Alfakit\Forms\Components\Select;
use AlfaSix\Alfakit\Forms\Components\TextInput;
use AlfaSix\Alfakit\Schemas\Schema;
use AlfaSix\Alfakit\Standalone\Form;

/** A standalone create form with a BelongsToMany repeater; `submit()` returns the order. */
class OrderForm extends Form
{
    public function form(Schema $schema): Schema
    {
        return $schema->model(Order::class)->components([
            TextInput::make('number')->required(),
            Repeater::make('products')->relationship()->orderColumn('sort')->defaultItems(0)->schema([
                Select::make('product_id')->relationship('product', 'name')->required(),
                TextInput::make('quantity')->integer()->required(),
            ]),
        ]);
    }

    public function submit(array $data): mixed
    {
        return Order::query()->create($data);
    }
}
