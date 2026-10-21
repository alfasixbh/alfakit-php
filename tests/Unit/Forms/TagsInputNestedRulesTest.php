<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Forms\Components\TagsInput;
use Illuminate\Validation\ValidationException;

/*
 * `nestedRecursiveRules()` validate each tag. The errors are keyed by the tag's path
 * (`labels.1`, which the renderers show on the field) and name the field by its label,
 * not by the raw path ("The labels.1 field …").
 */
it('refuses a tag with the field label as the attribute, keyed by the tag', function (): void {
    $form = schema([
        TagsInput::make('labels')->nestedRecursiveRules(['min:5']),
    ])->fill();

    $form->applyClientState(['labels' => ['valid', 'ab']]);

    try {
        $form->validate();
        $this->fail('The short tag was accepted.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('labels.1')
            ->not->toHaveKey('labels.0')
            ->and($exception->errors()['labels.1'][0])->toContain('labels field')
            ->not->toContain('labels.1');
    }
});

it('validates the tags of a field without other rules', function (): void {
    $form = schema([
        TagsInput::make('labels')->nestedRecursiveRules('alpha'),
    ])->fill();

    $form->applyClientState(['labels' => ['ok', 'n0t']]);

    expect(fn () => $form->validate())->toThrow(ValidationException::class);

    $form->applyClientState(['labels' => ['ok']]);

    expect($form->validate())->toBe(['labels' => ['ok']]);
});
