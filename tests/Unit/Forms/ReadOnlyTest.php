<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Forms\Components\Repeater;
use AlfaSix\Alfakit\Forms\Components\TextInput;

it('makes fields inside a read-only repeater read-only', function (): void {
    $form = schema([
        Repeater::make('items')->readOnly()->schema([TextInput::make('sku')]),
        Repeater::make('other')->schema([TextInput::make('sku')]),
    ])->fill(['items' => [['sku' => 'a']], 'other' => [['sku' => 'b']]]);

    $fields = $form->getFlatFields();
    $child = fn (string $repeater) => $fields[$repeater]->getNestedFieldByStatePath("{$repeater}.0.sku");

    expect($child('items')?->isReadOnly())->toBeTrue()
        ->and($child('other')?->isReadOnly())->toBeFalse();
});

it('keeps the server value of read-only fields, but still saves it', function (): void {
    $form = schema([
        TextInput::make('sku')->readOnly(),
        TextInput::make('name'),
        Repeater::make('items')->readOnly()->schema([TextInput::make('sku')]),
    ])->fill(['sku' => 'server', 'name' => 'old', 'items' => [['sku' => 'a']]]);

    $form->applyClientState(['sku' => 'forged', 'name' => 'new', 'items' => [['sku' => 'forged'], ['sku' => 'added']]]);

    expect($form->getState())->toMatchArray(['sku' => 'server', 'name' => 'new', 'items' => [['sku' => 'a']]]);
});

it('keeps the server value of read-only fields inside existing relationship repeater items', function (): void {
    $post = AlfaSix\Alfakit\Tests\Fixtures\Models\Post::query()->create(['title' => 'P', 'slug' => 'p']);
    $first = $post->comments()->create(['author_name' => 'A', 'body' => 'first', 'sort' => 0]);
    $second = $post->comments()->create(['author_name' => 'B', 'body' => 'second', 'sort' => 1]);

    $form = schema([
        Repeater::make('comments')->relationship()->orderColumn('sort')->schema([
            TextInput::make('author_name')->readOnly(),
            TextInput::make('body'),
        ]),
    ], record: $post, operation: 'edit')->fill();

    $form->applyClientState(['comments' => [
        ['id' => $second->id, 'author_name' => 'forged', 'body' => 'second!'],
        ['id' => $first->id, 'author_name' => 'forged', 'body' => 'first!'],
        ['author_name' => 'C', 'body' => 'new'],
    ]]);

    expect(array_map(fn (array $item): array => Illuminate\Support\Arr::only($item, ['author_name', 'body']), $form->getRawState()['comments']))->toBe([
        ['author_name' => 'B', 'body' => 'second!'],
        ['author_name' => 'A', 'body' => 'first!'],
        ['author_name' => 'C', 'body' => 'new'],
    ]);
});

it('keeps read-only values the server wrote during a live refresh', function (): void {
    $make = fn () => schema([
        TextInput::make('title')->afterStateUpdated(fn ($state, $set) => $set('slug', str($state)->slug()->toString())),
        TextInput::make('slug')->readOnly(),
    ])->fill();

    // Refresh: the browser typed a title, the server derived the slug.
    $payload = $make()->applyClientState(['title' => 'Hello World', 'slug' => null])->callAfterStateUpdated('title')->toPayload();
    $token = $payload['state'][AlfaSix\Alfakit\Schemas\Schema::READ_ONLY_STATE_KEY] ?? null;

    expect($token)->toBeString()
        ->and($make()->fill()->toPayload()['state'])->not->toHaveKey(AlfaSix\Alfakit\Schemas\Schema::READ_ONLY_STATE_KEY);

    // Submit: the slug written by the server survives, a forged one doesn't.
    $submitted = $make()->applyClientState([...$payload['state'], 'slug' => 'forged']);
    $tampered = $make()->applyClientState(['title' => 'Hello World', 'slug' => 'forged', AlfaSix\Alfakit\Schemas\Schema::READ_ONLY_STATE_KEY => 'nope']);

    expect($submitted->getState())->toBe(['title' => 'Hello World', 'slug' => 'hello-world'])
        ->and($tampered->getState())->toBe(['title' => 'Hello World', 'slug' => null]);
});
