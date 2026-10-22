<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Auth\MultiFactor\App\AppAuthentication;
use AlfaSix\Alfakit\Notifications\Notification;
use AlfaSix\Alfakit\Panel;
use AlfaSix\Alfakit\Support\StarterKitNavigation;
use AlfaSix\Alfakit\Tests\Fixtures\AuthTenancy\Panels;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Team;
use AlfaSix\Alfakit\Tests\Fixtures\Models\TenantUser;
use AlfaSix\Alfakit\Tests\Fixtures\Models\User;
use AlfaSix\Alfakit\Tests\Fixtures\Panel\Resources\PostResource;
use AlfaSix\Alfakit\Tests\Fixtures\Tenancy\ProjectResource;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

/*
 * Starter kit mode: the drop-in header components (`<AlfakitSearch />`,
 * `<AlfakitNotifications />`, `<AlfakitTenantSwitcher />`, `<AlfakitLanguageSwitcher />`) read the `widgets` of the shared
 * navigation and load the panel data from their endpoint; multi-factor authentication is
 * the host's.
 */

function widgetsPanel(string $id, Closure $configure): Panel
{
    $panel = Panels::register($id, fn (Panel $panel): Panel => $configure($panel->starterKit()));

    StarterKitNavigation::register(alfakit());

    return $panel;
}

beforeEach(function (): void {
    Route::middleware('web')->get('/login', fn () => 'login')->name('login');
    Route::middleware(['web', 'auth'])->get('/dashboard', fn () => Inertia::render('dashboard'))->name('dashboard');
    app('router')->getRoutes()->refreshNameLookups();
});

it('shares what the header components render before they load on host pages', function (): void {
    widgetsPanel('kitw', fn (Panel $panel): Panel => $panel->resources([PostResource::class])->databaseNotifications());
    $user = User::query()->create(['name' => 'Ann', 'email' => 'ann'.uniqid().'@example.com', 'password' => 'secret']);
    Notification::make()->title('Welcome')->sendToDatabase($user);

    $this->actingAs($user);

    $widgets = inertiaProps(inertiaGet($this, '/dashboard'))['alfakitNavigation'][0]['widgets'];

    expect($widgets['endpoint'])->toBe('http://localhost/kitw/_alfakit/starter-kit')
        ->and($widgets['search'])->toBe(['keyBindings' => ['mod+k'], 'placeholder' => __('alfakit::panel.global_search.placeholder')])
        ->and($widgets['notifications'])->toBe(['label' => __('alfakit::ui.notifications'), 'unreadCount' => 1])
        // Only tenancy panels have a tenant switcher.
        ->and($widgets)->not->toHaveKey('tenancy');
});

it('shares plugins\' header component data (starterKitWidget) without overriding the built-in ones', function (): void {
    widgetsPanel('kitp', fn (Panel $panel): Panel => $panel->resources([PostResource::class])
        ->starterKitWidget('copilot', fn ($user): array => ['label' => 'Open AI copilot for '.$user->name, 'keyBindings' => ['mod+j']])
        ->starterKitWidget('hidden', fn (): ?array => null)
        ->starterKitWidget('search', fn (): array => ['keyBindings' => []]));

    $this->actingAs(User::query()->create(['name' => 'Ann', 'email' => 'ann'.uniqid().'@example.com', 'password' => 'secret']));

    $widgets = inertiaProps(inertiaGet($this, '/dashboard'))['alfakitNavigation'][0]['widgets'];

    expect($widgets['copilot'])->toBe(['label' => 'Open AI copilot for Ann', 'keyBindings' => ['mod+j']])
        ->and($widgets)->not->toHaveKey('hidden')
        ->and($widgets['search']['keyBindings'])->toBe(['mod+k']);
});

it('leaves out the bell without database notifications', function (): void {
    widgetsPanel('kitx', fn (Panel $panel): Panel => $panel->resources([PostResource::class]));

    $this->actingAs(User::query()->create(['name' => 'Ann', 'email' => 'ann'.uniqid().'@example.com', 'password' => 'secret']));

    expect(inertiaProps(inertiaGet($this, '/dashboard'))['alfakitNavigation'][0]['widgets'])->not->toHaveKey('notifications');
});

it('shares the current language of the language switcher with two or more languages', function (): void {
    widgetsPanel('kitl', fn (Panel $panel): Panel => $panel->resources([PostResource::class])->locales(['en', 'fr', 'ar']));
    widgetsPanel('kitm', fn (Panel $panel): Panel => $panel->resources([PostResource::class])->locales(['en']));
    widgetsPanel('kitn', fn (Panel $panel): Panel => $panel->resources([PostResource::class])->locales(['en', 'fr'])->localeSwitcher(false));

    $this->actingAs(User::query()->create(['name' => 'Ann', 'email' => 'ann'.uniqid().'@example.com', 'password' => 'secret']));

    $navigation = collect(inertiaProps(inertiaGet($this, '/dashboard'))['alfakitNavigation'])->keyBy('id');

    expect($navigation['kitl']['widgets']['language'])->toBe([
        'label' => __('alfakit::ui.change_language'),
        'current' => ['code' => 'en', 'tag' => 'en', 'label' => 'English', 'direction' => 'ltr'],
    ])
        // One language, or `localeSwitcher(false)`: `<AlfakitLanguageSwitcher />` renders nothing.
        ->and($navigation['kitm']['widgets'])->not->toHaveKey('language')
        ->and($navigation['kitn']['widgets'])->not->toHaveKey('language');

    // The switch goes back to the host page, which then names the new language; so does the
    // panel data the header components load again (in it), and the host keeps its own.
    $this->from('/dashboard')->post('/kitl/_alfakit/locale', ['locale' => 'ar'])->assertRedirect('/dashboard');

    $response = inertiaGet($this, '/dashboard');
    $navigation = collect(inertiaProps($response)['alfakitNavigation'])->keyBy('id');

    expect($navigation['kitl']['widgets']['language']['current'])->toMatchArray(['code' => 'ar', 'direction' => 'rtl'])
        ->and($this->getJson('/kitl/_alfakit/starter-kit')->json('localization'))->toMatchArray(['code' => 'ar', 'direction' => 'rtl'])
        ->and(app()->getLocale())->toBe('en');
});

it('serves the panel data of the header components to signed-in users only', function (): void {
    widgetsPanel('kity', fn (Panel $panel): Panel => $panel->resources([PostResource::class])->databaseNotifications());

    $this->getJson('/kity/_alfakit/starter-kit')->assertUnauthorized();

    $this->actingAs(User::query()->create(['name' => 'Ann', 'email' => 'ann'.uniqid().'@example.com', 'password' => 'secret']));

    $data = $this->getJson('/kity/_alfakit/starter-kit')->assertOk()->assertHeader('Cache-Control', 'no-store, private')->json();

    expect($data['id'])->toBe('kity')
        ->and($data['starterKit'])->toBeTrue()
        ->and($data['endpoints']['globalSearch'])->toBe('http://localhost/kity/_alfakit/global-search')
        ->and($data['endpoints']['notifications'])->toBe('http://localhost/kity/_alfakit/notifications')
        ->and($data['databaseNotifications']['unreadCount'])->toBe(0)
        ->and($data['translations'])->toHaveKey('notifications')
        ->and($data['localization'])->toHaveKey('tag')
        ->and($data)->toHaveKey('csrf');
});

it('answers 404 for panels that are not in starter kit mode', function (): void {
    $this->actingAs(User::query()->create(['name' => 'Ann', 'email' => 'ann'.uniqid().'@example.com', 'password' => 'secret']));

    $this->getJson('/admin/_alfakit/starter-kit')->assertNotFound();
});

it('offers the tenant switcher of tenancy panels on host pages', function (): void {
    widgetsPanel('kitt', fn (Panel $panel): Panel => $panel->tenant(Team::class, slugAttribute: 'slug')->resources([ProjectResource::class]));
    $user = TenantUser::query()->create(['name' => 'Ada', 'email' => 'ada'.uniqid().'@example.com', 'password' => 'secret']);
    $alpha = Team::query()->create(['name' => 'Alpha', 'slug' => 'alpha']);
    $user->teams()->attach($alpha->id);

    $this->actingAs($user);

    $navigation = inertiaProps(inertiaGet($this, '/dashboard'))['alfakitNavigation'][0];

    // Outside a tenant: no navigation (tenant routes), no search (tenant-scoped).
    expect($navigation['groups'])->toBe([])
        ->and($navigation['widgets'])->not->toHaveKey('search')
        ->and($navigation['widgets']['endpoint'])->toBe('http://localhost/kitt/_alfakit/starter-kit')
        ->and($navigation['widgets']['tenancy'])->not->toHaveKey('current')
        ->and($navigation['widgets']['tenancy']['tenants'])->toHaveCount(1)
        ->and($navigation['widgets']['tenancy']['tenants'][0])->toMatchArray(['name' => 'Alpha', 'url' => 'http://localhost/kitt/alpha/projects']);
});

it('leaves multi-factor authentication to the host while it signs users in', function (): void {
    $panel = widgetsPanel('kitm', fn (Panel $panel): Panel => $panel->multiFactorAuthentication([AppAuthentication::make()], isRequired: true));

    expect($panel->hasMultiFactorAuthentication())->toBeFalse()
        ->and($panel->isMultiFactorAuthenticationRequired())->toBeFalse();

    $this->actingAs(User::query()->create(['name' => 'Ann', 'email' => 'ann'.uniqid().'@example.com', 'password' => 'secret']));

    // Required MFA would otherwise refuse every page: the profile page it sets up on is off.
    expect($this->get('/kitm')->status())->not->toBe(403);

    // The panel's own login brings Alfakit's challenge back.
    expect(Panel::make('own')->starterKit()->login()->multiFactorAuthentication([AppAuthentication::make()])->hasMultiFactorAuthentication())->toBeTrue();
});
