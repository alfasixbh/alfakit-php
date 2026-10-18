<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Forms\Components\DatePicker;
use AlfaSix\Alfakit\Forms\Components\DateTimePicker;
use AlfaSix\Alfakit\Forms\Components\KeyValue;
use AlfaSix\Alfakit\Forms\Components\Select;
use AlfaSix\Alfakit\Forms\Components\TagsInput;
use AlfaSix\Alfakit\Forms\Components\TextInput;
use AlfaSix\Alfakit\Forms\Components\Toggle;
use AlfaSix\Alfakit\Schemas\Components\Section;

it('serialises a text input with its props', function (): void {
    $schema = TextInput::make('email')
        ->email()
        ->required()
        ->placeholder('you@example.com')
        ->helperText('We never share it.')
        ->prefixIcon('mail')
        ->live(onBlur: true)
        ->toSchema();

    expect($schema)->toMatchArray([
        'type' => 'TextInput',
        'key' => 'email',
        'name' => 'email',
        'label' => 'Email',
        'inputType' => 'email',
        'required' => true,
        'placeholder' => 'you@example.com',
        'helperText' => 'We never share it.',
        'prefixIcon' => 'mail',
        'live' => ['onBlur' => true, 'debounce' => null],
    ])->not->toHaveKey('disabled');
});

it('debounces typed live fields by 150 ms and refreshes choices at once', function (): void {
    expect(TextInput::make('title')->live()->toSchema()['live'])->toBe(['onBlur' => false, 'debounce' => 150])
        ->and(Select::make('status')->live()->toSchema()['live'])->toBe(['onBlur' => false, 'debounce' => 0])
        ->and(Toggle::make('active')->live()->toSchema()['live'])->toBe(['onBlur' => false, 'debounce' => 0])
        ->and(DatePicker::make('starts_on')->live()->toSchema()['live'])->toBe(['onBlur' => false, 'debounce' => 0])
        ->and(DateTimePicker::make('starts_at')->live()->toSchema()['live'])->toBe(['onBlur' => false, 'debounce' => 0])
        // An explicit debounce wins; `onBlur` fields have none unless one is given.
        ->and(Select::make('status')->live(debounce: '1s')->toSchema()['live'])->toBe(['onBlur' => false, 'debounce' => 1000])
        ->and(TextInput::make('title')->live(debounce: 500)->toSchema()['live'])->toBe(['onBlur' => false, 'debounce' => 500])
        ->and(TextInput::make('title')->live(onBlur: true)->toSchema()['live'])->toBe(['onBlur' => true, 'debounce' => null]);
});

it('derives labels from names and strips _id', function (): void {
    expect(TextInput::make('author_id')->getLabel())->toBe('Author')
        ->and(TextInput::make('firstName')->getLabel())->toBe('First name')
        ->and(TextInput::make('meta.og_title')->getLabel())->toBe('Og title');
});

it('serialises grouped and enum options', function (): void {
    enum PostStatus: string
    {
        case Draft = 'draft';
        case Published = 'published';
    }

    $grouped = Select::make('s')->options(['Group A' => ['a' => 'A'], 'b' => 'B'])->descriptions(['a' => 'desc'])->toSchema();

    expect($grouped['options'])->toBe([
        ['label' => 'Group A', 'options' => [['value' => 'a', 'label' => 'A', 'description' => 'desc']]],
        ['value' => 'b', 'label' => 'B'],
    ]);

    expect(Select::make('status')->options(PostStatus::class)->toSchema()['options'])->toBe([
        ['value' => 'draft', 'label' => 'Draft'],
        ['value' => 'published', 'label' => 'Published'],
    ]);
});

it('normalises dates on hydrate and dehydrate', function (): void {
    $date = DatePicker::make('published_at');
    $dateTime = DateTimePicker::make('starts_at')->seconds(false);

    $schema = schema([$date, $dateTime])->fill(['published_at' => '2026-10-08T15:30:00+03:00', 'starts_at' => '2026-10-08 15:30:45']);

    expect($date->getState())->toBe('2026-10-08')
        ->and($dateTime->getState())->toBe('2026-10-08 15:30')
        ->and($schema->getState())->toBe(['published_at' => '2026-10-08', 'starts_at' => '2026-10-08 15:30']);
});

it('casts toggles, numeric inputs and tags on dehydrate', function (): void {
    $schema = schema([
        Toggle::make('on'),
        TextInput::make('qty')->integer(),
        TextInput::make('price')->numeric(),
        TagsInput::make('tags'),
        TagsInput::make('csv')->separator(','),
        KeyValue::make('meta'),
    ])->fill(['on' => '1', 'qty' => '3', 'price' => '9.5', 'tags' => ['a', '', 'b'], 'csv' => 'x,y', 'meta' => [['key' => 'k', 'value' => 'v'], ['key' => '', 'value' => 'ignored']]]);

    expect($schema->getState())->toBe([
        'on' => true,
        'qty' => 3,
        'price' => 9.5,
        'tags' => ['a', 'b'],
        'csv' => 'x,y',
        'meta' => ['k' => 'v'],
    ]);
});

it('serialises nested layout with columns', function (): void {
    $schema = Section::make('Details')->description('Basics')->columns(3)->schema([
        TextInput::make('a')->columnSpan(2),
        TextInput::make('b')->columnSpanFull(),
    ])->toSchema();

    expect($schema['type'])->toBe('Section')
        ->and($schema['heading'])->toBe('Details')
        ->and($schema['columns'])->toBe(['default' => 1, 'lg' => 3])
        ->and($schema['schema'][0]['columnSpan'])->toBe(['lg' => 2])
        ->and($schema['schema'][1]['columnSpan'])->toBe(['default' => 'full']);
});
