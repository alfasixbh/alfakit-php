<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Auth\MultiFactor\App\AppAuthentication;
use AlfaSix\Alfakit\Auth\MultiFactor\App\Totp;
use AlfaSix\Alfakit\Auth\Notifications\RegistrationInvitation;
use AlfaSix\Alfakit\Auth\Notifications\ResetPassword;
use AlfaSix\Alfakit\AuthKit\Account\DeleteAccount;
use AlfaSix\Alfakit\AuthKit\Account\Profile;
use AlfaSix\Alfakit\AuthKit\AuthKit;
use AlfaSix\Alfakit\AuthKit\AuthKitPanel;
use AlfaSix\Alfakit\AuthKit\Notifications\VerifyEmail;
use AlfaSix\Alfakit\AuthKit\Pages\Login;
use AlfaSix\Alfakit\Support\SchemaContext;
use AlfaSix\Alfakit\Tests\Fixtures\Models\MfaUser;
use AlfaSix\Alfakit\Tests\Fixtures\Models\VerifiableUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;

/*
 * The app auth kit: AlfaKit's sign-in, registration, password reset, email verification,
 * two-step verification and account page for the app's own users, outside any panel.
 */

beforeEach(function (): void {
    config()->set('auth.providers.users.model', MfaUser::class);
    app('auth')->forgetGuards();
});

function authKit(?Closure $configure = null, ?string $prefix = null, ?string $as = null): AuthKitPanel
{
    $panel = AuthKit::configure($configure);

    Route::middleware('web')->group(fn () => AuthKit::routes($prefix, $as));
    Route::get('/dashboard', fn () => 'dashboard')->middleware('web')->name('dashboard');
    Route::getRoutes()->refreshNameLookups();
    Route::getRoutes()->refreshActionLookups();

    return $panel;
}

function kitUser(array $attributes = []): MfaUser
{
    return MfaUser::query()->create(['name' => 'Kit', 'email' => 'kit'.uniqid().'@example.com', 'password' => 'password', ...$attributes]);
}

function kitProps(mixed $test, string $url): array
{
    return inertiaProps(inertiaGet($test, $url));
}

function kitAction(mixed $test, string $token, string $action, array $data = [], array $extra = []): Illuminate\Testing\TestResponse
{
    return inertiaPost($test, '/_alfakit/auth/actions', ['token' => $token, 'action' => $action, 'data' => $data, ...$extra]);
}

// ---------------------------------------------------------------- Routes and payload

it('registers the pages under Laravel\'s conventional route names and its own endpoints', function (): void {
    authKit();

    foreach (['login', 'logout', 'register', 'password.request', 'password.reset', 'verification.notice', 'verification.verify', 'two-factor.login', 'account'] as $name) {
        expect(Route::has($name))->toBeTrue($name);
    }

    expect(route('login'))->toBe('http://localhost/login')
        ->and(route('account'))->toBe('http://localhost/account')
        ->and(Route::has('alfakit.auth-kit.internal.actions.execute'))->toBeTrue()
        ->and(route('alfakit.auth-kit.internal.actions.execute'))->toBe('http://localhost/_alfakit/auth/actions')
        ->and(array_keys(alfakit()->getPanels()))->not->toContain(AuthKitPanel::ID)
        ->and(alfakit()->getPanel(AuthKitPanel::ID))->toBeInstanceOf(AuthKitPanel::class);
});

it('renders the sign-in page as the app\'s guest Inertia page with a standalone component', function (): void {
    authKit();

    $response = inertiaGet($this, '/login');
    $props = inertiaProps($response);
    $component = $props['component'];

    expect($response->json('component'))->toBe('auth/alfakit')
        ->and($props['authKit']['page'])->toBe('login')
        ->and($props['authKit']['title'])->toBe('Sign in')
        ->and($component['alfakit'])->toBe('standalone')
        ->and($component['class'])->toBe(Login::class)
        ->and($component['heading'])->toBe('Sign in to your account')
        ->and($component['endpoints']['action'])->toBe('http://localhost/_alfakit/auth/actions')
        ->and(array_column($component['content'][0]['components'], 'name'))->toBe(['email', 'password', 'remember'])
        ->and($component['content'][1]['links'][0]['url'])->toBe('http://localhost/register')
        ->and(SchemaContext::fromToken($component['token'])->panel)->toBe(AuthKitPanel::ID);
});

it('honours a prefix, a route name prefix and custom Inertia pages', function (): void {
    authKit(fn (AuthKitPanel $kit) => $kit->inertiaPages('Auth/Kit', 'Settings/Kit'), prefix: 'kit', as: 'kit.');

    $response = inertiaGet($this, '/kit/login');

    expect($response->json('component'))->toBe('Auth/Kit')
        ->and(route('kit.login'))->toBe('http://localhost/kit/login')
        ->and(AuthKit::panel()->getRegistrationUrl())->toBe('http://localhost/kit/register')
        ->and($response->json('props.component.endpoints.action'))->toBe('http://localhost/kit/_alfakit/auth/actions');
});

// ---------------------------------------------------------------- Sign in

it('signs in with the right password, sends the user home and rate limits wrong ones', function (): void {
    authKit();
    $user = kitUser();
    $token = kitProps($this, '/login')['component']['token'];

    kitAction($this, $token, 'authenticate', ['email' => $user->email, 'password' => 'wrong'])
        ->assertJsonValidationErrors('email');

    $this->assertGuest();

    kitAction($this, $token, 'authenticate', ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect('http://localhost/dashboard');

    $this->assertAuthenticatedAs($user);

    // Guests only: a signed-in user is sent away from the sign-in page.
    $this->get('/login')->assertRedirect();
});

it('locks the sign-in after five wrong passwords', function (): void {
    // The message counts down the seconds left: a second ticking over mid-test read "59".
    $this->freezeTime();
    authKit();
    $user = kitUser();
    $token = kitProps($this, '/login')['component']['token'];

    foreach (range(1, 5) as $attempt) {
        kitAction($this, $token, 'authenticate', ['email' => $user->email, 'password' => 'wrong']);
    }

    kitAction($this, $token, 'authenticate', ['email' => $user->email, 'password' => 'password'])
        ->assertJsonValidationErrors(['email' => __('alfakit::auth.login.throttled', ['seconds' => 60])]);

    $this->assertGuest();
});

it('asks for the authenticator code after the password, with a one-time code input', function (): void {
    authKit(fn (AuthKitPanel $kit) => $kit->multiFactorAuthentication([AppAuthentication::make()->recoverable()]));
    $secret = Totp::generateSecret();
    $user = kitUser();
    $user->saveAppAuthenticationSecret($secret);

    kitAction($this, kitProps($this, '/login')['component']['token'], 'authenticate', ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect('http://localhost/two-factor-challenge');

    $this->assertGuest();

    $props = kitProps($this, '/two-factor-challenge');
    $code = $props['component']['content'][0]['components'][0];

    expect($props['authKit']['page'])->toBe('two-factor-challenge')
        ->and($code['type'])->toBe('OneTimeCodeInput');

    kitAction($this, $props['component']['token'], 'verify', ['code' => '000000'])->assertJsonValidationErrors('code');

    kitAction($this, $props['component']['token'], 'verify', ['code' => Totp::code($secret)])
        ->assertRedirect('http://localhost/dashboard');

    $this->assertAuthenticatedAs($user);
});

it('sends the challenge page back to sign-in without a pending sign-in', function (): void {
    authKit(fn (AuthKitPanel $kit) => $kit->multiFactorAuthentication([AppAuthentication::make()]));

    $this->get('/two-factor-challenge')->assertRedirect('http://localhost/login');
});

// ---------------------------------------------------------------- Registration

it('registers a user and signs them in', function (): void {
    authKit();
    $token = kitProps($this, '/register')['component']['token'];

    kitAction($this, $token, 'register', ['name' => 'New', 'email' => 'new@example.com', 'password' => 'secret-pass-1', 'password_confirmation' => 'nope'])
        ->assertJsonValidationErrors('password');

    kitAction($this, $token, 'register', ['name' => 'New', 'email' => 'new@example.com', 'password' => 'secret-pass-1', 'password_confirmation' => 'secret-pass-1'])
        ->assertRedirect('http://localhost/dashboard');

    $user = MfaUser::query()->where('email', 'new@example.com')->firstOrFail();

    expect(Hash::check('secret-pass-1', $user->password))->toBeTrue();
    $this->assertAuthenticatedAs($user);
});

it('only lets invited addresses register with invitation-only registration', function (): void {
    NotificationFacade::fake();
    $kit = authKit(fn (AuthKitPanel $kit) => $kit->invitationOnlyRegistration());

    // No invitation: back to sign-in, and no "Sign up" link there.
    $this->get('/register')->assertRedirect('http://localhost/login');
    expect(kitProps($this, '/login')['component']['content'])->toHaveCount(1);

    $url = $kit->inviteToRegister('Invited@Example.com');

    NotificationFacade::assertSentOnDemand(RegistrationInvitation::class, fn (RegistrationInvitation $notification): bool => $notification->url === $url);

    // A tampered link is refused.
    $this->get(str_replace('invited%40example.com', 'evil%40example.com', $url))->assertRedirect('http://localhost/login');

    $form = kitProps($this, $url)['component'];
    $email = collect($form['content'][0]['components'])->firstWhere('name', 'email');

    expect($form['content'][0]['state']['email'])->toBe('invited@example.com')
        ->and($email['readOnly'] ?? $email['isReadOnly'] ?? null)->toBeTrue();

    kitAction($this, $form['token'], 'register', ['name' => 'Ivy', 'email' => 'invited@example.com', 'password' => 'secret-pass-1', 'password_confirmation' => 'secret-pass-1'])
        ->assertRedirect('http://localhost/dashboard');

    expect(MfaUser::query()->where('email', 'invited@example.com')->exists())->toBeTrue();
});

// ---------------------------------------------------------------- Password reset

function kitPasswordResetTable(): void
{
    Schema::create('password_reset_tokens', function (Blueprint $table): void {
        $table->string('email')->primary();
        $table->string('token');
        $table->timestamp('created_at')->nullable();
    });
}

it('emails a reset link to the auth kit page and resets the password with it', function (): void {
    NotificationFacade::fake();
    kitPasswordResetTable();
    authKit();
    $user = kitUser();

    kitAction($this, kitProps($this, '/forgot-password')['component']['token'], 'request', ['email' => $user->email])
        ->assertRedirect();

    $resetUrl = null;
    NotificationFacade::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use (&$resetUrl, $user): bool {
        $resetUrl = $notification->toMail($user)->actionUrl;

        return true;
    });

    expect($resetUrl)->toStartWith('http://localhost/reset-password/');

    $form = kitProps($this, (string) $resetUrl)['component'];

    expect($form['content'][0]['state']['email'])->toBe($user->email)
        ->and(json_encode($form['content']))->not->toContain(basename((string) parse_url((string) $resetUrl, PHP_URL_PATH)));

    kitAction($this, $form['token'], 'reset', ['email' => $user->email, 'password' => 'brand-new-pass', 'password_confirmation' => 'brand-new-pass'])
        ->assertRedirect('http://localhost/login');

    expect(Hash::check('brand-new-pass', $user->fresh()->password))->toBeTrue();
});

it('throttles password reset requests per IP address', function (): void {
    NotificationFacade::fake();
    kitPasswordResetTable();
    authKit();
    $token = kitProps($this, '/forgot-password')['component']['token'];

    foreach (range(1, 3) as $attempt) {
        kitAction($this, $token, 'request', ['email' => "a{$attempt}@example.com"])->assertSessionHasNoErrors();
    }

    kitAction($this, $token, 'request', ['email' => 'a4@example.com'])->assertJsonValidationErrors('email');
});

// ---------------------------------------------------------------- Email verification

it('verifies the email address with the signed link of its own notification', function (): void {
    config()->set('auth.providers.users.model', VerifiableUser::class);
    app('auth')->forgetGuards();
    authKit();
    $user = VerifiableUser::query()->create(['name' => 'Vera', 'email' => 'vera@example.com', 'password' => 'password']);

    $this->actingAs($user);

    $url = (new class extends VerifyEmail
    {
        public function url(object $notifiable): string
        {
            return $this->verificationUrl($notifiable);
        }
    })->url($user);

    expect($url)->toStartWith('http://localhost/verify-email/'.$user->id.'/');

    // Unverified: the prompt page with "resend".
    expect(inertiaGet($this, '/verify-email')->json('props.authKit.page'))->toBe('verify-email');

    $this->get('/verify-email/'.$user->id.'/'.sha1('other@example.com'))->assertForbidden();
    $this->get($url)->assertRedirect('http://localhost/dashboard');

    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
    $this->get('/verify-email')->assertRedirect('http://localhost/dashboard');
});

it('takes over Fortify\'s verification link route instead of registering the name twice', function (): void {
    config()->set('auth.providers.users.model', VerifiableUser::class);
    app('auth')->forgetGuards();

    // Fortify registers this route even with `'views' => false`.
    Route::middleware('web')->get('/email/verify/{id}/{hash}', fn () => 'fortify')->name('verification.verify');

    authKit();
    $user = VerifiableUser::query()->create(['name' => 'Vera', 'email' => 'vera@example.com', 'password' => 'password']);
    $this->actingAs($user);

    $named = collect(Route::getRoutes()->getRoutes())->filter(fn ($route): bool => $route->getName() === 'verification.verify');

    expect($named)->toHaveCount(1)
        ->and($named->first()->uri())->toBe('email/verify/{id}/{hash}')
        ->and($named->first()->getActionName())->toContain('AuthKitController@verify');

    $url = (new class extends VerifyEmail
    {
        public function url(object $notifiable): string
        {
            return $this->verificationUrl($notifiable);
        }
    })->url($user);

    expect($url)->toStartWith('http://localhost/email/verify/'.$user->id.'/');

    $this->get($url)->assertRedirect('http://localhost/dashboard');

    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
});

// ---------------------------------------------------------------- Account

it('sends guests to sign in and back to the account page', function (): void {
    authKit();

    $this->get('/account')->assertRedirect('http://localhost/login');

    expect(session('url.intended'))->toBe('http://localhost/account');
});

it('renders the account sections for any signed-in user, without a panel access check', function (): void {
    authKit(fn (AuthKitPanel $kit) => $kit->multiFactorAuthentication([AppAuthentication::make()]));
    // `canAccessPanel()` is false for banned users: the account page is still theirs.
    $user = kitUser(['role' => 'banned']);

    $response = $this->actingAs($user)->get('/account', ['X-Inertia' => 'true', 'X-Inertia-Version' => '']);
    $props = inertiaProps($response);

    expect($response->json('component'))->toBe('settings/alfakit')
        ->and(array_keys($props['sections']))->toBe(['profile', 'password', 'twoFactor', 'sessions', 'deleteAccount'])
        ->and($props['sections']['profile']['content'][0]['state']['email'])->toBe($user->email)
        ->and($props['sections']['profile']['heading'])->toBe('Profile information')
        ->and($props['authKit']['logoutUrl'])->toBe('http://localhost/logout');

    // Internal requests of an account section work for that user.
    kitAction($this, $props['sections']['profile']['token'], 'submit', ['name' => 'Renamed', 'email' => $user->email])
        ->assertRedirect();

    expect($user->fresh()->name)->toBe('Renamed');
});

it('needs the current password to change the email address', function (): void {
    authKit();
    $user = kitUser();
    $this->actingAs($user);
    $token = Profile::make()->getContextToken();

    kitAction($this, $token, 'submit', ['name' => 'Kit', 'email' => 'moved@example.com'])
        ->assertJsonValidationErrors('current_password');

    kitAction($this, $token, 'submit', ['name' => 'Kit', 'email' => 'moved@example.com', 'current_password' => 'password'])
        ->assertSessionHasNoErrors();

    expect($user->fresh()->email)->toBe('moved@example.com');
});

it('changes the password only with the current one', function (): void {
    authKit();
    $user = kitUser();
    $props = inertiaProps($this->actingAs($user)->get('/account', ['X-Inertia' => 'true', 'X-Inertia-Version' => '']));
    $token = $props['sections']['password']['token'];

    kitAction($this, $token, 'submit', ['current_password' => 'wrong', 'password' => 'changed-pass-1', 'password_confirmation' => 'changed-pass-1'])
        ->assertJsonValidationErrors('current_password');

    kitAction($this, $token, 'submit', ['current_password' => 'password', 'password' => 'changed-pass-1', 'password_confirmation' => 'changed-pass-1'])
        ->assertSessionHasNoErrors();

    expect(Hash::check('changed-pass-1', $user->fresh()->password))->toBeTrue();
    $this->assertAuthenticatedAs($user);
});

it('lists the browser sessions and signs out the other ones', function (): void {
    Schema::create('sessions', function (Blueprint $table): void {
        $table->string('id')->primary();
        $table->foreignId('user_id')->nullable()->index();
        $table->string('ip_address', 45)->nullable();
        $table->text('user_agent')->nullable();
        $table->longText('payload');
        $table->integer('last_activity')->index();
    });

    authKit();
    $user = kitUser();
    config()->set('session.driver', 'database');

    DB::table('sessions')->insert([
        ['id' => 'other-1', 'user_id' => $user->id, 'ip_address' => '10.0.0.1', 'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) Chrome/120.0 Safari/537.36', 'payload' => '', 'last_activity' => now()->subHour()->getTimestamp()],
        ['id' => 'someone-else', 'user_id' => 999, 'ip_address' => '10.0.0.2', 'user_agent' => '', 'payload' => '', 'last_activity' => now()->getTimestamp()],
    ]);

    $props = inertiaProps($this->actingAs($user)->get('/account', ['X-Inertia' => 'true', 'X-Inertia-Version' => '']));
    $sessions = $props['sections']['sessions'];
    $list = $sessions['content'][0]['components'][0];

    expect(json_encode($list))->toContain('Chrome on macOS')->toContain('10.0.0.1')->not->toContain('10.0.0.2')
        ->and($sessions['headerActions'][0]['name'])->toBe('logoutOtherSessions');

    kitAction($this, $sessions['token'], 'logoutOtherSessions', ['password' => 'wrong'])->assertJsonValidationErrors('password');
    kitAction($this, $sessions['token'], 'logoutOtherSessions', ['password' => 'password'])->assertSessionHasNoErrors();

    expect(DB::table('sessions')->where('id', 'other-1')->exists())->toBeFalse()
        ->and(DB::table('sessions')->where('id', 'someone-else')->exists())->toBeTrue();
});

it('deletes the account after the password, signs out and can be turned off', function (): void {
    authKit();
    $user = kitUser();
    $this->actingAs($user);
    $token = DeleteAccount::make()->getContextToken();

    kitAction($this, $token, 'deleteAccount', ['password' => 'wrong'])->assertJsonValidationErrors('password');

    expect(MfaUser::query()->whereKey($user->id)->exists())->toBeTrue();

    kitAction($this, $token, 'deleteAccount', ['password' => 'password'])->assertRedirect('http://localhost');

    expect(MfaUser::query()->whereKey($user->id)->exists())->toBeFalse();
    $this->assertGuest();

    AuthKit::panel()->accountDeletion(false);
    $other = kitUser();

    expect(array_keys(inertiaProps($this->actingAs($other)->get('/account', ['X-Inertia' => 'true', 'X-Inertia-Version' => '']))['sections']))
        ->not->toContain('deleteAccount');
});

it('signs out and ends the session', function (): void {
    authKit();
    $this->actingAs(kitUser());

    $this->post('/logout')->assertRedirect('http://localhost');

    $this->assertGuest();
});

it('renders every page and translation in Arabic', function (): void {
    authKit();
    app()->setLocale('ar');

    $component = kitProps($this, '/login')['component'];

    expect($component['localization']['direction'])->toBe('rtl')
        ->and($component['heading'])->toBe(__('alfakit::auth.login.heading', [], 'ar'))
        ->and(__('alfakit::auth.account.sessions.heading', [], 'ar'))->not->toBe('auth.account.sessions.heading');
});

it('builds signed invitation links for the auth kit registration route', function (): void {
    $kit = authKit(fn (AuthKitPanel $kit) => $kit->invitationOnlyRegistration(expiresAfterDays: 3));

    $url = $kit->getRegistrationInvitationUrl('ann@example.com');

    expect($url)->toStartWith('http://localhost/register?')
        ->and(URL::hasValidSignature(Illuminate\Http\Request::create($url)))->toBeTrue();
});

it('replaces a page or section with an extended class', function (): void {
    authKit(fn (AuthKitPanel $kit) => $kit->components(['profile' => AlfaSix\Alfakit\Tests\Fixtures\AuthKit\AccountProfile::class]));
    $user = kitUser();

    $profile = inertiaProps($this->actingAs($user)->get('/account', ['X-Inertia' => 'true', 'X-Inertia-Version' => '']))['sections']['profile'];

    expect(array_column($profile['content'][0]['components'], 'name'))->toContain('locale');

    kitAction($this, $profile['token'], 'submit', ['name' => 'Kit', 'email' => $user->email, 'locale' => 'ar'])->assertRedirect();

    expect($user->fresh()->locale)->toBe('ar');
});
