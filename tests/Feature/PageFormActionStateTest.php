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

    expect(PreferencesPage::$saved)->toBe(['courier' => 'Converted shipping courier']);
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

    expect(PreferencesPage::$saved)->toBe(['courier' => 'Bahrain local courier']);
});
