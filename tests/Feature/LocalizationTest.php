<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Localization\Locale;
use AlfaSix\Alfakit\Support\Enums\LanguageSwitcherPosition;
use AlfaSix\Alfakit\Tests\Fixtures\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Number;

beforeEach(function (): void {
    alfakit()->getPanel('admin')
        ->locales(['en', 'ar', Locale::make('fr')->label('Français (FR)')])
        ->defaultLocale('en')
        ->currency('EUR')
        ->timezone('Asia/Bahrain')
        ->persistLocaleUsing(null)
        ->resolveLocaleUsing(null)
        ->detectBrowserLocale();
});

afterEach(function (): void {
    alfakit()->getPanel('admin')->locales([])->currency(null)->timezone(null)->persistLocaleUsing(null)
        ->languageSwitcher()->topbar();
    Number::useLocale('en');
    Number::useCurrency('USD');
});

it('shares the resolved locale, direction and switcher with the frontend', function (): void {
    $response = inertiaGet($this, '/admin/login');
    $localization = inertiaProps($response)['alfakit']['localization'];

    expect($localization)->toMatchArray([
        'code' => 'en', 'tag' => 'en', 'direction' => 'ltr', 'currency' => 'EUR',
        'timezone' => 'Asia/Bahrain', 'switcher' => true, 'firstDayOfWeek' => 0,
    ])->and(array_column($localization['locales'], 'label', 'code'))->toBe([
        'en' => 'English', 'ar' => 'العربية', 'fr' => 'Français (FR)',
    ])->and(inertiaProps($response)['alfakit']['endpoints']['locale'])->toBe('http://localhost/admin/_alfakit/locale');

    $response->assertHeader('Content-Language', 'en');
});

it('places the language switcher in the user menu by default', function (): void {
    $localization = inertiaProps(inertiaGet($this, '/admin/login'))['alfakit']['localization'];

    expect($localization['switcherPosition'])->toBe('user-menu')
        ->and(alfakit()->getPanel('admin')->getLanguageSwitcherPosition())->toBe(LanguageSwitcherPosition::UserMenu);
});

it('moves the language switcher to the top bar, both places or hides it', function (string|LanguageSwitcherPosition $position, string $expected): void {
    alfakit()->getPanel('admin')->languageSwitcher($position);

    $localization = inertiaProps(inertiaGet($this, '/admin/login'))['alfakit']['localization'];

    // The language list stays available for the auth pages whatever the position.
    expect($localization['switcherPosition'])->toBe($expected)
        ->and($localization['switcher'])->toBeTrue()
        ->and($localization['locales'])->toHaveCount(3);
})->with([
    ['topbar', 'topbar'],
    [LanguageSwitcherPosition::Both, 'both'],
    ['hidden', 'hidden'],
    [LanguageSwitcherPosition::UserMenu, 'user-menu'],
]);

it('labels each language in its own language for the switcher', function (): void {
    alfakit()->getPanel('admin')->locales(['en', 'ar', 'ja', 'fa', 'zh_CN'])->languageSwitcher('topbar');

    $locales = inertiaProps(inertiaGet($this, '/admin/login'))['alfakit']['localization']['locales'];

    expect(array_column($locales, 'label', 'code'))->toMatchArray(['ar' => 'العربية', 'ja' => '日本語', 'fa' => 'فارسی'])
        ->and(array_column($locales, 'direction', 'code'))->toMatchArray(['ar' => 'rtl', 'fa' => 'rtl', 'ja' => 'ltr'])
        ->and(array_column($locales, 'tag', 'code'))->toMatchArray(['zh_CN' => 'zh-CN']);
});

it('keeps a user menu entry when a top bar switcher has no top bar', function (): void {
    alfakit()->getPanel('admin')->languageSwitcher('topbar')->topbar(false);

    $localization = inertiaProps(inertiaGet($this, '/admin/login'))['alfakit']['localization'];

    expect($localization['switcherPosition'])->toBe('both');
});

it('rejects unknown language switcher positions', function (): void {
    alfakit()->getPanel('admin')->languageSwitcher('sidebar');
})->throws(ValueError::class);

it('uses the browser language and renders RTL documents', function (): void {
    $this->get('/admin/login', ['Accept-Language' => 'ar-BH,ar;q=0.9,en;q=0.5'])
        ->assertOk()
        ->assertSee('lang="ar"', false)
        ->assertSee('dir="rtl"', false)
        ->assertHeader('Content-Language', 'ar');

    // The locale only applies to the panel request: it is restored once the request
    // terminates so it cannot leak into the next request of a long-lived worker.
    expect(app()->getLocale())->toBe('en')->and(Number::defaultLocale())->toBe('en');
});

it('falls back to the default locale for unsupported browser languages', function (): void {
    $this->get('/admin/login', ['Accept-Language' => 'ja-JP'])->assertSee('lang="en"', false)->assertSee('dir="ltr"', false);
});

it('can turn browser detection off', function (): void {
    alfakit()->getPanel('admin')->detectBrowserLocale(false);

    $this->get('/admin/login', ['Accept-Language' => 'fr'])->assertSee('lang="en"', false);
});

it('switches language for guests through the session and a cookie', function (): void {
    $this->from('/admin/login')
        ->post('/admin/_alfakit/locale', ['locale' => 'fr'])
        ->assertRedirect('/admin/login')
        ->assertSessionHas('alfakit.locale', 'fr')
        ->assertCookie('alfakit_locale', 'fr');

    $localization = inertiaProps(inertiaGet($this, '/admin/login'))['alfakit']['localization'];

    expect($localization['code'])->toBe('fr');
});

it('keeps the latest pick when a request running during the switch saved the old one back', function (): void {
    // The switch set the cookie to fr; a request that started before it ended later and
    // saved its session, which still says ar.
    $this->withSession(['alfakit.locale' => 'ar'])->withCookie('alfakit_locale', 'fr');

    expect(inertiaProps(inertiaGet($this, '/admin/login'))['alfakit']['localization']['code'])->toBe('fr');
});

it('falls back to the session pick when the cookie names a language the panel does not offer', function (): void {
    $this->withSession(['alfakit.locale' => 'ar'])->withCookie('alfakit_locale', 'de');

    expect(inertiaProps(inertiaGet($this, '/admin/login'))['alfakit']['localization']['code'])->toBe('ar');
});

it('rejects languages the panel does not offer', function (): void {
    $this->from('/admin/login')
        ->post('/admin/_alfakit/locale', ['locale' => 'de'])
        ->assertSessionHasErrors('locale');
});

it('reads and persists the signed-in user preference', function (): void {
    $user = User::query()->create(['name' => 'A', 'email' => 'a@example.com', 'password' => 'password', 'locale' => 'ar']);

    alfakit()->getPanel('admin')->persistLocaleUsing(static fn (User $user, string $locale) => $user->update(['locale' => $locale]));

    $this->actingAs($user);

    expect(inertiaProps(inertiaGet($this, '/admin'))['alfakit']['localization']['direction'])->toBe('rtl');

    $this->post('/admin/_alfakit/locale', ['locale' => 'fr'])->assertRedirect();

    expect($user->fresh()->locale)->toBe('fr');
});

it('lets a custom resolver decide first', function (): void {
    alfakit()->getPanel('admin')->resolveLocaleUsing(static fn ($request, array $locales): string => 'fr');

    $this->get('/admin/login', ['Accept-Language' => 'ar'])->assertSee('lang="fr"', false);
});

it('scaffolds and checks a new language', function (): void {
    $directory = lang_path('vendor/alfakit/sw');
    File::deleteDirectory($directory);

    try {
        $this->artisan('alfakit:lang:add', ['locale' => 'sw'])
            ->expectsOutputToContain("->locales([..., 'sw'])")
            ->assertSuccessful();

        expect(File::exists("{$directory}/ui.php"))->toBeTrue()
            ->and((require "{$directory}/ui.php")['save'])->toBe('Save');

        $this->artisan('alfakit:lang:check', ['locales' => ['sw']])->assertSuccessful();

        File::put("{$directory}/ui.php", "<?php return ['table' => ['showing' => 'Inaonyesha']];");

        $this->artisan('alfakit:lang:check', ['locales' => ['sw']])->assertFailed();
    } finally {
        File::deleteDirectory($directory);
    }
});

it('treats a blank currency setting as unset', function (): void {
    $panel = alfakit()->getPanel('admin');

    // `ALFAKIT_CURRENCY=` in .env is an empty string, not null.
    config(['alfakit.currency' => '']);
    Number::useCurrency('GBP');

    expect($panel->currency(null)->getCurrency())->toBe('GBP')
        ->and($panel->currency(fn (): string => '')->getCurrency())->toBe('GBP');

    config(['alfakit.currency' => 'jpy']);

    expect($panel->currency(fn (): ?string => null)->getCurrency())->toBe('JPY');
});
