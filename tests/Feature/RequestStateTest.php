<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Http\Middleware\SetPanelLocale;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Post;
use AlfaSix\Alfakit\Tests\Fixtures\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Number;
use Symfony\Component\HttpFoundation\Response;

afterEach(function (): void {
    alfakit()->getPanel('admin')->darkMode()->locales([])->defaultLocale(null)->detectBrowserLocale()->currency(null);
    Number::useLocale('en');
    Number::useCurrency('USD');
});

function requestStateUser(): User
{
    return User::query()->create(['name' => 'State', 'email' => 'state@example.com', 'password' => 'password']);
}

// ---------------------------------------------------------------- Dark mode (B13)

it('renders the stored dark scheme on the server', function (): void {
    $this->withUnencryptedCookie('alfakit_appearance', 'dark')
        ->get('/admin/login')
        ->assertOk()
        ->assertSee('class="dark"', false)
        ->assertSee('const appearance = "dark"', false);
});

it('keeps the cookie readable through the encrypted-cookie middleware', function (): void {
    $this->withCredentials()->call('GET', '/admin/login', [], ['alfakit_appearance' => 'dark'])
        ->assertSee('class="dark"', false);
});

it('ignores unknown schemes and follows the panel dark mode configuration', function (): void {
    $this->withUnencryptedCookie('alfakit_appearance', '"><script>')->get('/admin/login')
        ->assertDontSee('class="dark"', false)
        ->assertSee('const appearance = "system"', false);

    alfakit()->getPanel('admin')->darkMode(false);
    $this->withUnencryptedCookie('alfakit_appearance', 'dark')->get('/admin/login')
        ->assertDontSee('class="dark"', false);

    alfakit()->getPanel('admin')->darkMode(true, isForced: true);
    $this->withUnencryptedCookie('alfakit_appearance', 'light')->get('/admin/login')
        ->assertSee('class="dark"', false);
});

// ---------------------------------------------------------------- Inline edits (B15)

it('does not inline-edit soft-deleted records', function (): void {
    $user = requestStateUser();
    $post = Post::query()->create(['title' => 'Hello', 'slug' => 'hello', 'author_id' => $user->id]);
    $token = inertiaProps(inertiaGet($this->actingAs($user), '/admin/posts'))['page']['token'];
    $post->delete();

    $this->postJson('/admin/_alfakit/table/cell', ['token' => $token, 'component' => 'table', 'record' => $post->id, 'column' => 'is_published', 'state' => true])
        ->assertNotFound();

    expect(Post::withTrashed()->find($post->id)?->is_published)->toBeFalsy();
});

it('rejects array cell values without a server error', function (): void {
    $user = requestStateUser();
    $post = Post::query()->create(['title' => 'Hello', 'slug' => 'hello', 'author_id' => $user->id]);
    $token = inertiaProps(inertiaGet($this->actingAs($user), '/admin/posts'))['page']['token'];

    $this->postJson('/admin/_alfakit/table/cell', ['token' => $token, 'component' => 'table', 'record' => $post->id, 'column' => 'is_published', 'state' => ['x']])
        ->assertUnprocessable();
    $this->postJson('/admin/_alfakit/table/cell', ['token' => $token, 'component' => 'table', 'record' => [$post->id], 'column' => 'is_published', 'state' => true])
        ->assertNotFound();
});

// ---------------------------------------------------------------- Locale state (B16)

it('restores locale, currency and Carbon settings when the request terminates', function (): void {
    alfakit()->getPanel('admin')->locales(['en', 'ar'])->defaultLocale('ar')->detectBrowserLocale(false)->currency('EUR');
    alfakit()->setCurrentPanel(alfakit()->getPanel('admin'));

    $middleware = app(SetPanelLocale::class);
    $request = Request::create('/admin/login');
    $during = [];

    $response = $middleware->handle($request, function () use (&$during): Response {
        $during = [app()->getLocale(), Carbon::getLocale(), Number::defaultLocale(), Number::defaultCurrency()];

        return new Response('ok');
    });

    expect($during)->toBe(['ar', 'ar', 'ar', 'EUR']);

    $middleware->terminate($request, $response);

    expect([app()->getLocale(), Carbon::getLocale(), Number::defaultLocale(), Number::defaultCurrency()])
        ->toBe(['en', 'en', 'en', 'USD']);
});

it('applies defaultThemeMode() and themeSwitcher(false) on the first server render', function (): void {
    $panel = alfakit()->getPanel('admin');
    $panel->defaultThemeMode(AlfaSix\Alfakit\Theming\ThemeMode::Dark);

    // No stored choice: the default mode is rendered.
    $this->get('/admin/login')->assertSee('class="dark"', false)->assertSee('const appearance = "dark"', false);

    // A stored choice wins while the switcher is shown…
    $this->withUnencryptedCookie('alfakit_appearance', 'light')->get('/admin/login')->assertDontSee('class="dark"', false);

    // …and is ignored without the switcher.
    $panel->themeSwitcher(false);
    $this->withUnencryptedCookie('alfakit_appearance', 'light')->get('/admin/login')->assertSee('class="dark"', false);

    $panel->themeSwitcher()->defaultThemeMode(AlfaSix\Alfakit\Theming\ThemeMode::System);
});
