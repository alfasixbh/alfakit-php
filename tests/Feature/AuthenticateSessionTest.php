<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Tests\Fixtures\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

/**
 * Sign in through the session (not `actingAs()`, which sets the user on the guard only), so
 * a later request finds the user the way a browser's next request does.
 */
function signInWithSession(string $email = 'ada@example.com'): User
{
    $user = User::query()->create(['name' => 'Ada', 'email' => $email, 'password' => Hash::make('password')]);

    test()->startSession();
    Auth::guard('web')->login($user);
    app('auth')->forgetGuards();

    return $user;
}

function changePasswordElsewhere(User $user): void
{
    User::query()->whereKey($user->getKey())->update(['password' => Hash::make('another-password')]);
    app('auth')->forgetGuards();
}

it('keeps a session signed in while the password stays the same', function (): void {
    signInWithSession();

    $this->get('/admin')->assertOk();
    app('auth')->forgetGuards();
    $this->get('/admin')->assertOk();
});

it('signs a session out once the password it signed in with has changed', function (): void {
    $user = signInWithSession();

    $this->get('/admin')->assertOk();

    // Changed from another session ("log out other sessions", a new password).
    changePasswordElsewhere($user);

    $this->get('/admin')->assertRedirect('/admin/login');
    $this->assertGuest('web');
});

it('refuses the internal endpoints to a session whose password has changed', function (): void {
    $user = signInWithSession();
    $token = inertiaProps(inertiaGet($this, '/admin/posts'))['page']['token'];
    $request = ['token' => $token, 'action' => 'create', 'data' => ['title' => 'Nope']];

    app('auth')->forgetGuards();
    expect($this->postJson('/admin/_alfakit/actions', $request)->status())->not->toBe(401);

    changePasswordElsewhere($user);

    $this->postJson('/admin/_alfakit/actions', $request)->assertUnauthorized();
    $this->assertGuest('web');
});

it('starts afresh when another user signs in on the same session', function (): void {
    signInWithSession();
    $this->get('/admin')->assertOk();

    $grace = User::query()->create(['name' => 'Grace', 'email' => 'grace@example.com', 'password' => Hash::make('other')]);
    Auth::guard('web')->login($grace);
    app('auth')->forgetGuards();

    $this->get('/admin')->assertOk();
    app('auth')->forgetGuards();
    $this->get('/admin')->assertOk();
});

it('keeps the session that changes the password signed in, wherever it does so', function (): void {
    // A page of the application outside the panel (a starter kit's settings page).
    Illuminate\Support\Facades\Route::middleware('web')->post('/host/password', function (): string {
        User::query()->findOrFail(Auth::guard('web')->id())->update(['password' => Hash::make('a-new-password')]);

        return 'ok';
    });

    signInWithSession();
    $this->get('/admin')->assertOk();

    app('auth')->forgetGuards();
    $this->post('/host/password')->assertOk();

    app('auth')->forgetGuards();
    $this->get('/admin')->assertOk();
    $this->assertAuthenticated('web');
});

it('keeps the session signed in when a panel request saves the password on another instance', function (): void {
    // A page inside the panel's middleware (a users resource saving the admin's own row).
    Illuminate\Support\Facades\Route::middleware(['web', AlfaSix\Alfakit\Http\Middleware\AuthenticateSession::class.':admin'])->post('/inside/password', function (): string {
        User::query()->findOrFail(Auth::guard('web')->id())->update(['password' => Hash::make('a-new-password')]);

        return 'ok';
    });

    signInWithSession();
    $this->get('/admin')->assertOk();

    app('auth')->forgetGuards();
    $this->post('/inside/password')->assertOk();

    app('auth')->forgetGuards();
    $this->get('/admin')->assertOk();
});
