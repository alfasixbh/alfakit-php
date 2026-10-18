<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Tables\Columns\SelectColumn;
use AlfaSix\Alfakit\Tables\Table;

it('serialises the combobox messages of a select column', function (): void {
    $column = Table::make()->records([])->columns([
        SelectColumn::make('status')
            ->options(['draft' => 'Draft'])
            ->native(false)
            ->searchableOptions()
            ->loadingMessage('Fetching statuses…')
            ->noOptionsMessage(fn (): string => 'No statuses yet')
            ->noSearchResultsMessage('No status matches')
            ->searchPrompt('Search statuses')
            ->searchingMessage('Searching statuses…'),
    ])->toSchema()['columns'][0];

    expect($column)->toMatchArray([
        'loadingMessage' => 'Fetching statuses…',
        'noOptionsMessage' => 'No statuses yet',
        'noSearchResultsMessage' => 'No status matches',
        'searchPrompt' => 'Search statuses',
        'searchingMessage' => 'Searching statuses…',
    ]);
});

it('leaves the messages out by default and on a native select', function (): void {
    $columns = Table::make()->records([])->columns([
        SelectColumn::make('combobox')->options(['a' => 'A'])->native(false),
        SelectColumn::make('native')->options(['a' => 'A'])->searchPrompt('Search')->loadingMessage('Loading'),
    ])->toSchema()['columns'];

    foreach ($columns as $column) {
        expect($column)->not->toHaveKeys(['loadingMessage', 'noOptionsMessage', 'noSearchResultsMessage', 'searchPrompt', 'searchingMessage']);
    }
});
