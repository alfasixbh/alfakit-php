<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Localization\LocaleResolver;
use AlfaSix\Alfakit\Panel;
use AlfaSix\Alfakit\Support\StarterKitDocument;
use AlfaSix\Alfakit\Support\StarterKitNavigation;
use AlfaSix\Alfakit\Tests\Fixtures\AuthTenancy\Panels;
use AlfaSix\Alfakit\Tests\Fixtures\Models\User;
use AlfaSix\Alfakit\Tests\Fixtures\Panel\Resources\PostResource;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

/*
 * Starter kit mode (`Panel::starterKit()`): the panel runs inside a Laravel starter kit app,
 * with the host's authentication, root view, layout and theme.
 */

function starterKitPanel(?Closure $configure = null): Panel
{
    $panel = Panels::register('kit', fn (Panel $panel): Panel => ($configure ?? fn (Panel $panel): Panel => $panel)(
        $panel->starterKit()->resources([PostResource::class]),
    ));

    StarterKitNavigation::register(alfakit());

    return $panel;
}

function starterKitUser(): User
{
    return User::query()->create(['name' => 'Ann', 'email' => 'ann'.uniqid().'@example.com', 'password' => 'secret']);
}

beforeEach(function (): void {
    // The host application's own pages and Fortify's login route.
    Route::middleware('web')->get('/login', fn () => 'login')->name('login');
    Route::middleware(['web', 'auth'])->get('/dashboard', fn () => Inertia::render('dashboard'))->name('dashboard');
    app('router')->getRoutes()->refreshNameLookups();
});

it('turns off Alfakit authentication pages and sends guests to the host login route', function (): void {
    $panel = starterKitPanel(fn (Panel $panel): Panel => $panel->login()->registration()->passwordReset()->profile()->starterKit());

    expect($panel->isStarterKit())->toBeTrue()
        ->and($panel->hasLogin())->toBeFalse()
        ->and($panel->hasRegistration())->toBeFalse()
        ->and($panel->hasPasswordReset())->toBeFalse()
        ->and($panel->hasProfile())->toBeFalse()
        ->and(Route::has('alfakit.kit.auth.login'))->toBeFalse()
        ->and(Route::has('alfakit.kit.auth.register'))->toBeFalse()
        ->and(Route::has('alfakit.kit.auth.profile'))->toBeFalse();

    $this->get('/kit/posts')->assertRedirect('http://localhost/login');
});

it('renders panel pages through the host root view without the panel navigation', function (): void {
    starterKitPanel();

    $this->actingAs(starterKitUser());

    $response = inertiaGet($this, '/kit/posts');
    $props = inertiaProps($response);

    expect($props['alfakit']['starterKit'])->toBeTrue()
        ->and($props['alfakit']['navigation'])->toBe([])
        ->and($props['alfakit']['auth']['login'])->toBe('http://localhost/login')
        ->and($props['alfakitNavigation'])->toHaveCount(1)
        ->and($props['alfakitNavigation'][0]['id'])->toBe('kit')
        ->and($props['alfakitNavigation'][0]['url'])->toBe('http://localhost/kit/posts');

    $items = collect($props['alfakitNavigation'][0]['groups'])->flatMap(fn (array $group): array => $group['items']);

    expect($items->pluck('url')->all())->toContain('http://localhost/kit/posts')
        ->and($items->firstWhere('url', 'http://localhost/kit/posts')['active'] ?? false)->toBeTrue();

    // A full page visit renders the host's root view (`app`), not `alfakit::app`.
    $this->app['view']->addLocation(__DIR__.'/views');
    $this->get('/kit/posts')->assertOk()->assertSee('host-root-view', false);
});

it('shares the panel navigation with the host application pages', function (): void {
    starterKitPanel();

    // Guests get none.
    expect(StarterKitNavigation::resolve(alfakit()))->toBeNull();

    $this->actingAs(starterKitUser());

    $props = inertiaProps(inertiaGet($this, '/dashboard'));
    $items = collect($props['alfakitNavigation'][0]['groups'])->flatMap(fn (array $group): array => $group['items']);

    expect($items->pluck('url')->all())->toContain('http://localhost/kit/posts')
        ->and($items->every(fn (array $item): bool => ! ($item['active'] ?? false)))->toBeTrue()
        // Building the navigation does not leave the panel set for the rest of the request.
        ->and(alfakit()->getCurrentPanel())->toBeNull();
});

it('shares the panel user menu links with the host pages, for <AlfakitUserMenuItems />', function (): void {
    starterKitPanel(fn (Panel $panel): Panel => $panel->userMenuItems([
        [AlfaSix\Alfakit\Actions\Action::make('billing')->label('Billing')->icon('credit-card')->url('/billing')],
        [AlfaSix\Alfakit\Actions\Action::make('docs')->label('Docs')->url('https://example.com/docs', shouldOpenInNewTab: true)],
        // Server logic needs the panel's action endpoint: not offered on the host's pages.
        AlfaSix\Alfakit\Actions\Action::make('ping')->label('Ping')->action(fn () => null),
        AlfaSix\Alfakit\Actions\Action::make('secret')->label('Secret')->url('/secret')->hidden(),
    ]));

    $this->actingAs(starterKitUser());

    $menu = inertiaProps(inertiaGet($this, '/dashboard'))['alfakitNavigation'][0]['userMenu'];

    expect(array_column($menu, 'label'))->toBe(['Billing', 'Docs'])
        ->and($menu[0])->toMatchArray(['icon' => 'credit-card', 'url' => '/billing'])
        ->and($menu[1]['openInNewTab'])->toBeTrue()
        ->and($menu[1]['group'])->toBe(1);
});

it('leaves the user menu out of the shared navigation when the panel has no entries', function (): void {
    starterKitPanel();
    $this->actingAs(starterKitUser());

    expect(inertiaProps(inertiaGet($this, '/dashboard'))['alfakitNavigation'][0])->not->toHaveKey('userMenu');
});

it('shares the document language and direction with every page, so a right-to-left panel language flips the whole page', function (): void {
    starterKitPanel(fn (Panel $panel): Panel => $panel->locales(['en', 'ar']));
    Route::middleware('web')->get('/welcome', fn () => Inertia::render('welcome'));
    app('router')->getRoutes()->refreshNameLookups();

    // Guests too (the kit's login page), in the panel's default language.
    expect(inertiaProps(inertiaGet($this, '/welcome'))['alfakitDocument'])->toBe(['tag' => 'en', 'direction' => 'ltr'])
        ->and(StarterKitDocument::direction())->toBe('ltr');

    $this->actingAs(starterKitUser());
    $this->withSession([LocaleResolver::SESSION_KEY => 'ar']);

    // The app's own page keeps its language (`lang`) and takes the panel language's direction.
    expect(inertiaProps(inertiaGet($this, '/dashboard'))['alfakitDocument'])->toBe(['tag' => 'en', 'direction' => 'rtl'])
        // AlfaKit's pages are in the panel language.
        ->and(inertiaProps(inertiaGet($this, '/kit/posts'))['alfakitDocument'])->toBe(['tag' => 'ar', 'direction' => 'rtl'])
        // The kit's root view (`<html dir="{{ StarterKitDocument::direction() }}">`) on app pages.
        ->and(StarterKitDocument::direction())->toBe('rtl');

    $this->withSession([LocaleResolver::SESSION_KEY => 'en']);

    expect(inertiaProps(inertiaGet($this, '/dashboard'))['alfakitDocument'])->toBe(['tag' => 'en', 'direction' => 'ltr']);
});

it('renders <html dir> in the starter kit root view from the panel language', function (): void {
    starterKitPanel(fn (Panel $panel): Panel => $panel->locales(['en', 'ar']));
    $this->app['view']->addLocation(__DIR__.'/views/document');

    $this->actingAs(starterKitUser());
    $this->withSession([LocaleResolver::SESSION_KEY => 'ar']);

    $this->get('/kit/posts')->assertOk()->assertSee('<html lang="ar" dir="rtl">', false);
    $this->get('/dashboard')->assertOk()->assertSee('<html lang="en" dir="rtl">', false);
});

/**
 * The root view's `<html dir>` and the page's `alfakitDocument` from one full page load.
 *
 * @return array{0: string|null, 1: array<string, mixed>|null}
 */
function starterKitGuestDocument(AlfaSix\Alfakit\Tests\TestCase $test, string $url, string $acceptLanguage): array
{
    $html = (string) $test->get($url, ['Accept-Language' => $acceptLanguage])->assertOk()->getContent();

    preg_match('/<html[^>]* dir="([^"]*)"/', $html, $dir);
    preg_match('/data-page="([^"]*)"|<script data-page="app" type="application\/json">(.*?)<\/script>/s', $html, $page);
    $json = ($page[1] ?? '') !== '' ? html_entity_decode($page[1]) : ($page[2] ?? 'null');

    return [$dir[1] ?? null, json_decode($json, true)['props'][StarterKitDocument::PROP] ?? null];
}

it('gives a guest the same direction in the root view and the shared prop, following the panel locale rules', function (bool $detectBrowser, string $direction): void {
    starterKitPanel(fn (Panel $panel): Panel => $panel->locales(['en', 'ar'])->defaultLocale('ar')->detectBrowserLocale($detectBrowser));
    $this->app['view']->addLocation(__DIR__.'/views/document');
    Route::middleware('web')->get('/kit-login', fn () => Inertia::render('auth/login'));
    app('router')->getRoutes()->refreshNameLookups();

    // A guest whose browser asks for English (the kit's login page).
    [$rootDir, $document] = starterKitGuestDocument($this, '/kit-login', 'en-US,en;q=0.9');

    expect($rootDir)->toBe($direction)
        ->and($document['direction'] ?? null)->toBe($direction);
})->with([
    // `detectBrowserLocale(false)`: the browser never overrides `defaultLocale('ar')`.
    'browser detection off' => [false, 'rtl'],
    'browser detection on' => [true, 'ltr'],
]);

it('resolves the guest document direction once per request, so the root view and the prop cannot disagree', function (): void {
    $calls = 0;
    // A resolver whose answer changes between calls stands in for anything that changes the
    // language mid-request (session, cookie, middleware order).
    starterKitPanel(function (Panel $panel) use (&$calls): Panel {
        return $panel->locales(['en', 'ar'])->resolveLocaleUsing(function () use (&$calls): string {
            return ++$calls === 1 ? 'ar' : 'en';
        });
    });
    $this->app['view']->addLocation(__DIR__.'/views/document');
    Route::middleware('web')->get('/kit-login', fn () => Inertia::render('auth/login'));
    app('router')->getRoutes()->refreshNameLookups();

    [$rootDir, $document] = starterKitGuestDocument($this, '/kit-login', 'en');

    expect($rootDir)->toBe('rtl')
        ->and($document['direction'] ?? null)->toBe('rtl')
        ->and($calls)->toBe(1);
});

it('does not share starter kit navigation on pages of other panels', function (): void {
    starterKitPanel();

    $this->actingAs(starterKitUser());

    $props = inertiaProps(inertiaGet($this, '/admin/posts'));

    expect($props['alfakitNavigation'] ?? null)->toBeNull()
        // That panel sets `<html lang dir>` itself.
        ->and($props['alfakitDocument'] ?? null)->toBeNull()
        ->and($props['alfakit'])->not->toHaveKey('starterKit')
        ->and($props['alfakit']['navigation'])->not->toBe([]);
});

it('shares starter kit navigation on host pages that render a standalone component or the auth kit', function (): void {
    starterKitPanel();
    AlfaSix\Alfakit\AuthKit\AuthKit::configure();
    Route::middleware('web')->group(fn () => AlfaSix\Alfakit\AuthKit\AuthKit::routes());
    Route::middleware(['web', 'auth'])->get('/contact', fn () => Inertia::render('Contact', ['form' => AlfaSix\Alfakit\Tests\Fixtures\Standalone\ContactForm::make()]));
    app('router')->getRoutes()->refreshNameLookups();
    app('router')->getRoutes()->refreshActionLookups();

    $this->actingAs(starterKitUser());

    // The hidden standalone and auth-kit panels render no sidebar of their own (W-S3-11).
    foreach (['/contact', '/account'] as $url) {
        $props = inertiaProps(inertiaGet($this, $url));

        expect($props['alfakitNavigation'] ?? null)->toHaveCount(1)
            ->and($props['alfakitNavigation'][0]['id'])->toBe('kit');
    }
});

it('offers embedded() as an alias', function (): void {
    $panel = Panel::make('x')->embedded(rootView: 'host', loginRoute: 'sign-in');

    expect($panel->isStarterKit())->toBeTrue()
        ->and($panel->getStarterKitRootView())->toBe('host')
        ->and($panel->getStarterKitLoginRoute())->toBe('sign-in')
        ->and(Panel::make('y')->isStarterKit())->toBeFalse();
});

it('sends "G then D" to the app dashboard when the starter kit panel has no dashboard page', function (): void {
    $panel = starterKitPanel();

    expect($panel->getShortcutDashboardUrl())->toBe(url('/dashboard'))
        ->and($panel->toSchema()['dashboardUrl'] ?? null)->toBe(url('/dashboard'));

    // A regular panel keeps its own home.
    $admin = Panels::register('plain', fn (Panel $panel): Panel => $panel->resources([PostResource::class]));

    expect($admin->getShortcutDashboardUrl())->toBeNull();

    // A Dashboard page registered for a customisable layout doesn't take over (W-S1-22).
    $panel->pages([AlfaSix\Alfakit\Pages\Dashboard::class]);
    expect($panel->getShortcutDashboardUrl())->toBe(url('/dashboard'));

    // Without widgets on the app's pages, the panel's own dashboard is the home.
    $panel->starterKitDashboard(false);
    expect($panel->getShortcutDashboardUrl())->toBeNull();
});

it('renders a pwa() panel\'s head tags in the host root view with @alfakitPwa', function (): void {
    starterKitPanel(fn (Panel $panel): Panel => $panel->pwa());

    $html = Illuminate\Support\Facades\Blade::render('@alfakitPwa');

    expect($html)->toContain('rel="manifest"')->toContain('manifest.webmanifest')->toContain('name="theme-color"')
        ->and(Illuminate\Support\Facades\Blade::render("@alfakitPwa('kit')"))->toBe($html)
        ->and(Illuminate\Support\Facades\Blade::render("@alfakitPwa('missing')"))->toBe('');
});
