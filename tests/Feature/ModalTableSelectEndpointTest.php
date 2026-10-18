<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Panel;
use AlfaSix\Alfakit\Tests\Fixtures\FormsA\PickerPage;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Post;
use AlfaSix\Alfakit\Tests\Fixtures\Models\User;

beforeEach(function (): void {
    $panel = Panel::make()->id('picker')->path('picker')->login()->middleware(['web'])->pages([PickerPage::class]);

    alfakit()->registerPanel($panel);
    $panel->registerRoutes(app('router'));
    app('router')->getRoutes()->refreshNameLookups();
});

/**
 * @return array{0: User, 1: string}
 */
function pickerSession(AlfaSix\Alfakit\Tests\TestCase $test): array
{
    $user = User::query()->create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'password']);
    User::query()->create(['name' => 'Zed', 'email' => 'zed@example.com', 'password' => 'password']);
    User::query()->create(['name' => 'Banned', 'email' => 'banned@example.com', 'password' => 'password']);

    $props = inertiaProps(inertiaGet($test->actingAs($user), '/picker/picker'));

    return [$user, $props['page']['token']];
}

/**
 * @param  array<string, mixed>  $query
 */
function pickerUrl(string $token, string $path, array $query = []): string
{
    return '/picker/_alfakit/schema/options?'.http_build_query(['token' => $token, 'component' => 'form', 'path' => $path, 'table' => 1, ...$query]);
}

it('serves the picker table with labels, applying the relationship and table configuration', function (): void {
    [, $token] = pickerSession($this);

    $response = $this->getJson(pickerUrl($token, 'author_id'))->assertOk();

    expect(array_column($response->json('table.columns'), 'name'))->toBe(['name', 'email'])
        ->and(array_map(static fn (array $record): string => $record['cells']['name']['state'], $response->json('table.records')))->toEqualCanonicalizing(['Admin', 'Zed'])
        ->and($response->json('labels'))->toEqualCanonicalizing([(string) User::query()->where('name', 'Admin')->value('id') => 'Admin', (string) User::query()->where('name', 'Zed')->value('id') => 'Zed'])
        ->and($response->json('table.records.0.actions'))->toBeNull()
        ->and($response->json('table.records.0.url'))->toBeNull()
        ->and($response->json('table.records.0.cells.name.disabled'))->toBeTrue()
        ->and($response->json('table.bulkActions'))->toBeNull();

    $this->getJson(pickerUrl($token, 'author_id', ['table_search' => 'ze']))
        ->assertOk()
        ->assertJsonCount(1, 'table.records')
        ->assertJsonPath('table.records.0.cells.name.state', 'Zed')
        ->assertJsonPath('table.search', 'ze');
});

it('pages, sorts and filters the picker table and never lists records outside its query', function (): void {
    [$user, $token] = pickerSession($this);

    foreach (['Alpha', 'Bravo', 'Charlie', 'Secret'] as $index => $title) {
        Post::query()->create(['title' => $title, 'slug' => strtolower($title), 'author_id' => $user->id, 'is_published' => $index === 0]);
    }

    $first = $this->getJson(pickerUrl($token, 'post_ids', ['table_sort' => 'title', 'table_direction' => 'desc']))->assertOk();

    expect(array_values($first->json('labels')))->toBe(['Charlie', 'Bravo'])
        ->and($first->json('table.pagination.total'))->toBe(3)
        ->and($first->json('table.pagination.lastPage'))->toBe(2);

    $second = $this->getJson(pickerUrl($token, 'post_ids', ['table_sort' => 'title', 'table_direction' => 'desc', 'table_page' => 2]))->assertOk();

    expect(array_values($second->json('labels')))->toBe(['Alpha']);

    $filtered = $this->getJson(pickerUrl($token, 'post_ids', ['table_filters' => ['is_published' => ['value' => '1']]]))->assertOk();

    expect(array_values($filtered->json('labels')))->toBe(['Alpha'])
        ->and($filtered->json('table.filterIndicators'))->not->toBeEmpty();
});

it('validates submitted keys against the picker query', function (): void {
    [$user] = pickerSession($this);
    $banned = User::query()->where('name', 'Banned')->value('id');
    $secret = Post::query()->create(['title' => 'Secret', 'slug' => 'secret', 'author_id' => $user->id]);
    $visible = Post::query()->create(['title' => 'Visible', 'slug' => 'visible', 'author_id' => $user->id]);

    $page = new PickerPage;
    $form = $page->getForm();

    $form->applyClientState(['author_id' => $banned, 'post_ids' => [$visible->id, $secret->id]]);
    $errors = rescue(fn () => $form->getValidator()->errors()->toArray(), report: false);

    expect($errors)->toHaveKeys(['author_id', 'post_ids.1'])
        ->and($errors)->not->toHaveKey('post_ids.0');

    $form->applyClientState(['author_id' => $user->id, 'post_ids' => [$visible->id]]);

    expect($form->getValidator()->errors()->toArray())->toBe([]);
});

it('refuses picker tables of hidden, disabled and unknown fields and forged tokens', function (): void {
    [, $token] = pickerSession($this);

    $this->getJson(pickerUrl($token, 'hidden_id'))->assertNotFound();
    $this->getJson(pickerUrl($token, 'locked_id'))->assertForbidden();
    $this->getJson(pickerUrl($token, 'nope'))->assertNotFound();
    $this->getJson(pickerUrl('forged', 'author_id'))->assertStatus(400);

    auth()->logout();
    $this->getJson(pickerUrl($token, 'author_id'))->assertForbidden();
});

it('returns labels for selected values without opening the table', function (): void {
    [$user, $token] = pickerSession($this);

    $this->getJson('/picker/_alfakit/schema/options?'.http_build_query(['token' => $token, 'component' => 'form', 'path' => 'author_id', 'values' => [$user->id]]))
        ->assertOk()
        ->assertJsonPath('labels.'.$user->id, 'Admin');
});
