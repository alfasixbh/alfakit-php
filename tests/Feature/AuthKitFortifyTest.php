<?php

declare(strict_types=1);

use AlfaSix\Alfakit\AuthKit\AuthKit;
use AlfaSix\Alfakit\AuthKit\AuthKitPanel;
use AlfaSix\Alfakit\AuthKit\Pages\TwoFactorChallenge;
use AlfaSix\Alfakit\Tests\Fixtures\AuthKit\FortifyUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Laravel\Fortify\Contracts\CreatesNewUsers;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Contracts\UpdatesUserPasswords;
use Laravel\Fortify\Contracts\UpdatesUserProfileInformation;
use Laravel\Fortify\Fortify;

/*
 * The auth kit on Laravel Fortify's backend (`AuthKitPanel::fortify()`): its actions,
 * `authenticateUsing()` and two-factor authentication, with AlfaKit's UI.
 */

require_once __DIR__.'/../Fixtures/AuthKit/FortifyStubs.php';

beforeEach(function (): void {
    config()->set('auth.providers.users.model', FortifyUser::class);
    app('auth')->forgetGuards();

    Schema::table('users', function (Blueprint $table): void {
        $table->text('two_factor_secret')->nullable();
        $table->text('two_factor_recovery_codes')->nullable();
    });

    AuthKit::configure(fn (AuthKitPanel $kit) => $kit->fortify());

    Route::middleware('web')->group(fn () => AuthKit::routes());
    Route::getRoutes()->refreshNameLookups();
    Route::getRoutes()->refreshActionLookups();

    Fortify::$authenticateUsingCallback = null;
});

afterEach(function (): void {
    Fortify::$authenticateUsingCallback = null;
});

function fortifyUser(array $attributes = []): FortifyUser
{
    return FortifyUser::query()->create(['name' => 'Fay', 'email' => 'fay'.uniqid().'@example.com', 'password' => 'password', ...$attributes]);
}

function fortifyAction(mixed $test, string $token, string $action, array $data): Illuminate\Testing\TestResponse
{
    return inertiaPost($test, '/_alfakit/auth/actions', ['token' => $token, 'action' => $action, 'data' => $data]);
}

it('creates users with the app\'s Fortify action', function (): void {
    app()->instance(CreatesNewUsers::class, new class implements CreatesNewUsers
    {
        public function create(array $input): mixed
        {
            return FortifyUser::query()->create([
                'name' => strtoupper((string) $input['name']),
                'email' => $input['email'],
                'password' => Hash::make((string) $input['password']),
            ]);
        }
    });

    expect(AuthKit::panel()->usesFortify())->toBeTrue();

    $token = inertiaProps(inertiaGet($this, '/register'))['component']['token'];

    fortifyAction($this, $token, 'register', ['name' => 'Fred', 'email' => 'fred@example.com', 'password' => 'secret-pass-1', 'password_confirmation' => 'secret-pass-1'])
        ->assertRedirect();

    expect(FortifyUser::query()->where('email', 'fred@example.com')->value('name'))->toBe('FRED');
    $this->assertAuthenticated();
});

it('signs in with Fortify\'s authenticateUsing callback', function (): void {
    $user = fortifyUser();
    Fortify::$authenticateUsingCallback = fn (Request $request): ?FortifyUser => $request->input('email') === $user->email && $request->input('password') === 'magic' ? $user : null;

    $token = inertiaProps(inertiaGet($this, '/login'))['component']['token'];

    fortifyAction($this, $token, 'authenticate', ['email' => $user->email, 'password' => 'password'])->assertJsonValidationErrors('email');
    fortifyAction($this, $token, 'authenticate', ['email' => $user->email, 'password' => 'magic'])->assertRedirect();

    $this->assertAuthenticatedAs($user);
});

it('asks users with Fortify two-factor authentication for their code or a recovery code', function (): void {
    app()->instance(TwoFactorAuthenticationProvider::class, new class implements TwoFactorAuthenticationProvider
    {
        public function verify(string $secret, string $code): bool
        {
            return $secret === 'SECRET' && $code === '123456';
        }
    });

    $user = fortifyUser([
        'two_factor_secret' => encrypt('SECRET'),
        'two_factor_recovery_codes' => encrypt((string) json_encode(['rc-one', 'rc-two'])),
    ]);

    $login = inertiaProps(inertiaGet($this, '/login'))['component']['token'];

    fortifyAction($this, $login, 'authenticate', ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect('http://localhost/two-factor-challenge/'.TwoFactorChallenge::FORTIFY);

    $this->assertGuest();

    $challenge = inertiaProps(inertiaGet($this, '/two-factor-challenge/fortify'))['component'];

    expect($challenge['content'][0]['components'][0]['type'])->toBe('OneTimeCodeInput')
        ->and($challenge['content'][1]['links'][0]['url'])->toBe('http://localhost/two-factor-challenge/fortify-recovery');

    fortifyAction($this, $challenge['token'], 'verify', ['code' => '000000'])->assertJsonValidationErrors('code');
    fortifyAction($this, $challenge['token'], 'verify', ['code' => '123456'])->assertRedirect();

    $this->assertAuthenticatedAs($user);

    // A recovery code works once.
    auth()->logout();
    fortifyAction($this, inertiaProps(inertiaGet($this, '/login'))['component']['token'], 'authenticate', ['email' => $user->email, 'password' => 'password']);
    $recovery = inertiaProps(inertiaGet($this, '/two-factor-challenge/fortify-recovery'))['component'];

    expect($recovery['content'][0]['components'][0]['type'])->toBe('TextInput');

    fortifyAction($this, $recovery['token'], 'verify', ['code' => 'rc-two'])->assertRedirect();

    $this->assertAuthenticatedAs($user);
    expect($user->fresh()->recoveryCodes())->toBe(['rc-one', 'replaced-rc-two']);
});

it('updates the profile and password with the app\'s Fortify actions', function (): void {
    $calls = new ArrayObject;

    app()->instance(UpdatesUserProfileInformation::class, new class($calls) implements UpdatesUserProfileInformation
    {
        public function __construct(private ArrayObject $calls) {}

        public function update(mixed $user, array $input): void
        {
            $this->calls[] = ['profile', $input];
            $user->forceFill(['name' => $input['name']])->save();
        }
    });

    app()->instance(UpdatesUserPasswords::class, new class($calls) implements UpdatesUserPasswords
    {
        public function __construct(private ArrayObject $calls) {}

        public function update(mixed $user, array $input): void
        {
            $this->calls[] = ['password', array_keys($input)];
            $user->forceFill(['password' => Hash::make((string) $input['password'])])->save();
        }
    });

    $user = fortifyUser();
    $sections = inertiaProps($this->actingAs($user)->get('/account', ['X-Inertia' => 'true', 'X-Inertia-Version' => '']))['sections'];

    fortifyAction($this, $sections['profile']['token'], 'submit', ['name' => 'Fiona', 'email' => $user->email])->assertRedirect();
    fortifyAction($this, $sections['password']['token'], 'submit', ['current_password' => 'password', 'password' => 'next-pass-123', 'password_confirmation' => 'next-pass-123'])->assertRedirect();

    expect($user->fresh()->name)->toBe('Fiona')
        ->and(Hash::check('next-pass-123', $user->fresh()->password))->toBeTrue()
        ->and($calls[0][0])->toBe('profile')
        ->and($calls[1])->toBe(['password', ['current_password', 'password', 'password_confirmation']]);
});
