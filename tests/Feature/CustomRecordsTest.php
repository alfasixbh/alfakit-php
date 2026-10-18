<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Tests\Fixtures\Models\User;
use AlfaSix\Alfakit\Tests\Fixtures\Panel\Pages\PlansPage;

function plansUser(): User
{
    return User::query()->firstOrCreate(['email' => 'plans@example.com'], ['name' => 'Plans', 'password' => 'password']);
}

/**
 * @return array<string, mixed>
 */
function plansPage(User $user, string $query = ''): array
{
    return inertiaProps(inertiaGet(test()->actingAs($user), '/admin/plans'.$query));
}

beforeEach(fn () => PlansPage::reset());

it('serialises custom rows with their record actions and bulk selection', function (): void {
    $props = plansPage(plansUser());
    $table = $props['page']['content'][0];

    expect($table['selectable'])->toBeTrue()
        ->and(array_column($table['records'], 'key'))->toBe(['basic', 'pro', 'team'])
        ->and($table['records'][0]['selectable'])->toBeTrue()
        ->and($table['records'][0]['actions'][0])->toMatchArray(['name' => 'archive', 'record' => 'basic'])
        // The `hidden()` closure received the row as `$record`.
        ->and($table['records'][1])->not->toHaveKey('actions')
        ->and($table['columns'][1]['editable'])->toBeTrue();
});

it('runs record actions on a custom row resolved by key', function (): void {
    $props = plansPage(plansUser());

    inertiaPost($this, '/admin/_alfakit/actions', ['token' => $props['page']['token'], 'component' => 'table', 'action' => 'archive', 'record' => 'team'])
        ->assertRedirect();

    expect(PlansPage::$runs)->toBe([['archive' => 'Team']]);

    // A hidden action (inactive plan) and an unknown key are refused.
    inertiaPost($this, '/admin/_alfakit/actions', ['token' => $props['page']['token'], 'component' => 'table', 'action' => 'archive', 'record' => 'pro'])->assertForbidden();
    inertiaPost($this, '/admin/_alfakit/actions', ['token' => $props['page']['token'], 'component' => 'table', 'action' => 'archive', 'record' => 'nope'])->assertNotFound();
});

it('runs bulk actions on selected custom rows and on every filtered row', function (): void {
    $props = plansPage(plansUser());

    inertiaPost($this, '/admin/_alfakit/actions', ['token' => $props['page']['token'], 'component' => 'table', 'action' => 'export', 'records' => ['team', 'basic', 'nope']])
        ->assertRedirect();

    expect(PlansPage::$runs)->toBe([['export' => ['Basic', 'Team']]]);

    PlansPage::$runs = [];
    $props = plansPage(plansUser(), '?search=o');

    inertiaPost($this, '/admin/_alfakit/actions', ['token' => $props['page']['token'], 'component' => 'table', 'action' => 'export', 'records' => [], 'allRecords' => true, 'tableQuery' => 'search=o'])
        ->assertRedirect();

    expect(PlansPage::$runs)->toBe([['export' => ['Pro']]]);
});

it('saves inline cell edits of custom rows through updateStateUsing and refuses columns without it', function (): void {
    $props = plansPage(plansUser());
    $token = $props['page']['token'];

    $this->postJson('/admin/_alfakit/table/cell', ['token' => $token, 'component' => 'table', 'record' => 'pro', 'column' => 'price', 'state' => 45])
        ->assertOk()->assertJsonPath('state', 45);

    expect(PlansPage::$plans['pro']['price'])->toBe(45);

    $this->postJson('/admin/_alfakit/table/cell', ['token' => $token, 'component' => 'table', 'record' => 'pro', 'column' => 'price', 'state' => 'abc'])
        ->assertStatus(422);
    $this->postJson('/admin/_alfakit/table/cell', ['token' => $token, 'component' => 'table', 'record' => 'missing', 'column' => 'price', 'state' => 1])
        ->assertNotFound();
    // The toggle column has no `updateStateUsing()`: nothing to save an array row to.
    $this->postJson('/admin/_alfakit/table/cell', ['token' => $token, 'component' => 'table', 'record' => 'pro', 'column' => 'active', 'state' => true])
        ->assertStatus(400);
});
