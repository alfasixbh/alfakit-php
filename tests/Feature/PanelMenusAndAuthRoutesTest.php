<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Actions\Action;
use AlfaSix\Alfakit\Auth\MultiFactor\App\AppAuthentication;
use AlfaSix\Alfakit\Auth\MultiFactor\App\Totp;
use AlfaSix\Alfakit\Auth\MultiFactor\Email\EmailAuthentication;
use AlfaSix\Alfakit\Auth\MultiFactor\MultiFactorChallenge;
use AlfaSix\Alfakit\Auth\Pages\EditProfile;
use AlfaSix\Alfakit\Forms\Components\TextInput;
use AlfaSix\Alfakit\Navigation\MenuItem;
use AlfaSix\Alfakit\Pages\Dashboard;
use AlfaSix\Alfakit\Panel;
use AlfaSix\Alfakit\Tests\Fixtures\ActionLifecycle\LifecyclePage;
use AlfaSix\Alfakit\Tests\Fixtures\AuthTenancy\FakeBillingProvider;
use AlfaSix\Alfakit\Tests\Fixtures\AuthTenancy\Panels;
use AlfaSix\Alfakit\Tests\Fixtures\Models\MfaUser;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Team;
use AlfaSix\Alfakit\Tests\Fixtures\Models\TenantUser;
use AlfaSix\Alfakit\Tests\Fixtures\Models\User;
use AlfaSix\Alfakit\Tests\Fixtures\Tenancy\ProjectResource;
use AlfaSix\Alfakit\Tests\Fixtures\Tenancy\RegisterTeam;
use AlfaSix\Alfakit\Theming\Slot;
use Illuminate\Support\Facades\Notification as NotificationFacade;

/*
 * User and tenant menu items as actions, configurable auth routes and profile helpers,
 * the multi-factor challenge component, head slots and custom colours.
 */

beforeEach(function (): void {
    LifecyclePage::$actions = null;
    LifecyclePage::$runs = [];
});

function menuUser(): User
{
    return User::query()->create(['name' => 'Menu', 'email' => 'menu'.uniqid().'@example.com', 'password' => 'password']);
}

// ---------------------------------------------------------------- User menu

it('accepts actions, groups and keyed overrides in the user menu', function (): void {
    Panels::register('menus', fn (Panel $panel): Panel => $panel
        ->login()
        ->profile()
        ->pages([LifecyclePage::class])
        ->userMenuItems([
            'profile' => fn (Action $action): Action => $action->label('Edit profile'),
            'logout' => fn (Action $action): Action => $action->label('Log out')->icon('door-open'),
            [
                Action::make('docs')->label('Docs')->url('https://example.com/docs', shouldOpenInNewTab: true)->sort(2),
                Action::make('settings')->label('Settings')->url('/settings')->sort(1),
            ],
            [
                Action::make('lock')->label('Lock')->url('/lock')->postToUrl(),
                Action::make('hiddenItem')->url('/x')->hidden(),
                MenuItem::make()->label('Legacy')->url('/legacy'),
            ],
        ]));

    $this->actingAs(menuUser());
    $menu = inertiaProps(inertiaGet($this, '/menus/lifecycle'))['alfakit']['userMenu'];

    expect(array_column($menu['items'], 'label'))->toBe(['Settings', 'Docs', 'Lock', 'Legacy'])
        ->and($menu['items'][0])->not->toHaveKey('group')
        ->and($menu['items'][2]['group'])->toBe(1)
        ->and($menu['items'][2]['method'])->toBe('post')
        ->and($menu['items'][1]['openInNewTab'])->toBeTrue()
        ->and($menu['profile']['label'])->toBe('Edit profile')
        ->and($menu['profile']['url'])->toBe('http://localhost/menus/profile')
        ->and($menu['logout']['label'])->toBe('Log out')
        ->and($menu['logout']['icon'])->toBe('door-open')
        ->and($menu['logout']['method'])->toBe('post')
        ->and($menu['logout']['url'])->toBe($menu['logoutUrl']);
});

it('hides built-in user menu entries', function (): void {
    Panels::register('menuhide', fn (Panel $panel): Panel => $panel
        ->login()
        ->profile()
        ->pages([LifecyclePage::class])
        ->userMenuItems([
            'profile' => fn (Action $action): Action => $action->hidden(),
            'logout' => Action::make('logout')->visible(false),
        ]));

    $this->actingAs(menuUser());
    $menu = inertiaProps(inertiaGet($this, '/menuhide/lifecycle'))['alfakit']['userMenu'];

    expect($menu)->not->toHaveKey('profileUrl')
        ->and($menu)->not->toHaveKey('profile')
        ->and($menu['logout'])->toBeFalse();
});

it('runs user menu actions with modals through the action endpoint', function (): void {
    Panels::register('menuact', fn (Panel $panel): Panel => $panel
        ->login()
        ->pages([LifecyclePage::class])
        ->userMenuItems([
            Action::make('feedback')
                ->label('Send feedback')
                ->schema([TextInput::make('message')->required()])
                ->action(fn (array $data) => LifecyclePage::$runs[] = $data['message']),
        ]));

    $this->actingAs(menuUser());
    $props = inertiaProps(inertiaGet($this, '/menuact/lifecycle'));
    $item = $props['alfakit']['userMenu']['items'][0];

    expect($item['action']['name'])->toBe('feedback')
        ->and($item['action']['hasModal'])->toBeTrue();

    $token = $props['page']['token'];

    $this->getJson('/menuact/_alfakit/actions/modal?'.http_build_query(['token' => $token, 'component' => 'userMenu', 'action' => 'feedback']))
        ->assertOk()
        ->assertJsonPath('form.components.0.name', 'message');

    inertiaPost($this, '/menuact/_alfakit/actions', ['token' => $token, 'component' => 'userMenu', 'action' => 'feedback', 'data' => ['message' => '']])
        ->assertJsonValidationErrors('message');

    inertiaPost($this, '/menuact/_alfakit/actions', ['token' => $token, 'component' => 'userMenu', 'action' => 'feedback', 'data' => ['message' => 'Great']])
        ->assertRedirect();

    inertiaPost($this, '/menuact/_alfakit/actions', ['token' => $token, 'component' => 'userMenu', 'action' => 'missing'])
        ->assertNotFound();

    expect(LifecyclePage::$runs)->toBe(['Great']);
});

it('refuses hidden menu actions and guests', function (): void {
    Panels::register('menuguard', fn (Panel $panel): Panel => $panel
        ->login()
        ->pages([LifecyclePage::class])
        ->userMenuItems([
            Action::make('secret')->hidden()->action(fn () => LifecyclePage::$runs[] = 'secret'),
        ]));

    $this->actingAs(menuUser());
    $token = inertiaProps(inertiaGet($this, '/menuguard/lifecycle'))['page']['token'];

    inertiaPost($this, '/menuguard/_alfakit/actions', ['token' => $token, 'component' => 'userMenu', 'action' => 'secret'])->assertForbidden();

    expect(LifecyclePage::$runs)->toBe([]);
});

it('keeps MenuItem working in the user menu', function (): void {
    Panels::register('menulegacy', fn (Panel $panel): Panel => $panel
        ->login()
        ->pages([LifecyclePage::class])
        ->userMenuItems([MenuItem::make()->label('Help')->icon('life-buoy')->url('/help')->sort(5)]));

    $this->actingAs(menuUser());
    $menu = inertiaProps(inertiaGet($this, '/menulegacy/lifecycle'))['alfakit']['userMenu'];

    expect($menu['items'])->toBe([['label' => 'Help', 'icon' => 'life-buoy', 'url' => '/help']])
        ->and(alfakit()->getPanel('menulegacy')->getUserMenuItems()[0])->toBeInstanceOf(MenuItem::class);
});

// ---------------------------------------------------------------- Tenant menu

it('customises the tenant menu entries and runs tenant menu actions in the tenant', function (): void {
    Panels::register('teammenu', fn (Panel $panel): Panel => $panel
        ->login()
        ->tenant(Team::class, slugAttribute: 'slug')
        ->tenantRegistration(RegisterTeam::class)
        ->tenantBillingProvider(new FakeBillingProvider)
        ->resources([ProjectResource::class])
        ->pages([Dashboard::class, LifecyclePage::class])
        ->tenantMenuItems([
            'register' => fn (Action $action): Action => $action->label('Add a team'),
            'billing' => fn (Action $action): Action => $action->label('Plans')->icon('wallet'),
            Action::make('invite')
                ->label('Invite')
                ->requiresConfirmation()
                ->action(fn () => LifecyclePage::$runs[] = alfakit()->getTenant()?->getAttribute('slug')),
        ]));

    $user = TenantUser::query()->create(['name' => 'Ada', 'email' => 'ada'.uniqid().'@example.com', 'password' => 'secret']);
    $alpha = Team::query()->create(['name' => 'Alpha', 'slug' => 'alpha']);
    $user->teams()->attach([$alpha->id]);
    $this->actingAs($user);

    $props = inertiaProps(inertiaGet($this, '/teammenu/alpha/lifecycle'));
    $tenancy = $props['alfakit']['tenancy'];

    expect($tenancy['registration']['label'])->toBe('Add a team')
        ->and($tenancy['registrationUrl'])->toBe('http://localhost/teammenu/new')
        ->and(array_column($tenancy['menu'], 'label'))->toBe(['Invite', 'Plans'])
        ->and($tenancy['menu'][1]['icon'])->toBe('wallet')
        ->and($tenancy['menu'][1]['url'])->toBe('http://localhost/teammenu/alpha/billing')
        ->and($tenancy['menu'][0]['action']['name'])->toBe('invite');

    inertiaPost($this, '/teammenu/alpha/_alfakit/actions', ['token' => $props['page']['token'], 'component' => 'tenantMenu', 'action' => 'invite'])
        ->assertRedirect();

    expect(LifecyclePage::$runs)->toBe(['alpha']);
});

// ---------------------------------------------------------------- Auth routes & profile

it('registers the auth pages at custom slugs', function (): void {
    $panel = Panels::register('slugs', fn (Panel $panel): Panel => $panel
        ->login()
        ->registration()
        ->passwordReset()
        ->emailVerification()
        ->emailChangeVerification()
        ->loginRouteSlug('sign-in')
        ->registrationRouteSlug('/sign-up/')
        ->passwordResetRoutePrefix('forgot')
        ->passwordResetRequestRouteSlug('start')
        ->passwordResetRouteSlug('new')
        ->emailVerificationRoutePrefix('verify-email')
        ->emailVerificationPromptRouteSlug('notice')
        ->emailVerificationRouteSlug('confirm')
        ->emailChangeVerificationRoutePrefix('email-change')
        ->emailChangeVerificationRouteSlug('confirm')
        ->pages([Dashboard::class]));

    expect($panel->getLoginUrl())->toBe('http://localhost/slugs/sign-in')
        ->and($panel->getRegistrationUrl())->toBe('http://localhost/slugs/sign-up')
        ->and($panel->getPasswordResetUrl())->toBe('http://localhost/slugs/forgot/start')
        ->and(route('alfakit.slugs.auth.password-reset.reset'))->toBe('http://localhost/slugs/forgot/new')
        ->and($panel->getEmailVerificationPromptUrl())->toBe('http://localhost/slugs/verify-email/notice')
        ->and(route('alfakit.slugs.auth.email-verification.verify', ['id' => 1, 'hash' => 'abc']))->toBe('http://localhost/slugs/verify-email/confirm/1/abc')
        ->and(route('alfakit.slugs.auth.email-change-verification.verify', ['id' => 1, 'token' => 'abc']))->toBe('http://localhost/slugs/email-change/confirm/1/abc');

    $this->get('/slugs/sign-in')->assertOk();
    $this->get('/slugs/login')->assertNotFound();
    $this->get('/slugs')->assertRedirect('http://localhost/slugs/sign-in');
});

it('builds the profile form from overridable component helpers', function (): void {
    Panels::register('prof', fn (Panel $panel): Panel => $panel->login()->profile(ProfileWithUsername::class)->pages([Dashboard::class]));

    $this->actingAs(menuUser());
    $props = inertiaProps(inertiaGet($this, '/prof/profile'));
    $form = collect($props['page']['content'])->firstWhere('type', 'Form');
    $names = array_column($form['components'], 'name');

    expect($names)->toBe(['username', 'name', 'email', 'password', 'password_confirmation'])
        ->and(collect($form['components'])->firstWhere('name', 'password'))->not->toHaveKey('revealable');
});

it('turns off password reveal toggles with revealablePasswords(false)', function (): void {
    Panels::register('noreveal', fn (Panel $panel): Panel => $panel->login()->revealablePasswords(false)->pages([Dashboard::class]));
    Panels::register('reveal', fn (Panel $panel): Panel => $panel->login()->pages([Dashboard::class]));

    $password = fn (string $url): array => collect(collect(inertiaProps(inertiaGet($this, $url))['page']['content'])->firstWhere('type', 'Form')['components'])->firstWhere('name', 'password');

    expect($password('/noreveal/login'))->not->toHaveKey('revealable')
        ->and($password('/reveal/login')['revealable'] ?? null)->toBeTrue();
});

// ---------------------------------------------------------------- Multi-factor challenge in actions

function sudoPanel(?Closure $configure = null): Panel
{
    config()->set('auth.providers.users.model', MfaUser::class);
    app('auth')->forgetGuards();

    return Panels::register('sudo', function (Panel $panel) use ($configure): Panel {
        $panel->login()->pages([LifecyclePage::class])
            ->multiFactorAuthentication([AppAuthentication::make()->recoverable()->recoveryCodeCount(2), EmailAuthentication::make()]);

        return $configure === null ? $panel : $configure($panel);
    });
}

function sudoPage(mixed $test, MfaUser $user): string
{
    LifecyclePage::$actions = fn (): array => [
        Action::make('destroy')
            ->requiresConfirmation()
            ->schema([MultiFactorChallenge::make()])
            ->action(fn (array $data) => LifecyclePage::$runs[] = $data),
    ];

    $test->actingAs($user);

    return inertiaProps(inertiaGet($test, '/sudo/lifecycle'))['page']['token'];
}

it('verifies an authenticator code before a sensitive action runs', function (): void {
    sudoPanel();
    $secret = Totp::generateSecret();
    $user = MfaUser::query()->create(['name' => 'Mia', 'email' => 'mia'.uniqid().'@example.com', 'password' => 'password']);
    $user->saveAppAuthenticationSecret($secret);
    $token = sudoPage($this, $user);

    $modal = $this->getJson('/sudo/_alfakit/actions/modal?'.http_build_query(['token' => $token, 'action' => 'destroy']))->assertOk()->json();
    $group = $modal['form']['components'][0];

    expect($group['type'])->toBe('Group')
        ->and(array_column($group['schema'] ?? $group['components'] ?? [], 'name'))->toContain('code');

    inertiaPost($this, '/sudo/_alfakit/actions', ['token' => $token, 'action' => 'destroy', 'data' => ['multiFactorChallenge' => ['method' => 'app', 'code' => '000000']]])
        ->assertJsonValidationErrors('multiFactorChallenge.code');

    expect(LifecyclePage::$runs)->toBe([]);

    inertiaPost($this, '/sudo/_alfakit/actions', ['token' => $token, 'action' => 'destroy', 'data' => ['multiFactorChallenge' => ['method' => 'app', 'code' => Totp::code($secret)]]])
        ->assertRedirect();

    // The code never reaches the action's data.
    expect(LifecyclePage::$runs)->toBe([[]]);
});

it('accepts a recovery code once and rate limits wrong codes', function (): void {
    sudoPanel();
    $user = MfaUser::query()->create(['name' => 'Mia', 'email' => 'mia'.uniqid().'@example.com', 'password' => 'password']);
    $user->saveAppAuthenticationSecret(Totp::generateSecret());
    $codes = (new AppAuthentication)->recoverable()->regenerateRecoveryCodes($user);
    $token = sudoPage($this, $user);

    $run = fn (string $method, string $code) => inertiaPost($this, '/sudo/_alfakit/actions', ['token' => $token, 'action' => 'destroy', 'data' => ['multiFactorChallenge' => ['method' => $method, 'code' => $code]]]);

    $run('recovery', $codes[0])->assertRedirect();
    $run('recovery', $codes[0])->assertJsonValidationErrors('multiFactorChallenge.code');

    foreach (range(1, 5) as $attempt) {
        $run('app', '000000');
    }

    $run('recovery', $codes[1])->assertJsonValidationErrors('multiFactorChallenge.code');

    expect(LifecyclePage::$runs)->toHaveCount(1);
});

it('emails the code when the modal opens for email users', function (): void {
    NotificationFacade::fake();
    sudoPanel();
    $user = MfaUser::query()->create(['name' => 'Mia', 'email' => 'mia'.uniqid().'@example.com', 'password' => 'password', 'has_email_authentication' => true]);
    $token = sudoPage($this, $user);

    $this->getJson('/sudo/_alfakit/actions/modal?'.http_build_query(['token' => $token, 'action' => 'destroy']))->assertOk();

    NotificationFacade::assertSentTo($user, AlfaSix\Alfakit\Auth\MultiFactor\Email\Notifications\VerifyEmailAuthentication::class);
});

it('fails closed for users without a second factor', function (): void {
    sudoPanel();
    $user = MfaUser::query()->create(['name' => 'Mia', 'email' => 'mia'.uniqid().'@example.com', 'password' => 'password']);
    $token = sudoPage($this, $user);

    expect(MultiFactorChallenge::make()->hasEnabledProviders($user))->toBeFalse();

    inertiaPost($this, '/sudo/_alfakit/actions', ['token' => $token, 'action' => 'destroy', 'data' => ['multiFactorChallenge' => ['code' => '123456']]])
        ->assertJsonValidationErrors('multiFactorChallenge.code');

    expect(LifecyclePage::$runs)->toBe([]);
});

it('hides the regenerate recovery codes action with regenerableRecoveryCodes(false)', function (): void {
    expect(AppAuthentication::make()->recoverable()->areRecoveryCodesRegenerable())->toBeTrue()
        ->and(AppAuthentication::make()->recoverable()->regenerableRecoveryCodes(false)->areRecoveryCodesRegenerable())->toBeFalse()
        ->and(AppAuthentication::make()->areRecoveryCodesRegenerable())->toBeFalse();
});

// ---------------------------------------------------------------- Head slots

it('renders head slots into the root view with the CSP nonce', function (): void {
    Panels::register('heads', fn (Panel $panel): Panel => $panel
        ->login()
        ->pages([LifecyclePage::class])
        ->slot(Slot::HEAD_START, '<meta name="head-start" content="1">')
        ->slot(Slot::HEAD_END, fn (?string $nonce): string => '<script>window.headEnd = '.json_encode($nonce).'</script>')
        ->slot(Slot::HEAD_END, '<meta name="scoped" content="1">', scopes: [Dashboard::class]));

    Illuminate\Support\Facades\Vite::useCspNonce('test-nonce');
    $this->actingAs(menuUser());

    $html = $this->get('/heads/lifecycle')->assertOk()->getContent();
    $headStart = strpos((string) $html, 'name="head-start"');

    expect($headStart)->toBeInt()
        ->and($headStart)->toBeLessThan(strpos((string) $html, '<title'))
        ->and($html)->toContain('<script nonce="test-nonce">window.headEnd = "test-nonce"</script>')
        ->and($html)->not->toContain('name="scoped"');

    // Head slots are not sent to the renderer.
    $props = inertiaProps(inertiaGet($this, '/heads/lifecycle'));

    expect($props['alfakit']['slots'] ?? [])->not->toHaveKey(Slot::HEAD_START);
});

// ---------------------------------------------------------------- Colours

it('emits custom colours with foregrounds, their class rules and the gray flag', function (): void {
    $panel = Panels::register('colours', fn (Panel $panel): Panel => $panel
        ->login()
        ->pages([LifecyclePage::class])
        ->colors(['primary' => '#FDE68A', 'brand' => '#4f46e5', 'pale' => '#fef9c3', 'gray' => '#71717a', 'bad name;' => 'red']));

    $style = $panel->getHtmlThemeStyle();
    $css = (string) $panel->getCustomColorCss();

    expect($style)->toContain('--alfakit-brand: #4f46e5')
        ->and($style)->toContain('--alfakit-brand-foreground: oklch(0.985 0 0)')
        ->and($style)->toContain('--alfakit-pale-foreground: oklch(0.205 0 0)')
        ->and($style)->toContain('--alfakit-primary-foreground: oklch(0.205 0 0)')
        ->and($style)->not->toContain('bad name')
        ->and($css)->toContain('.alfakit-color-brand{--alfakit-c:var(--alfakit-brand)')
        ->and($css)->not->toContain('alfakit-color-primary')
        ->and($css)->not->toContain('alfakit-color-gray')
        ->and($panel->getHtmlThemeAttributes()['data-alfakit-gray'])->toBe('true');

    $this->actingAs(menuUser());
    $props = inertiaProps(inertiaGet($this, '/colours/lifecycle'));

    expect($props['alfakit']['colorCss'])->toBe($css);
});

class ProfileWithUsername extends EditProfile
{
    public function form(AlfaSix\Alfakit\Schemas\Schema $schema): AlfaSix\Alfakit\Schemas\Schema
    {
        return $schema->components([
            TextInput::make('username')->maxLength(255),
            $this->getNameFormComponent(),
            $this->getEmailFormComponent(),
            $this->getPasswordFormComponent()->revealable(false),
            $this->getPasswordConfirmationFormComponent(),
        ]);
    }
}
