<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Forms\Components\TextInput;
use AlfaSix\Alfakit\Forms\Components\Toggle;
use AlfaSix\Alfakit\Schemas\Components\Utilities\Get;

/*
 * A field hidden when the form loads never reaches the browser (hidden state stays on the
 * server). When a live toggle reveals it, it keeps the value the server filled (its
 * default on a create page, the record's on an edit page) instead of starting empty:
 * the CMS page form's "Page order" was empty and required after "Show advanced settings".
 */
function revealedForm(): AlfaSix\Alfakit\Schemas\Schema
{
    return schema([
        Toggle::make('advanced')->live()->dehydrated(false),
        TextInput::make('sort')->integer()->default(0)->required()
            ->visible(fn (Get $get): bool => (bool) $get('advanced'))
            ->dehydratedWhenHidden(),
        TextInput::make('title'),
    ]);
}

it('keeps the default of a field revealed after the form loaded', function (): void {
    $form = revealedForm()->fill();
    $sent = $form->toPayload()['state'];

    expect($sent)->not->toHaveKey('sort');

    // The browser turns the toggle on: it never had `sort`.
    $form = revealedForm()->fill();
    $form->applyClientState([...$sent, 'advanced' => true]);

    expect($form->toPayload()['state']['sort'] ?? null)->toBe(0)
        ->and($form->validate())->toMatchArray(['sort' => 0]);
});

it('keeps what the browser sends for a revealed field', function (): void {
    $form = revealedForm()->fill();
    $form->applyClientState(['advanced' => true, 'sort' => 3, 'title' => null]);

    expect($form->validate())->toMatchArray(['sort' => 3, 'title' => null]);

    $form = revealedForm()->fill();
    $form->applyClientState(['advanced' => true, 'sort' => null, 'title' => null]);

    expect(fn () => $form->validate())->toThrow(Illuminate\Validation\ValidationException::class);
});
