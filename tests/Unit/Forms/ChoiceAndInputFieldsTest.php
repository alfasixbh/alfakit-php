<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Forms\Components\CodeEditor;
use AlfaSix\Alfakit\Forms\Components\CodeEditor\Enums\Language;
use AlfaSix\Alfakit\Forms\Components\Slider;
use AlfaSix\Alfakit\Forms\Components\ToggleButtons;
use Illuminate\Validation\ValidationException;

/**
 * @param  array<string, mixed>  $state
 * @return array<string, array<string>>
 */
function fieldErrors(AlfaSix\Alfakit\Forms\Components\Field $field, array $state): array
{
    $schema = schema([$field])->fill()->applyClientState($state);

    try {
        $schema->validate();
    } catch (ValidationException $exception) {
        return $exception->errors();
    }

    return [];
}

// ---------------------------------------------------------------- ToggleButtons

it('serialises toggle buttons with colours, icons and layout', function (): void {
    $field = ToggleButtons::make('status')
        ->options(['draft' => 'Draft', 'published' => 'Published'])
        ->colors(['draft' => 'gray', 'published' => 'success'])
        ->icons(['draft' => 'pencil', 'published' => 'check'])
        ->grouped();

    schema([$field])->fill();
    $props = $field->toSchema();

    expect($props['type'])->toBe('ToggleButtons')
        ->and($props['options'][1])->toBe(['value' => 'published', 'label' => 'Published', 'icon' => 'check', 'color' => 'success'])
        ->and($props['grouped'])->toBeTrue()
        ->and($props['inline'])->toBeTrue()
        ->and($props)->not->toHaveKey('multiple');
});

it('validates toggle button values, including disabled options and multiple values', function (): void {
    $single = fn () => ToggleButtons::make('status')->options(['a' => 'A', 'b' => 'B', 'c' => 'C'])->disableOptionWhen(['c']);
    $multiple = fn () => ToggleButtons::make('tags')->multiple()->options(['a' => 'A', 'b' => 'B']);

    expect(fieldErrors($single(), ['status' => 'x']))->toHaveKey('status')
        ->and(fieldErrors($single(), ['status' => 'c']))->toHaveKey('status')
        ->and(fieldErrors($single(), ['status' => 'b']))->toBe([])
        ->and(fieldErrors($multiple(), ['tags' => ['a', 'x']]))->toHaveKey('tags.1')
        ->and(fieldErrors($multiple(), ['tags' => 'a']))->toHaveKey('tags')
        ->and(fieldErrors($multiple(), ['tags' => ['a', 'a']]))->toHaveKey('tags.0')
        ->and(fieldErrors($multiple(), ['tags' => ['b', 'a']]))->toBe([]);

    $schema = schema([$multiple()])->fill()->applyClientState(['tags' => ['b']]);

    expect($schema->validate())->toBe(['tags' => ['b']]);
});

it('stores boolean toggle buttons as booleans', function (): void {
    $field = ToggleButtons::make('active')->boolean();
    $schema = schema([$field])->fill()->applyClientState(['active' => '0']);

    expect($schema->validate())->toBe(['active' => false])
        ->and($field->toSchema()['options'][0])->toMatchArray(['value' => '1', 'label' => 'Yes', 'color' => 'success', 'icon' => 'check'])
        ->and($field->toSchema()['boolean'])->toBeTrue()
        ->and(fieldErrors(ToggleButtons::make('active')->boolean(), ['active' => 'maybe']))->toHaveKey('active');
});

// ---------------------------------------------------------------- Slider

it('serialises slider bounds, steps, tooltips and pips', function (): void {
    $field = Slider::make('volume')->range(minValue: 10, maxValue: 50)->step(5)->tooltips()->pips(density: 10)->fillTrack();
    schema([$field])->fill();
    $props = $field->toSchema();

    expect($props)->toMatchArray(['type' => 'Slider', 'min' => 10, 'max' => 50, 'step' => 5, 'tooltips' => true, 'pips' => true, 'pipsDensity' => 10, 'fillTrack' => true])
        ->and($props)->not->toHaveKey('multiple')
        ->and(Slider::make('x')->decimalPlaces(2)->getStep())->toBe(0.01);
});

it('validates and casts a single slider value', function (): void {
    $field = fn () => Slider::make('volume')->range(0, 10)->decimalPlaces(0);

    expect(fieldErrors($field(), ['volume' => 11]))->toHaveKey('volume')
        ->and(fieldErrors($field(), ['volume' => 'loud']))->toHaveKey('volume')
        ->and(schema([$field()])->fill()->applyClientState(['volume' => '7.4'])->validate())->toBe(['volume' => 7]);
});

it('treats an array default as a range with sorted values and difference limits', function (): void {
    $field = fn () => Slider::make('price')->range(0, 100)->default([20, 80])->maxDifference(50);

    $schema = schema([$field()])->fill();

    expect($schema->getRawState())->toBe(['price' => [20, 80]])
        ->and($field()->isMultiple())->toBeTrue()
        ->and(fieldErrors($field(), ['price' => [10, 20]]))->toBe([])
        ->and(fieldErrors($field(), ['price' => [10, 90]]))->toHaveKey('price')
        ->and(fieldErrors($field(), ['price' => [10, 120]]))->toHaveKey('price.1')
        ->and(fieldErrors($field(), ['price' => 30]))->toHaveKey('price')
        ->and(schema([$field()])->fill()->applyClientState(['price' => [40, '15']])->validate())->toBe(['price' => [15, 40]]);
});

it('treats a list filled by the server as a range, but not a list sent by the browser', function (): void {
    $field = fn () => Slider::make('price')->range(0, 1000)->step(10);

    // Filled from the record / formData without default([...]): two thumbs, validated as a range.
    $filled = $field();
    $schema = schema([$filled])->fill(['price' => [200, 800]]);

    expect($filled->isMultiple())->toBeTrue()
        ->and($filled->toSchema())->toMatchArray(['multiple' => true])
        ->and($schema->applyClientState(['price' => [300, '100']])->validate())->toBe(['price' => [100, 300]]);

    // A number on the server stays a single slider even when the browser sends a list.
    $single = $field();
    $schema = schema([$single])->fill(['price' => 40]);

    expect($single->isMultiple())->toBeFalse()
        ->and(fn () => $schema->applyClientState(['price' => [10, 20]])->validate())->toThrow(ValidationException::class)
        ->and($field()->isMultiple())->toBeFalse();

    // An action form or a create page hydrated the field with null: the posted list is a range.
    expect(schema([$field()])->fill()->applyClientState(['price' => [500, 100]])->validate())->toBe(['price' => [100, 500]])
        ->and(schema([$field()])->fill()->applyClientState(['price' => 40])->validate())->toBe(['price' => 40]);
});

// ---------------------------------------------------------------- CodeEditor

it('serialises the code editor language and options', function (): void {
    $field = CodeEditor::make('snippet')->language(Language::Php)->wrap()->maxHeight(400)->maxLength(5);
    schema([$field])->fill();

    expect($field->toSchema())->toMatchArray(['type' => 'CodeEditor', 'language' => 'php', 'lineNumbers' => true, 'wrap' => true, 'tabSize' => 4, 'maxHeight' => 400])
        ->and(CodeEditor::make('x')->language('ruby')->getLanguage())->toBe('ruby')
        ->and(fieldErrors(CodeEditor::make('snippet')->maxLength(5), ['snippet' => '<?php echo 1;']))->toHaveKey('snippet')
        ->and(fieldErrors(CodeEditor::make('snippet'), ['snippet' => ['not', 'code']]))->toHaveKey('snippet');
});
