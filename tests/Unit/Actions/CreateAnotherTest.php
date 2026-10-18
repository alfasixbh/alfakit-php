<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Actions\CreateAction;
use AlfaSix\Alfakit\Forms\Components\TextInput;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Tag;

function tagCreateAction(): CreateAction
{
    return CreateAction::make()->model(Tag::class)->form([TextInput::make('name')->required()]);
}

it('offers "Create & create another" in the modal by default', function (): void {
    $modal = tagCreateAction()->toModalSchema();

    expect($modal['createAnotherLabel'])->toBe('Create & create another')
        ->and($modal)->not->toHaveKey('preserveFormData');
});

it('hides "Create & create another" with createAnother(false)', function (): void {
    expect(tagCreateAction()->createAnother(false)->toModalSchema())->not->toHaveKey('createAnotherLabel');
});

it('serialises the fields kept when creating another', function (): void {
    expect(tagCreateAction()->preserveFormDataWhenCreatingAnother()->toModalSchema()['preserveFormData'])->toBeTrue()
        ->and(tagCreateAction()->preserveFormDataWhenCreatingAnother(['name'])->toModalSchema()['preserveFormData'])->toBe(['name']);
});

it('keeps the modal open and skips the redirect when creating another', function (): void {
    $action = tagCreateAction()->successRedirectUrl('/tags');

    $action->execute(['name' => 'First'], [CreateAction::CREATE_ANOTHER_ARGUMENT => true]);

    expect(Tag::query()->pluck('name')->all())->toBe(['First'])
        ->and($action->shouldCloseModal())->toBeFalse()
        ->and($action->getSuccessRedirectUrl())->toBeNull();

    $plain = tagCreateAction()->successRedirectUrl('/tags');
    $plain->execute(['name' => 'Second']);

    expect($plain->shouldCloseModal())->toBeTrue()
        ->and($plain->getSuccessRedirectUrl())->toBe('/tags');

    // Disabled: the argument is ignored.
    $disabled = tagCreateAction()->createAnother(false);
    $disabled->execute(['name' => 'Third'], [CreateAction::CREATE_ANOTHER_ARGUMENT => true]);

    expect($disabled->shouldCloseModal())->toBeTrue();
});
