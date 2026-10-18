<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Forms\Components\Select;
use Illuminate\Validation\ValidationException;

/*
 * Selects fed by an API (`getSearchResultsUsing()` + a label lookup) validate submitted
 * values through the label lookup, so a value the source doesn't know is refused.
 */
$countries = ['bh' => 'Bahrain', 'fr' => 'France'];

it('accepts values the label lookup knows', function () use ($countries): void {
    $form = schema([
        Select::make('country')->searchable()
            ->getSearchResultsUsing(fn (string $search): array => $countries)
            ->getOptionLabelUsing(fn (string $value): ?string => $countries[$value] ?? null),
    ])->fill();

    $form->applyClientState(['country' => 'fr']);

    expect($form->validate())->toBe(['country' => 'fr']);
});

it('refuses values the source does not know', function () use ($countries): void {
    $form = schema([
        Select::make('country')->searchable()
            ->getSearchResultsUsing(fn (string $search): array => $countries)
            ->getOptionLabelUsing(fn (string $value): ?string => $countries[$value] ?? null),
    ])->fill();

    $form->applyClientState(['country' => 'xx']);

    expect(fn () => $form->validate())->toThrow(ValidationException::class);
});

it('validates every item of a multiple select with a bulk label lookup', function () use ($countries): void {
    $form = schema([
        Select::make('countries')->multiple()->searchable()
            ->getSearchResultsUsing(fn (string $search): array => $countries)
            ->getOptionLabelsUsing(fn (array $values): array => array_intersect_key($countries, array_flip($values))),
    ])->fill();

    $form->applyClientState(['countries' => ['bh', 'xx']]);

    expect(fn () => $form->validate())->toThrow(ValidationException::class);

    $form->applyClientState(['countries' => ['bh', 'fr']]);

    expect($form->validate())->toBe(['countries' => ['bh', 'fr']]);
});
