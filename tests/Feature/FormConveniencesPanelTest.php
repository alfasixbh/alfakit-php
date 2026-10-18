<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Tests\Fixtures\FormConveniences\FcPage;
use AlfaSix\Alfakit\Tests\Fixtures\FormConveniences\FcPanelProvider;
use AlfaSix\Alfakit\Tests\Fixtures\Models\User;

beforeEach(function (): void {
    FcPanelProvider::registerPanel();
    FcPage::$runs = [];
    FcPage::$canPlaceOrder = true;
});

function fcUser(): User
{
    return User::query()->create(['name' => 'Fc', 'email' => 'fc'.uniqid().'@example.com', 'password' => 'password']);
}

/**
 * @return array<string, mixed>
 */
function fcProps(mixed $test): array
{
    return inertiaProps(inertiaGet($test->actingAs(fcUser()), '/fc/checkout'))['page'];
}

/**
 * @param  array<int, array<string, mixed>>  $components
 * @return array<string, array<string, mixed>>
 */
function fcFind(array $components, array &$found = []): array
{
    foreach ($components as $component) {
        $found[$component['name'] ?? $component['key'] ?? uniqid()] = $component;
        fcFind($component['schema'] ?? [], $found);
    }

    return $found;
}

it('serialises inline labels, copyable inputs and wizard actions on a page form', function (): void {
    $page = fcProps($this);
    $components = fcFind(json_decode(json_encode($page), true)['form']['components'] ?? json_decode(json_encode($page), true)['content'][0]['components'] ?? []);

    expect($components)->toHaveKeys(['email', 'api_key', 'amount', 'wizard'])
        ->and($components['email'])->not->toHaveKey('inlineLabel')
        ->and($components['api_key'])->toMatchArray(['inlineLabel' => true, 'copyable' => true, 'copyMessage' => 'Key copied', 'copyMessageDuration' => 1500])
        ->and($components['amount']['inlineLabel'])->toBeTrue()
        ->and($components['wizard']['submitAction']['name'])->toBe('placeOrder')
        ->and($components['wizard']['nextAction']['label'])->toBe('Continue')
        ->and($components['wizard']['previousAction'])->toBeFalse()
        ->and($components['wizard']['persistStepInQueryString'])->toBe('checkout-step')
        ->and($components['wizard']['completedIcon'])->toBe('check-circle');
});

it('runs the wizard submit action through the action endpoint', function (): void {
    $token = fcProps($this)['token'];

    inertiaPost($this, '/fc/_alfakit/actions', ['token' => $token, 'action' => 'placeOrder'])->assertRedirect();

    expect(FcPage::$runs)->toBe([['placeOrder' => true]]);
});

it('refuses an unauthorised wizard submit action and leaves it out of the schema', function (): void {
    FcPage::$canPlaceOrder = false;
    $page = fcProps($this);

    expect(json_encode($page))->not->toContain('placeOrder');

    inertiaPost($this, '/fc/_alfakit/actions', ['token' => $page['token'], 'action' => 'placeOrder'])->assertForbidden();

    expect(FcPage::$runs)->toBe([]);
});

it('validates a wizard step with stripped characters on the server', function (): void {
    $token = fcProps($this)['token'];

    $this->postJson('/fc/_alfakit/schema/validate', ['token' => $token, 'component' => 'form', 'step' => 'cart', 'state' => ['amount' => '1,500', 'email' => ' a@b.c ']])
        ->assertNoContent();

    $this->postJson('/fc/_alfakit/schema/validate', ['token' => $token, 'component' => 'form', 'step' => 'cart', 'state' => ['amount' => '1,5x']])
        ->assertJsonValidationErrors('amount');
});
