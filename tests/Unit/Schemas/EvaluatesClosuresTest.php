<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Forms\Components\TextInput;
use AlfaSix\Alfakit\Schemas\Components\Utilities\Get;
use AlfaSix\Alfakit\Schemas\Components\Utilities\Set;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Post;
use Illuminate\Database\Eloquent\Model;

it('returns non-closure values untouched', function (): void {
    expect(TextInput::make('a')->evaluate('hello'))->toBe('hello')
        ->and(TextInput::make('a')->evaluate(42))->toBe(42);
});

it('injects named parameters', function (): void {
    $field = TextInput::make('a');

    expect($field->evaluate(fn ($state, $foo) => [$state, $foo], ['state' => 'x', 'foo' => 'bar']))->toBe(['x', 'bar']);
});

it('injects the component, record, operation, get and set by name', function (): void {
    $post = new Post(['title' => 'Hi']);
    $field = TextInput::make('title');
    schema([$field], record: $post, operation: 'edit')->fill();

    $result = $field->evaluate(fn ($component, $record, $operation, $get, $set, $state) => compact('component', 'record', 'operation', 'get', 'set', 'state'));

    expect($result['component'])->toBe($field)
        ->and($result['record'])->toBe($post)
        ->and($result['operation'])->toBe('edit')
        ->and($result['get'])->toBeInstanceOf(Get::class)
        ->and($result['set'])->toBeInstanceOf(Set::class)
        ->and($result['state'])->toBe('Hi');
});

it('injects by type hint', function (): void {
    $post = new Post(['title' => 'Hi']);
    $field = TextInput::make('title');
    schema([$field], record: $post)->fill();

    expect($field->evaluate(fn (Model $m) => $m))->toBe($post)
        ->and($field->evaluate(fn (Post $p) => $p))->toBe($post)
        ->and($field->evaluate(fn (TextInput $c) => $c))->toBe($field)
        ->and($field->evaluate(fn (Get $g) => $g))->toBeInstanceOf(Get::class);
});

it('falls back to defaults and null, and resolves from the container', function (): void {
    $field = TextInput::make('a');

    expect($field->evaluate(fn ($missing = 'd') => $missing))->toBe('d')
        ->and($field->evaluate(fn (?string $nothing) => $nothing))->toBeNull()
        ->and($field->evaluate(fn (Illuminate\Contracts\Config\Repository $config) => $config))->toBe(app('config'));
});

it('applies global configuration callbacks', function (): void {
    TextInput::configureUsing(fn (TextInput $input) => $input->maxLength(10));

    expect(TextInput::make('a')->toSchema()['maxLength'])->toBe(10);

    TextInput::clearConfigurations();
});
