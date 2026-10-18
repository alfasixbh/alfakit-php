<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Tests\Fixtures\Standalone\ResettingForm;
use AlfaSix\Alfakit\Tests\TestCase;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

/*
 * `reset()` and `resetAfterSubmit()` on standalone forms: the render after a successful
 * submit fills the form with defaults instead of the page's `fill()` data.
 */

beforeEach(function (): void {
    ResettingForm::$resetInSubmit = false;

    Route::middleware('web')->group(function (): void {
        Route::get('/resetting', fn () => Inertia::render('Resetting', ['form' => ResettingForm::make()->fill(['name' => 'Draft', 'country' => 'FR'])]));
        Route::get('/resetting-always', fn () => Inertia::render('Resetting', ['form' => ResettingForm::make()->fill(['name' => 'Draft'])->resetAfterSubmit()]));
    });
});

/**
 * @return array<string, mixed>
 */
function resettingFormState(TestCase $test, string $url): array
{
    return inertiaProps(inertiaGet($test, $url))['form']['content'][0]['state'];
}

/**
 * @param  array<string, mixed>  $data
 */
function submitResettingForm(TestCase $test, string $url, array $data): void
{
    $token = inertiaProps(inertiaGet($test, $url))['form']['token'];

    inertiaPost($test, '/_alfakit/actions', ['token' => $token, 'action' => 'submit', 'data' => $data])->assertRedirect();
}

it('keeps filling the form from the page without a reset', function (): void {
    submitResettingForm($this, '/resetting', ['name' => 'Ann', 'country' => 'DE']);

    expect(resettingFormState($this, '/resetting'))->toMatchArray(['name' => 'Draft', 'country' => 'FR']);
});

it('shows defaults plus the reset data on the render after reset()', function (): void {
    ResettingForm::$resetInSubmit = true;
    submitResettingForm($this, '/resetting', ['name' => 'Ann', 'country' => 'DE']);

    expect(resettingFormState($this, '/resetting'))->toMatchArray(['name' => null, 'country' => 'BE'])
        // The flash is used once.
        ->and(resettingFormState($this, '/resetting'))->toMatchArray(['name' => 'Draft', 'country' => 'FR']);
});

it('resets after every submit with resetAfterSubmit(), carried in the token', function (): void {
    submitResettingForm($this, '/resetting-always', ['name' => 'Ann']);

    expect(resettingFormState($this, '/resetting-always'))->toMatchArray(['name' => null, 'country' => 'NL']);
});

it('does not reset when validation fails', function (): void {
    $token = inertiaProps(inertiaGet($this, '/resetting-always'))['form']['token'];

    $this->postJson('/_alfakit/actions', ['token' => $token, 'action' => 'submit', 'data' => ['name' => '']])->assertStatus(422);

    expect(resettingFormState($this, '/resetting-always'))->toMatchArray(['name' => 'Draft']);
});
