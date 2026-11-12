<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Forms\Components\ColorPicker;
use AlfaSix\Alfakit\Forms\Components\KeyValue;
use AlfaSix\Alfakit\Forms\Components\TagsInput;
use Illuminate\Validation\ValidationException;

function keyValueSchema(KeyValue $field, array $client): AlfaSix\Alfakit\Schemas\Schema
{
    $schema = schema([$field])->fill(['meta' => ['colour' => 'red', 'size' => 'L']]);
    $schema->toSchema();
    $schema->applyClientState(['meta' => $client]);

    return $schema;
}

it('holds a key-value field to its locks on the server', function (): void {
    $rows = [['key' => 'colour', 'value' => 'red'], ['key' => 'size', 'value' => 'L']];

    // Unchanged rows always pass.
    keyValueSchema(KeyValue::make('meta')->addable(false)->deletable(false)->editableKeys(false)->editableValues(false), $rows)->validate();

    expect(fn () => keyValueSchema(KeyValue::make('meta')->addable(false), [...$rows, ['key' => 'x', 'value' => 'y']])->validate())
        ->toThrow(ValidationException::class)
        ->and(fn () => keyValueSchema(KeyValue::make('meta')->deletable(false), [$rows[0]])->validate())
        ->toThrow(ValidationException::class)
        ->and(fn () => keyValueSchema(KeyValue::make('meta')->editableKeys(false)->addable(false), [['key' => 'color', 'value' => 'red'], $rows[1]])->validate())
        ->toThrow(ValidationException::class)
        ->and(fn () => keyValueSchema(KeyValue::make('meta')->editableValues(false), [['key' => 'colour', 'value' => 'blue'], $rows[1]])->validate())
        ->toThrow(ValidationException::class);

    // What the locks leave open still works.
    keyValueSchema(KeyValue::make('meta')->addable(false), [['key' => 'colour', 'value' => 'blue'], $rows[1]])->validate();
    keyValueSchema(KeyValue::make('meta')->editableValues(false), [...$rows, ['key' => 'x', 'value' => 'y']])->validate();
});

it('drops key-value rows whose key is not text and tags that are not text', function (): void {
    $schema = schema([KeyValue::make('meta'), TagsInput::make('tags')]);
    $schema->applyClientState([
        'meta' => [['key' => 'a', 'value' => ['nested' => 1]], ['key' => ['x'], 'value' => 'b'], ['key' => 'c', 'value' => 'd']],
        'tags' => ['one', ['two'], 'three'],
    ]);

    // A nested value that was stored stays as it is: only the key has to be text.
    expect($schema->getState())->toBe(['meta' => ['a' => ['nested' => 1], 'c' => 'd'], 'tags' => ['one', 'three']]);
});

it('only accepts colour values a picker can produce', function (string $format, string $value, bool $valid): void {
    $schema = schema([ColorPicker::make('colour')->format($format)]);
    $schema->applyClientState(['colour' => $value]);

    $valid
        ? expect($schema->validate())->toBeArray()
        : expect(fn () => $schema->validate())->toThrow(ValidationException::class);
})->with([
    ['rgb', 'rgb(255, 0, 12)', true],
    ['rgb', 'rgb(256, 0, 12)', false],
    ['rgb', 'rgb(999, 0, 12)', false],
    ['rgba', 'rgba(1, 2, 3, 0.5)', true],
    ['rgba', 'rgba(1, 2, 300, 0.5)', false],
    ['hsl', 'hsl(360, 100%, 50%)', true],
    ['hsl', 'hsl(361, 100%, 50%)', false],
    ['hsl', 'hsl(10, 101%, 50%)', false],
]);

it('keeps what item fields show when a relationship repeater is loaded again after the fill', function (): void {
    $post = AlfaSix\Alfakit\Tests\Fixtures\Models\Post::query()->create(['title' => 'Priced', 'slug' => 'priced', 'content' => 'x']);
    $post->comments()->create(['author_name' => 'Ann', 'body' => 'Hi', 'sort' => 1250]);

    $schema = schema([
        AlfaSix\Alfakit\Forms\Components\Repeater::make('comments')->relationship()->schema([
            AlfaSix\Alfakit\Forms\Components\TextInput::make('author_name'),
            AlfaSix\Alfakit\Forms\Components\MoneyInput::make('sort')->currency('USD')->minorUnits(),
        ]),
    ], record: $post, operation: 'edit');

    // What an edit page does: fill from the record, then load the relationship fields.
    $schema->fill();
    $schema->loadStateFromRelationships();

    expect($schema->getRawState()['comments'][0]['sort'])->toBe('12.50');

    // And saving what was shown stores the same amount, not a hundred times it.
    $schema->saveRelationships();

    expect($post->comments()->sole()->sort)->toBe(1250);
});
