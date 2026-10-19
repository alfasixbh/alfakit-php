<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Forms\Components\Repeater;
use AlfaSix\Alfakit\Forms\Components\TagsInput;
use AlfaSix\Alfakit\Forms\Components\Textarea;
use AlfaSix\Alfakit\Forms\Components\TextInput;
use Illuminate\Validation\ValidationException;

it('trims text inputs, textareas and tags before validating and saving', function (): void {
    $schema = schema([
        TextInput::make('name')->trim()->maxLength(3),
        TextInput::make('untrimmed'),
        Textarea::make('bio')->trim(),
        TagsInput::make('tags')->trim(),
    ])->fill();

    $schema->applyClientState(['name' => '  Ann  ', 'untrimmed' => ' x ', 'bio' => "\n Hi \n", 'tags' => [' a ', 'b', '  ']]);

    // `maxLength(3)` passes because the validator sees the trimmed value.
    expect($schema->validate())->toBe(['name' => 'Ann', 'untrimmed' => ' x ', 'bio' => 'Hi', 'tags' => ['a', 'b']])
        // The form state keeps what the user typed (live refreshes never eat their spaces).
        ->and($schema->getRawState()['name'])->toBe('  Ann  ');
});

it('saves a whitespace-only trimmed value as null', function (): void {
    $schema = schema([TextInput::make('name')->trim()])->fill();
    $schema->applyClientState(['name' => '   ']);

    expect($schema->getState())->toBe(['name' => null]);
});

it('evaluates trim() from a closure', function (): void {
    $schema = schema([
        TextInput::make('kind'),
        TextInput::make('name')->trim(fn ($get): bool => $get('kind') === 'strict'),
    ])->fill();

    $schema->applyClientState(['kind' => 'loose', 'name' => ' a ']);
    expect($schema->getState()['name'])->toBe(' a ');

    $schema->applyClientState(['kind' => 'strict', 'name' => ' a ']);
    expect($schema->getState()['name'])->toBe('a');
});

it('strips characters before numeric validation and casting', function (): void {
    $schema = schema([
        TextInput::make('amount')->numeric()->stripCharacters(','),
        TextInput::make('phone')->stripCharacters([' ', '-']),
    ])->fill();

    $schema->applyClientState(['amount' => '1,234.5', 'phone' => '555 12-34']);

    expect($schema->validate())->toBe(['amount' => 1234.5, 'phone' => '5551234']);
});

it('still rejects values that are invalid after stripping', function (): void {
    $schema = schema([TextInput::make('amount')->numeric()->stripCharacters(',')])->fill();
    $schema->applyClientState(['amount' => '1,2a']);

    expect(fn () => $schema->validate())->toThrow(ValidationException::class);
});

it('trims and strips fields inside repeater items for validation', function (): void {
    $schema = schema([
        Repeater::make('lines')->schema([TextInput::make('qty')->integer()->stripCharacters(',')->trim()]),
    ])->fill();

    $schema->applyClientState(['lines' => [['qty' => ' 1,000 '], ['qty' => '2']]]);

    expect($schema->getValidator()->passes())->toBeTrue();
});

it('runs mutateStateForValidationUsing() closures', function (): void {
    $schema = schema([
        TextInput::make('code')->in(['ABC'])->mutateStateForValidationUsing(fn (?string $state): ?string => strtoupper((string) $state)),
    ])->fill();

    $schema->applyClientState(['code' => 'abc']);

    expect($schema->getValidator()->passes())->toBeTrue()
        ->and($schema->getRawState()['code'])->toBe('abc');
});

it('validates tel() with a custom telRegex()', function (): void {
    $default = schema([TextInput::make('phone')->tel()])->fill();
    $default->applyClientState(['phone' => '+1 555-123']);

    $custom = schema([TextInput::make('phone')->tel()->telRegex('/^\d{3}$/')])->fill();
    $custom->applyClientState(['phone' => '+1 555-123']);

    expect($default->getValidator()->passes())->toBeTrue()
        ->and($custom->getValidator()->passes())->toBeFalse()
        ->and(TextInput::make('phone')->getTelRegex())->toBe(TextInput::DEFAULT_TEL_REGEX);
});

it('serialises autocomplete(false) as off and copyable() with its message', function (): void {
    $off = TextInput::make('a')->autocomplete(false);
    $on = TextInput::make('b')->autocomplete();
    $named = TextInput::make('c')->autocomplete('new-password');
    $copy = TextInput::make('d')->copyable(copyMessage: 'Copied!', copyMessageDuration: 1500);
    $notCopyable = TextInput::make('e')->copyable(fn (): bool => false, 'Never shown');

    schema([$off, $on, $named, $copy, $notCopyable]);

    expect($off->toSchema()['autocomplete'])->toBe('off')
        ->and($on->toSchema()['autocomplete'])->toBe('on')
        ->and($named->toSchema()['autocomplete'])->toBe('new-password')
        ->and($copy->toSchema())->toMatchArray(['copyable' => true, 'copyMessage' => 'Copied!', 'copyMessageDuration' => 1500])
        ->and($notCopyable->toSchema())->not->toHaveKeys(['copyable', 'copyMessage']);
});

it('takes copyMessage() and copyMessageDuration() as Filament-style setters', function (): void {
    $input = TextInput::make('code')->copyable()->copyMessage('Copied!')->copyMessageDuration(1500);
    $props = (fn () => $this->getSchemaProps())->call($input);

    expect($props['copyable'] ?? null)->not->toBeNull()
        ->and(json_encode($props))->toContain('Copied!')->toContain('1500');
});

it('validates a number held by a text input as its text', function (): void {
    $schema = schema([
        TextInput::make('zip')->maxLength(5),
        TextInput::make('total'),
        TextInput::make('code')->maxLength(3),
    ])->fill(['zip' => 12345, 'total' => 40.5, 'code' => 12345]);

    expect($schema->getValidator()->errors()->toArray())->toBe(['code' => ['The code field must not be greater than 3 characters.']])
        ->and($schema->getRawState()['zip'])->toBe(12345);
});
