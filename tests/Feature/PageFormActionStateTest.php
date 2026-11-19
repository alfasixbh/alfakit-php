<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Tests\Fixtures\FormActions\PreferencesPage;
use AlfaSix\Alfakit\Tests\Fixtures\Models\User;

beforeEach(function (): void {
    PreferencesPage::$saved = null;
    $this->user = User::query()->create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'password']);
    $this->actingAs($this->user);
});

it('gives a form button the state the user typed, not the state the page was filled with (FM-B02)', function (): void {
    $this->postJson('/admin/_alfakit/actions', [
        'token' => PreferencesPage::token($this->user),
        'component' => 'page',
        'action' => 'save',
        'data' => ['courier' => 'Converted shipping courier'],
    ])->assertSuccessful();

    // The rows were not sent, which removes them (only adding is locked).
    expect(PreferencesPage::$saved)->toBe(['courier' => 'Converted shipping courier', 'limits' => []]);
});

it('validates the typed state when the form button reads it', function (): void {
    $this->postJson('/admin/_alfakit/actions', [
        'token' => PreferencesPage::token($this->user),
        'component' => 'page',
        'action' => 'save',
        'data' => ['courier' => ''],
    ])->assertJsonValidationErrors(['courier']);

    expect(PreferencesPage::$saved)->toBeNull();
});

it('leaves the page form alone for header actions, which do not submit it', function (): void {
    $this->postJson('/admin/_alfakit/actions', [
        'token' => PreferencesPage::token($this->user),
        'component' => 'page',
        'action' => 'peek',
        'data' => ['courier' => 'Typed'],
    ])->assertSuccessful();

    expect(PreferencesPage::$saved)->toBe(['courier' => 'Bahrain local courier', 'limits' => ['weight' => '30', 'parcels' => '5']]);
});

it('compares a button form made of the page fields with what the page filled them with', function (): void {
    $rows = [['key' => 'weight', 'value' => '45'], ['key' => 'parcels', 'value' => '5']];
    $request = fn (array $limits): Illuminate\Testing\TestResponse => $this->postJson('/admin/_alfakit/actions', [
        'token' => PreferencesPage::token($this->user),
        'component' => 'page',
        'action' => 'saveWithForm',
        'data' => ['courier' => 'Courier', 'limits' => $limits],
    ]);

    // The page's own rows, one value changed: allowed, although nothing may be added.
    $request($rows)->assertSuccessful();
    expect(PreferencesPage::$saved)->toBe(['courier' => 'Courier', 'limits' => ['weight' => '45', 'parcels' => '5']]);

    // A row the page never had is still refused.
    PreferencesPage::$saved = null;
    $request([...$rows, ['key' => 'extra', 'value' => '1']])->assertJsonValidationErrors(['limits']);
    expect(PreferencesPage::$saved)->toBeNull();
});
