<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Forms\Components\Repeater;
use AlfaSix\Alfakit\Forms\Components\Repeater\TableColumn;
use AlfaSix\Alfakit\Forms\Components\Select;
use AlfaSix\Alfakit\Forms\Components\TextInput;
use AlfaSix\Alfakit\Infolists\Components\RepeatableEntry;
use AlfaSix\Alfakit\Infolists\Components\RepeatableEntry\TableColumn as EntryTableColumn;
use AlfaSix\Alfakit\Infolists\Components\TextEntry;
use AlfaSix\Alfakit\Support\Enums\Alignment;

it('serialises repeater table columns mapped to the item fields', function (): void {
    $repeater = Repeater::make('lines')
        ->table([
            TableColumn::make('Product')->width('40%'),
            TableColumn::make('Quantity')->alignment(Alignment::End)->hiddenHeaderLabel(),
            TableColumn::make('Unit')->markAsRequired()->wrapHeader(),
        ])
        ->schema([
            TextInput::make('product')->required(),
            TextInput::make('quantity')->numeric(),
            Select::make('unit')->options(['kg' => 'kg']),
        ]);

    $props = schema([$repeater])->fill(['lines' => [['product' => 'Tea', 'quantity' => 2, 'unit' => 'kg']]])->toSchema()[0];

    expect($props['table'])->toBe([
        ['label' => 'Product', 'name' => 'product', 'required' => true, 'width' => '40%'],
        ['label' => 'Quantity', 'name' => 'quantity', 'hiddenLabel' => true, 'alignment' => 'end'],
        ['label' => 'Unit', 'name' => 'unit', 'required' => true, 'wrapHeader' => true],
    ])
        // Items keep their usual per-index schemas.
        ->and($props['items'][0]['schema'][1]['statePath'])->toBe('lines.0.quantity');
});

it('ignores the table layout for simple repeaters and without columns', function (): void {
    expect(schema([Repeater::make('tags')->simple(TextInput::make('tag'))->table([TableColumn::make('Tag')])])->fill()->toSchema()[0])->not->toHaveKey('table')
        ->and(schema([Repeater::make('lines')->schema([TextInput::make('a')])])->fill()->toSchema()[0])->not->toHaveKey('table');
});

it('maps right and left alignment to logical sides', function (): void {
    expect(TableColumn::make('A')->alignment('right')->getAlignment())->toBe('end')
        ->and(TableColumn::make('A')->alignment('left')->getAlignment())->toBe('start')
        ->and(TableColumn::make('A')->alignment(Alignment::Center)->getAlignment())->toBe('center');
});

it('serialises repeatable entry table columns', function (): void {
    $entry = RepeatableEntry::make('lines')
        ->table([EntryTableColumn::make('Product'), EntryTableColumn::make('Quantity')->alignment(Alignment::End)])
        ->schema([TextEntry::make('product'), TextEntry::make('quantity')])
        ->state([['product' => 'Tea', 'quantity' => 2]]);

    $props = schema([$entry])->toSchema()[0];

    expect($props['table'])->toBe([
        ['label' => 'Product', 'name' => 'product'],
        ['label' => 'Quantity', 'name' => 'quantity', 'alignment' => 'end'],
    ])->and($props['items'][0][0]['state'])->toBe('Tea');
});
