<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Commands\Support\PanelProviderEditor;
use AlfaSix\Alfakit\Contracts\CanImpersonate;
use AlfaSix\Alfakit\Panel;
use AlfaSix\Alfakit\Tests\Fixtures\AuthTenancy\Panels;
use AlfaSix\Alfakit\Tests\Fixtures\Models\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;

/*
 * `alfakit:users`: the generated users resource is loaded and exercised in a real panel:
 * invitations, suspend/ban, forced password changes, 2FA reset, sign-in tracking and sessions.
 */

class UsersCommandTestUser extends User implements CanImpersonate
{
    protected $table = 'users';

    public function canImpersonate(): bool
    {
        return true;
    }
}

function usersSandbox(object $test): string
{
    $sandbox = sys_get_temp_dir().'/alfakit-users-'.uniqid();
    $files = new Filesystem;

    foreach (['app/Alfakit', 'app/Providers/Alfakit', 'database/migrations', 'bootstrap'] as $directory) {
        $files->ensureDirectoryExists("{$sandbox}/{$directory}");
    }

    $test->originalBasePath = app()->basePath();
    app()->setBasePath($sandbox);

    return $sandbox;
}

/**
 * Generate into a unique namespace, autoload it and register a panel that uses it.
 *
 * @return array{namespace: string, sandbox: string, panel: Panel}
 */
function generateUsersResource(object $test, array $options = []): array
{
    $sandbox = usersSandbox($test);
    $base = 'UsersSandbox'.bin2hex(random_bytes(4)).'\\Alfakit';
    // Apps with several panels get a namespace per panel (`App\Alfakit\People\…`).
    $namespace = $base.'\\People';

    config([
        'alfakit.file_generation.path' => $sandbox.'/app/Alfakit',
        'alfakit.file_generation.namespace' => $base,
        'auth.providers.users.model' => UsersCommandTestUser::class,
    ]);

    $test->artisan('alfakit:users', ['--model' => UsersCommandTestUser::class, '--panel' => 'people', '--no-provider' => true, ...$options])->assertSuccessful();

    spl_autoload_register(static function (string $class) use ($namespace, $sandbox): void {
        if (str_starts_with($class, $namespace.'\\')) {
            $file = $sandbox.'/app/Alfakit/People/'.str_replace('\\', '/', substr($class, strlen($namespace) + 1)).'.php';

            if (is_file($file)) {
                require_once $file;
            }
        }
    });

    $migration = require (glob($sandbox.'/database/migrations/*_add_alfakit_account_columns_to_users_table.php') ?: [''])[0];
    expect($migration)->toBeInstanceOf(Migration::class);
    $migration->up();

    $resource = $namespace.'\\Resources\\UserResource';
    $accept = $namespace.'\\Users\\Pages\\AcceptInvitation';
    $change = $namespace.'\\Users\\Pages\\ChangePassword';

    $panel = Panels::register('people', fn (Panel $panel): Panel => $panel
        ->login()
        ->resources([$resource])
        ->pages([$change])
        ->authMiddleware([$namespace.'\\Users\\Middleware\\EnsureAccountIsActive', $namespace.'\\Users\\Middleware\\RequirePasswordChange'])
        ->routes(fn (Router $router, Panel $panel) => $accept::registerRoute($router, $panel)));

    return ['namespace' => $namespace, 'sandbox' => $sandbox, 'panel' => $panel];
}

function usersCommandUser(array $attributes = []): UsersCommandTestUser
{
    return UsersCommandTestUser::query()->create([
        'name' => 'User '.uniqid(),
        'email' => uniqid().'@example.com',
        'password' => 'password',
        ...$attributes,
    ]);
}

afterEach(function (): void {
    if (isset($this->originalBasePath)) {
        (new Filesystem)->deleteDirectory(app()->basePath());
        app()->setBasePath($this->originalBasePath);
    }
});

it('generates every file, with valid PHP, and skips them on a second run', function (): void {
    ['sandbox' => $sandbox] = generateUsersResource($this);

    $files = [
        'app/Alfakit/People/Resources/UserResource.php',
        'app/Alfakit/People/Resources/UserResource/Pages/ListUsers.php',
        'app/Alfakit/People/Resources/UserResource/Pages/CreateUser.php',
        'app/Alfakit/People/Resources/UserResource/Pages/EditUser.php',
        'app/Alfakit/People/Resources/UserResource/Pages/ViewUser.php',
        'app/Alfakit/People/Users/UserAccounts.php',
        'app/Alfakit/People/Users/UserInvitation.php',
        'app/Alfakit/People/Users/Pages/AcceptInvitation.php',
        'app/Alfakit/People/Users/Pages/ChangePassword.php',
        'app/Alfakit/People/Users/Middleware/EnsureAccountIsActive.php',
        'app/Alfakit/People/Users/Middleware/RequirePasswordChange.php',
        'app/Listeners/RecordUserSignIn.php',
    ];

    foreach ($files as $file) {
        expect(file_exists("{$sandbox}/{$file}"))->toBeTrue($file)
            ->and(shell_exec('php -l '.escapeshellarg("{$sandbox}/{$file}")))->toContain('No syntax errors');
    }

    expect(file_get_contents("{$sandbox}/app/Alfakit/People/Resources/UserResource.php"))->not->toContain('{{')->not->toContain("Select::make('roles')");

    file_put_contents("{$sandbox}/app/Alfakit/People/Users/UserAccounts.php", '<?php // mine');

    $this->artisan('alfakit:users', ['--model' => UsersCommandTestUser::class, '--panel' => 'people', '--no-provider' => true])
        ->expectsOutputToContain('already generated')
        ->assertSuccessful();

    expect(file_get_contents("{$sandbox}/app/Alfakit/People/Users/UserAccounts.php"))->toBe('<?php // mine')
        ->and(glob("{$sandbox}/database/migrations/*_add_alfakit_account_columns_to_users_table.php"))->toHaveCount(1);
});

it('adds roles when asked and leaves the migration out with --no-migrations', function (): void {
    $sandbox = usersSandbox($this);
    config(['alfakit.file_generation.path' => $sandbox.'/app/Alfakit', 'alfakit.file_generation.namespace' => 'App\\Alfakit']);

    $this->artisan('alfakit:users', ['--model' => 'App\\Models\\User', '--panel' => 'admin', '--roles' => true, '--no-migrations' => true, '--no-provider' => true])->assertSuccessful();

    $resource = file_get_contents("{$sandbox}/app/Alfakit/Admin/Resources/UserResource.php");

    expect($resource)->toContain("Select::make('roles')")
        ->toContain("->relationship('roles', 'name')")
        ->toContain("TextColumn::make('roles.name')")
        ->toContain("SelectFilter::make('roles')")
        ->toContain('UserAccounts::roleOptions()')
        ->toContain('use App\\Models\\User;')
        ->and(glob("{$sandbox}/database/migrations/*.php"))->toBe([])
        ->and(shell_exec('php -l '.escapeshellarg("{$sandbox}/app/Alfakit/Admin/Resources/UserResource.php")))->toContain('No syntax errors');

    // Imports stay sorted for Pint (App\Alfakit before App\Models).
    expect(strpos($resource, 'use App\\Alfakit\\Admin\\Users\\UserAccounts;'))->toBeLessThan(strpos($resource, 'use App\\Models\\User;'));
});

it('registers middleware, the change-password page and the invitation route on the panel provider', function (): void {
    $sandbox = usersSandbox($this);
    config(['alfakit.file_generation.path' => $sandbox.'/app/Alfakit', 'alfakit.file_generation.namespace' => 'App\\Alfakit']);
    $this->artisan('make:alfakit-panel', ['id' => 'admin'])->assertSuccessful();

    $this->artisan('alfakit:users', ['--model' => 'App\\Models\\User', '--panel' => 'admin'])->assertSuccessful();
    $this->artisan('alfakit:users', ['--model' => 'App\\Models\\User', '--panel' => 'admin'])->assertSuccessful();

    $provider = file_get_contents(PanelProviderEditor::path('admin'));

    expect($provider)
        ->toContain("->authMiddleware([\n                EnsureAccountIsActive::class,\n                RequirePasswordChange::class,\n            ])")
        ->toContain('->pages([ChangePassword::class])')
        ->toContain('->routes(fn (Router $router, Panel $panel) => AcceptInvitation::registerRoute($router, $panel))')
        ->toContain('use App\\Alfakit\\Admin\\Users\\Middleware\\EnsureAccountIsActive;')
        ->toContain('use Illuminate\\Routing\\Router;')
        ->not->toContain('->resources([UserResource::class])')
        ->and(substr_count($provider, 'EnsureAccountIsActive::class'))->toBe(1)
        ->and(shell_exec('php -l '.escapeshellarg(PanelProviderEditor::path('admin'))))->toContain('No syntax errors');
});

it('renders the table, form and infolist and filters by status', function (): void {
    ['namespace' => $namespace] = generateUsersResource($this);
    $admin = usersCommandUser(['name' => 'Admin']);
    $suspended = usersCommandUser(['suspended_at' => now()]);
    $invited = usersCommandUser(['invited_at' => now()]);
    $this->actingAs($admin);

    alfakit()->testPage($namespace.'\\Resources\\UserResource\\Pages\\ListUsers', panel: 'people')
        ->assertOk()
        ->assertCanSeeTableRecords([$admin, $suspended, $invited])
        ->assertTableColumnStateSet('status', 'suspended', $suspended)
        ->assertTableColumnStateSet('status', 'invited', $invited)
        ->filterTable('status', 'suspended')
        ->assertCanSeeTableRecords([$suspended])
        ->assertCanNotSeeTableRecords([$admin, $invited])
        ->resetTableFilters()
        ->assertActionVisible('invite')
        // Never impersonate, suspend or delete yourself.
        ->assertTableActionHidden('impersonate', $admin)
        ->assertTableActionVisible('impersonate', $invited)
        ->assertTableActionHidden('impersonate', $suspended)
        ->assertTableActionHidden('suspend', $admin)
        ->assertTableActionHidden('delete', $admin)
        ->assertTableActionVisible('unsuspend', $suspended)
        ->assertTableActionVisible('resendInvitation', $invited);

    alfakit()->testPage($namespace.'\\Resources\\UserResource\\Pages\\EditUser', ['record' => $suspended], panel: 'people')->assertOk()->assertFormFieldExists('password_change_required');
    alfakit()->testPage($namespace.'\\Resources\\UserResource\\Pages\\ViewUser', ['record' => $suspended], panel: 'people')->assertOk();
    alfakit()->testPage($namespace.'\\Resources\\UserResource\\Pages\\CreateUser', panel: 'people')
        ->fillForm(['name' => 'New', 'email' => 'new@example.com', 'password' => 'Secret-password-1'])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Hash::check('Secret-password-1', (string) UsersCommandTestUser::query()->where('email', 'new@example.com')->value('password')))->toBeTrue();
});

it('suspends, bans and blocks those users from the panel', function (): void {
    ['namespace' => $namespace] = generateUsersResource($this);
    $admin = usersCommandUser();
    $user = usersCommandUser();
    $this->actingAs($admin);

    $list = $namespace.'\\Resources\\UserResource\\Pages\\ListUsers';

    alfakit()->testPage($list, panel: 'people')->callTableAction('suspend', $user)->assertActionSucceeded();
    expect($user->refresh()->suspended_at)->not->toBeNull();

    alfakit()->testPage($list, panel: 'people')->callTableAction('unsuspend', $user)->assertActionSucceeded();
    expect($user->refresh()->suspended_at)->toBeNull();

    alfakit()->testPage($list, panel: 'people')->callTableAction('ban', $user, ['reason' => 'Spam'])->assertActionSucceeded();
    expect($user->refresh())->banned_at->not->toBeNull()->ban_reason->toBe('Spam');

    // The banned user is signed out on their next request.
    $this->actingAs($user);
    inertiaGet($this, '/people/users')->assertRedirect('http://localhost/people/login');
    expect(auth()->check())->toBeFalse();

    $this->actingAs($admin);
    alfakit()->testPage($list, panel: 'people')->callTableAction('unban', $user)->assertActionSucceeded();
    expect($user->refresh()->banned_at)->toBeNull();
});

it('sends users that must change their password to the change-password page', function (): void {
    ['namespace' => $namespace] = generateUsersResource($this);
    $admin = usersCommandUser();
    $user = usersCommandUser(['password' => 'old-password']);
    $this->actingAs($admin);

    alfakit()->testPage($namespace.'\\Resources\\UserResource\\Pages\\ListUsers', panel: 'people')->callTableAction('requirePasswordChange', $user)->assertActionSucceeded();
    expect((bool) $user->refresh()->password_change_required)->toBeTrue();

    $this->actingAs($user);
    inertiaGet($this, '/people/users')->assertRedirect('http://localhost/people/change-password');
    inertiaGet($this, '/people/change-password')->assertOk();

    alfakit()->testPage($namespace.'\\Users\\Pages\\ChangePassword', panel: 'people')
        ->fillForm(['current_password' => 'wrong', 'password' => 'New-password-123', 'password_confirmation' => 'New-password-123'])
        ->call('save')
        ->assertHasFormErrors(['current_password']);

    alfakit()->testPage($namespace.'\\Users\\Pages\\ChangePassword', panel: 'people')
        ->fillForm(['current_password' => 'old-password', 'password' => 'New-password-123', 'password_confirmation' => 'New-password-123'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($user->refresh())->password_change_required->toBeFalsy()
        ->and(Hash::check('New-password-123', $user->password))->toBeTrue();

    inertiaGet($this, '/people/users')->assertOk();
});

it('invites a user who then sets a password through the signed link', function (): void {
    NotificationFacade::fake();
    ['namespace' => $namespace, 'panel' => $panel] = generateUsersResource($this);
    $admin = usersCommandUser();
    $this->actingAs($admin);

    alfakit()->testPage($namespace.'\\Resources\\UserResource\\Pages\\ListUsers', panel: 'people')
        ->callAction('invite', ['name' => 'Ivy', 'email' => 'ivy@example.com'])
        ->assertHasNoActionErrors();

    $ivy = UsersCommandTestUser::query()->where('email', 'ivy@example.com')->firstOrFail();
    $accounts = $namespace.'\\Users\\UserAccounts';
    $url = null;

    NotificationFacade::assertSentTo($ivy, $namespace.'\\Users\\UserInvitation', function (object $notification) use (&$url): bool {
        $url = $notification->url;

        return true;
    });

    expect($accounts::status($ivy))->toBe('invited')
        ->and($url)->toStartWith('http://localhost/people/invitations/'.$ivy->id.'/');

    // Unsigned or tampered links are refused.
    auth()->logout();
    $this->get('/people/invitations/'.$ivy->id.'/'.$accounts::invitationHash($ivy))->assertForbidden();
    $this->get(URL::temporarySignedRoute('alfakit.people.auth.invitation.accept', now()->addDay(), ['user' => $ivy->id, 'hash' => 'nope']))->assertForbidden();

    inertiaGet($this, (string) $url)->assertOk();

    parse_str((string) parse_url((string) $url, PHP_URL_QUERY), $signature);

    alfakit()->testPage($namespace.'\\Users\\Pages\\AcceptInvitation', ['user' => $ivy->id, 'hash' => $accounts::invitationHash($ivy), ...$signature], panel: 'people')
        ->fillForm(['password' => 'Ivy-password-123', 'password_confirmation' => 'Ivy-password-123'])
        ->call('accept')
        ->assertHasNoFormErrors();

    $ivy->refresh();

    expect(Hash::check('Ivy-password-123', $ivy->password))->toBeTrue()
        ->and($accounts::status($ivy))->toBe('active')
        ->and(auth()->id())->toBe($ivy->id);

    // The link only works once.
    auth()->logout();
    $this->get((string) $url)->assertForbidden();
});

it('resets two-factor authentication, records sign-ins and revokes sessions', function (): void {
    ['namespace' => $namespace] = generateUsersResource($this);
    Schema::table('users', fn ($table) => $table->text('two_factor_secret')->nullable());
    config(['session.driver' => 'database']);
    Schema::create('sessions', function ($table): void {
        $table->string('id')->primary();
        $table->foreignId('user_id')->nullable()->index();
        $table->string('ip_address', 45)->nullable();
        $table->text('user_agent')->nullable();
        $table->longText('payload');
        $table->integer('last_activity')->index();
    });

    $admin = usersCommandUser();
    $user = usersCommandUser(['two_factor_secret' => 'secret']);
    DB::table('sessions')->insert([
        ['id' => 'a', 'user_id' => $user->id, 'ip_address' => '10.0.0.1', 'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_0) AppleWebKit/605.1.15 Version/17.0 Safari/605.1.15', 'payload' => '', 'last_activity' => time()],
        ['id' => 'b', 'user_id' => $user->id, 'ip_address' => '10.0.0.2', 'user_agent' => '', 'payload' => '', 'last_activity' => time() - 60],
    ]);
    $this->actingAs($admin);

    $accounts = $namespace.'\\Users\\UserAccounts';

    expect($accounts::sessions($user))->toHaveCount(2)
        ->and($accounts::sessions($user)[0]['device'])->toBe('Safari · macOS');

    $view = $namespace.'\\Resources\\UserResource\\Pages\\ViewUser';

    alfakit()->testPage($view, ['record' => $user], panel: 'people')
        ->assertOk()
        ->callAction('resetTwoFactor')
        ->assertActionSucceeded();
    expect($user->refresh()->two_factor_secret)->toBeNull();

    alfakit()->testPage($view, ['record' => $user], panel: 'people')->callAction('revokeSession', ['session' => 'b'])->assertActionSucceeded();
    expect(DB::table('sessions')->where('user_id', $user->id)->pluck('id')->all())->toBe(['a']);

    alfakit()->testPage($view, ['record' => $user], panel: 'people')->callAction('revokeSessions')->assertActionSucceeded();
    expect(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(0);

    // The generated listener records the sign-in without touching updated_at.
    require_once app()->basePath('app/Listeners/RecordUserSignIn.php');
    $updatedAt = (string) $user->refresh()->updated_at;
    $this->travel(5)->minutes();
    (new App\Listeners\RecordUserSignIn)->handle(new Login('web', $user, false));

    expect($user->refresh()->last_login_at)->not->toBeNull()
        ->and((string) $user->updated_at)->toBe($updatedAt);
});

it('authorises the account actions with the user policy', function (): void {
    ['namespace' => $namespace] = generateUsersResource($this);
    $admin = usersCommandUser();
    $user = usersCommandUser();
    $this->actingAs($admin);

    Illuminate\Support\Facades\Gate::policy(UsersCommandTestUser::class, UsersCommandTestPolicy::class);

    alfakit()->testPage($namespace.'\\Resources\\UserResource\\Pages\\ListUsers', panel: 'people')
        ->assertTableActionHidden('suspend', $user)
        ->assertTableActionHidden('ban', $user)
        ->assertActionHidden('invite');
});

class UsersCommandTestPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, User $model): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, User $model): bool
    {
        return false;
    }
}
