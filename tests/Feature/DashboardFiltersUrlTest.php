<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Panel;
use AlfaSix\Alfakit\Tests\Fixtures\AuthTenancy\FilteredDashboard;
use AlfaSix\Alfakit\Tests\Fixtures\AuthTenancy\Panels;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Post;
use AlfaSix\Alfakit\Tests\Fixtures\Models\User;

/*
 * `persistsFiltersInSession()` returning false: dashboard filters live in the URL.
 */

class UrlFilteredDashboard extends FilteredDashboard
{
    protected static bool $persistsFiltersInSession = false;
}

class UrlFilterActionDashboard extends AlfaSix\Alfakit\Tests\Fixtures\ActionsParity\ParityDashboard
{
    protected static bool $persistsFiltersInSession = false;
}

function urlFiltersStats(array $props): array
{
    $widgets = collect($props['page']['content'])->firstWhere('type', 'Widgets');

    return array_column($widgets['widgets'][0]['stats'], 'value', 'label');
}

function urlFiltersPost(mixed $test, string $token, string $action, array $data, string $referer): Illuminate\Testing\TestResponse
{
    return $test->post('/urlboards/_alfakit/actions', ['token' => $token, 'action' => $action, 'data' => $data], [
        'X-Inertia' => 'true', 'X-Inertia-Version' => '', 'Accept' => 'application/json', 'Referer' => $referer,
    ]);
}

beforeEach(function (): void {
    Panels::register('urlboards', fn (Panel $panel): Panel => $panel->pages([UrlFilteredDashboard::class]));
    $this->actingAs(User::query()->create(['name' => 'D', 'email' => 'url'.uniqid().'@example.com', 'password' => 'secret']));
    Post::query()->create(['title' => 'Draft', 'slug' => 'draft', 'status' => 'draft']);
    Post::query()->create(['title' => 'Live', 'slug' => 'live', 'status' => 'published']);
});

it('keeps the filters in the URL instead of the session', function (): void {
    $props = inertiaProps(inertiaGet($this, '/urlboards?tab=x'));

    expect((new UrlFilteredDashboard)->persistsFiltersInSession())->toBeFalse()
        ->and((new FilteredDashboard)->persistsFiltersInSession())->toBeTrue();

    $response = urlFiltersPost($this, $props['page']['token'], 'applyFilters', ['status' => 'published', 'search' => ''], 'http://localhost/urlboards?tab=x')
        ->assertRedirect();

    $location = urldecode((string) $response->headers->get('Location'));

    expect($location)->toBe('http://localhost/urlboards?tab=x&filters[status]=published')
        ->and(session()->has((new UrlFilteredDashboard)->getFiltersSessionKey()))->toBeFalse();

    expect(urlFiltersStats(inertiaProps(inertiaGet($this, '/urlboards?filters[status]=published'))))->toBe(['Posts' => 1, 'Filter' => 'published'])
        // Without the query string the filters are gone.
        ->and(urlFiltersStats(inertiaProps(inertiaGet($this, '/urlboards'))))->toBe(['Posts' => 2, 'Filter' => 'all']);
});

it('resets URL filters by redirecting without them', function (): void {
    $props = inertiaProps(inertiaGet($this, '/urlboards?filters[status]=draft'));

    $location = urlFiltersPost($this, $props['page']['token'], 'resetFilters', [], 'http://localhost/urlboards?filters[status]=draft')
        ->assertRedirect()
        ->headers->get('Location');

    expect($location)->toBe('http://localhost/urlboards');
});

it('keeps the apply button for live fields when filters live in the URL', function (): void {
    $dashboard = new class extends UrlFilteredDashboard
    {
        public function hasOnlyLiveFiltersPublic(): bool
        {
            return $this->hasOnlyLiveFilters();
        }
    };

    expect($dashboard->hasOnlyLiveFiltersPublic())->toBeFalse();
});

it('ignores malformed filters in the URL', function (): void {
    expect(urlFiltersStats(inertiaProps(inertiaGet($this, '/urlboards?filters=oops'))))->toBe(['Posts' => 2, 'Filter' => 'all']);
});

it('lets an own dashboard subclass replace the registered dashboard on the same route', function (): void {
    // The generated provider registers AlfaSix's Dashboard; the app adds its own (discovered).
    Panels::register('ownboard', fn (Panel $panel): Panel => $panel->pages([AlfaSix\Alfakit\Pages\Dashboard::class, FilteredDashboard::class]));
    $panel = alfakit()->getPanel('ownboard');

    expect($panel->getPages())->toBe([FilteredDashboard::class]);

    inertiaGet($this, '/ownboard')->assertOk();
});

it('closes the filter slide-over when its Reset redirects to the unfiltered dashboard', function (): void {
    Panels::register('urlactions', fn (Panel $panel): Panel => $panel->pages([UrlFilterActionDashboard::class]));

    $props = inertiaProps(inertiaGet($this, '/urlactions/parity-dashboard?filters[region]=north'));

    $response = $this->post('/urlactions/_alfakit/actions', ['token' => $props['page']['token'], 'action' => 'filter>resetFilters', 'mountedData' => ['filter' => ['region' => 'north']]], [
        'X-Inertia' => 'true', 'X-Inertia-Version' => '', 'Accept' => 'application/json', 'Referer' => 'http://localhost/urlactions/parity-dashboard?filters[region]=north',
    ])->assertRedirect();

    expect($response->headers->get('Location'))->toBe('http://localhost/urlactions/parity-dashboard');

    // The redirect is the action's own, and still carries its result: the nested Reset
    // asked for the modal it was opened from to close (`cancelParentActions()`).
    $landed = inertiaGet($this, '/urlactions/parity-dashboard')->json();
    $flash = $landed['flash']['alfakitAction'] ?? $landed['props']['flash']['alfakitAction'] ?? null;

    expect($flash)->toMatchArray(['name' => 'resetFilters', 'status' => 'success', 'cancelParentActions' => true]);
});
