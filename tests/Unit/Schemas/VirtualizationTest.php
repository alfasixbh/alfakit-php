<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Forms\Components\Builder;
use AlfaSix\Alfakit\Forms\Components\Builder\Block;
use AlfaSix\Alfakit\Forms\Components\Repeater;
use AlfaSix\Alfakit\Forms\Components\TextInput;
use AlfaSix\Alfakit\Tables\Columns\TextColumn;
use AlfaSix\Alfakit\Tables\Table;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Post;

it('virtualises repeaters, builders and tables above 50 items by default', function (): void {
    $form = schema([
        Repeater::make('items')->schema([TextInput::make('sku')]),
        Builder::make('content')->blocks([Block::make('text')->schema([TextInput::make('body')])]),
    ]);
    $form->fill(['items' => [], 'content' => []]);

    [$repeater, $builder] = $form->toSchema();

    expect($repeater['virtualize'])->toBe(['threshold' => 50])
        ->and($builder['virtualize'])->toBe(['threshold' => 50])
        ->and(Table::make()->query(Post::query())->columns([TextColumn::make('title')])->toSchema()['virtualize'])
        ->toBe(['threshold' => 50]);
});

it('lets the threshold, the estimate and the switch be configured or turned off', function (): void {
    $form = schema([
        Repeater::make('a')->schema([TextInput::make('sku')])->virtualizeThreshold(fn (): int => 200)->virtualizeEstimateSize(96),
        Repeater::make('b')->schema([TextInput::make('sku')])->virtualize(false),
        Repeater::make('c')->schema([TextInput::make('sku')])->virtualize(fn (): bool => false),
    ]);
    $form->fill(['a' => [], 'b' => [], 'c' => []]);

    [$a, $b, $c] = $form->toSchema();

    expect($a['virtualize'])->toBe(['threshold' => 200, 'estimateSize' => 96])
        ->and($b)->not->toHaveKey('virtualize')
        ->and($c)->not->toHaveKey('virtualize')
        ->and(Table::make()->query(Post::query())->columns([TextColumn::make('title')])->virtualize(false)->toSchema())
        ->not->toHaveKey('virtualize')
        ->and(Table::make()->query(Post::query())->columns([TextColumn::make('title')])->virtualizeThreshold(25)->toSchema()['virtualize'])
        ->toBe(['threshold' => 25]);
});
