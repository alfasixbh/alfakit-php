<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Tests\Fixtures\Models\Project;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Team;
use AlfaSix\Alfakit\Tests\Fixtures\Models\TenantUser;

function tenantUser(): array
{
    $user = TenantUser::query()->create(['name' => 'Ada', 'email' => 'ada@example.com', 'password' => 'secret']);
    $alpha = Team::query()->create(['name' => 'Alpha', 'slug' => 'alpha']);
    $beta = Team::query()->create(['name' => 'Beta', 'slug' => 'beta']);
    $other = Team::query()->create(['name' => 'Other', 'slug' => 'other']);
    $user->teams()->attach([$alpha->id, $beta->id]);

    Project::query()->create(['team_id' => $alpha->id, 'name' => 'Alpha project']);
    Project::query()->create(['team_id' => $beta->id, 'name' => 'Beta project']);
    Project::query()->create(['team_id' => $other->id, 'name' => 'Other project']);

    return [$user, $alpha, $beta, $other];
}

it('registers tenant-prefixed routes', function (): void {
    expect(route('alfakit.app.resources.projects.index', ['tenant' => 'alpha']))->toBe('http://localhost/app/alpha/projects')
        ->and(route('alfakit.app.auth.login'))->toBe('http://localhost/app/login')
        ->and(route('alfakit.app.internal.tenant.actions.execute', ['tenant' => 'alpha']))->toBe('http://localhost/app/alpha/_alfakit/actions');
});

it('redirects the panel root to the first tenant', function (): void {
    [$user] = tenantUser();

    $this->actingAs($user)->get('/app')->assertRedirect('http://localhost/app/alpha');
});

it('scopes resources to the current tenant and shares tenant data', function (): void {
    [$user, $alpha, $beta] = tenantUser();

    $props = inertiaProps(inertiaGet($this->actingAs($user), '/app/alpha/projects'));
    $table = $props['page']['content'][0];

    expect(array_column($table['records'], 'key'))->toBe([Project::query()->where('team_id', $alpha->id)->value('id')])
        ->and($props['alfakit']['tenancy']['current']['name'])->toBe('Alpha')
        ->and(array_column($props['alfakit']['tenancy']['tenants'], 'name'))->toBe(['Alpha', 'Beta'])
        ->and($props['alfakit']['tenancy']['tenants'][1]['url'])->toBe('http://localhost/app/beta')
        ->and($props['alfakit']['endpoints']['action'])->toBe('http://localhost/app/alpha/_alfakit/actions')
        ->and($props['alfakit']['navigation'][0]['items'][1]['url'])->toBe('http://localhost/app/alpha/projects');

    $beta = inertiaProps(inertiaGet($this, '/app/beta/projects'));

    expect($beta['page']['content'][0]['records'][0]['cells']['name']['state'])->toBe('Beta project');
});

it('forbids tenants the user cannot access and unknown tenants', function (): void {
    [$user] = tenantUser();

    $this->actingAs($user)->get('/app/other/projects')->assertNotFound();
    $this->get('/app/nope/projects')->assertNotFound();
});

it('associates created records with the current tenant', function (): void {
    [$user, $alpha] = tenantUser();

    $props = inertiaProps(inertiaGet($this->actingAs($user), '/app/alpha/projects/create'));

    inertiaPost($this, '/app/alpha/_alfakit/actions', ['token' => $props['page']['token'], 'action' => 'create', 'data' => ['name' => 'New project']])
        ->assertRedirect('http://localhost/app/alpha/projects');

    expect(Project::query()->where('name', 'New project')->value('team_id'))->toBe($alpha->id);
});

it('limits relationship options and exists rules to the current tenant', function (): void {
    [$user, $alpha] = tenantUser();
    $this->actingAs($user);
    alfakit()->setCurrentPanel(alfakit()->getPanel('app'));
    alfakit()->setTenant($alpha);

    expect(AlfaSix\Alfakit\Support\TenantScope::apply(Project::query())->pluck('name')->all())->toBe(['Alpha project']);

    $rule = AlfaSix\Alfakit\Support\TenantScope::applyToRule(new Illuminate\Validation\Rules\Unique('projects', 'name'), new Project, 'projects');

    expect((string) $rule)->toContain('team_id')->toContain((string) $alpha->id);
});

it('resolves the tenant from the token, not the internal endpoint url', function (): void {
    [$user] = tenantUser();
    $props = inertiaProps(inertiaGet($this->actingAs($user), '/app/alpha/projects/create'));

    // Posting an alpha token to the beta (or un-prefixed) endpoint still writes to alpha.
    inertiaPost($this, '/app/_alfakit/actions', ['token' => $props['page']['token'], 'action' => 'create', 'data' => ['name' => 'Via token']])
        ->assertRedirect();

    expect(Project::query()->where('name', 'Via token')->value('team_id'))
        ->toBe(Team::query()->where('slug', 'alpha')->value('id'));
});

it('renders tenant-less pages (profile, tenant registration, email verification) on tenancy panels', function (): void {
    [$user] = tenantUser();
    $user->forceFill(['email_verified_at' => null])->save();

    foreach (['/app/profile', '/app/new', '/app/email-verification/prompt'] as $url) {
        $props = inertiaProps(inertiaGet($this->actingAs($user), $url));

        expect($props['alfakit']['tenancy']['current'])->toBeNull()
            ->and($props['alfakit']['navigation'])->toBe([]);
    }
});

it('redirects the panel root to the HasDefaultTenant choice when the user can access it', function (): void {
    [$user] = tenantUser();
    $default = AlfaSix\Alfakit\Tests\Fixtures\Models\DefaultTenantUser::query()->findOrFail($user->getKey());

    AlfaSix\Alfakit\Tests\Fixtures\Models\DefaultTenantUser::$defaultSlug = 'beta';
    $this->actingAs($default)->get('/app')->assertRedirect('http://localhost/app/beta');

    // A default the user can't access falls back to their first tenant.
    AlfaSix\Alfakit\Tests\Fixtures\Models\DefaultTenantUser::$defaultSlug = 'other';
    $this->get('/app')->assertRedirect('http://localhost/app/alpha');

    AlfaSix\Alfakit\Tests\Fixtures\Models\DefaultTenantUser::$defaultSlug = 'beta';
});

it('requires a concrete page for tenant registration and the tenant profile', function (): void {
    $panel = AlfaSix\Alfakit\Panel::make('requires-page');

    expect(fn () => $panel->tenantRegistration(AlfaSix\Alfakit\Tenancy\Pages\RegisterTenant::class))
        ->toThrow(InvalidArgumentException::class, 'Panel::tenantRegistration() needs a concrete page class that extends')
        ->and(fn () => $panel->tenantProfile(AlfaSix\Alfakit\Pages\Dashboard::class))
        ->toThrow(InvalidArgumentException::class, 'Panel::tenantProfile() needs a concrete page class that extends')
        ->and($panel->tenantRegistration(AlfaSix\Alfakit\Tests\Fixtures\Tenancy\RegisterTeam::class)->hasTenantRegistration())->toBeTrue()
        ->and($panel->tenantRegistration(null)->hasTenantRegistration())->toBeFalse();
});

it('registers panel routes that route:cache can serialise', function (): void {
    $routes = collect(app('router')->getRoutes()->getRoutes())
        ->filter(fn (Illuminate\Routing\Route $route): bool => str_starts_with((string) $route->getName(), 'alfakit.'));

    expect($routes->map(fn (Illuminate\Routing\Route $route) => $route->getName())->all())
        ->toContain('alfakit.app.home', 'alfakit.app.tenant.registration');

    foreach ($routes as $route) {
        expect($route->getAction('uses'))->not->toBeInstanceOf(Closure::class, "Route [{$route->getName()}] uses a closure.")
            ->and(array_filter($route->middleware(), fn (mixed $middleware): bool => ! is_string($middleware)))->toBe([]);
    }

    // The compiled collection is what `route:cache` writes.
    expect(app('router')->getRoutes()->compile())->toBeArray();
});

it('drives tenant pages with the page test helpers', function (): void {
    [$user, $alpha, $beta, $other] = tenantUser();
    $this->actingAs($user);

    alfakit()->testPage(AlfaSix\Alfakit\Tests\Fixtures\Tenancy\ListProjects::class, ['tenant' => $alpha], panel: 'app')
        ->assertCanSeeTableRecords(Project::query()->where('team_id', $alpha->id)->get())
        ->assertCanNotSeeTableRecords(Project::query()->where('team_id', '!=', $alpha->id)->get())
        ->assertCountTableRecords(1);

    alfakit()->testPage(AlfaSix\Alfakit\Tests\Fixtures\Tenancy\CreateProject::class, ['tenant' => $beta], panel: 'app')
        ->fillForm(['name' => 'Made by a helper'])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertRedirect('http://localhost/app/beta/projects');

    expect(Project::query()->where('name', 'Made by a helper')->value('team_id'))->toBe($beta->id);

    alfakit()->testPage(AlfaSix\Alfakit\Tests\Fixtures\Tenancy\ListProjects::class, ['tenant' => $other], panel: 'app')->assertNotFound();
});

it('refuses a token replayed under another tenant the user belongs to', function (): void {
    [$user] = tenantUser();
    $props = inertiaProps(inertiaGet($this->actingAs($user), '/app/alpha/projects/create'));

    // The user is a member of beta too, but the token was issued on an alpha page.
    inertiaPost($this, '/app/beta/_alfakit/actions', ['token' => $props['page']['token'], 'action' => 'create', 'data' => ['name' => 'Replayed']])
        ->assertNotFound();

    expect(Project::query()->where('name', 'Replayed')->exists())->toBeFalse();
});

it('validates unique() on the tenant registration form against the tenant table', function (): void {
    [$user] = tenantUser();

    $props = inertiaProps(inertiaGet($this->actingAs($user), '/app/new'));
    $token = $props['page']['token'];

    // The documented `TextInput::make('slug')->unique()` checks the teams table (it used the field name as table).
    inertiaPost($this, '/app/_alfakit/actions', ['token' => $token, 'action' => 'register', 'data' => ['name' => 'Again', 'slug' => 'alpha']])
        ->assertInvalid(['slug']);

    inertiaPost($this, '/app/_alfakit/actions', ['token' => $token, 'action' => 'register', 'data' => ['name' => 'Gamma', 'slug' => 'gamma']])
        ->assertRedirect('http://localhost/app/gamma');
});

it('never discovers tenant registration and profile pages as ordinary pages', function (): void {
    $panel = AlfaSix\Alfakit\Panel::make()->id('discovery')->path('discovery')
        ->discoverPages(in: dirname(__DIR__).'/Fixtures/Tenancy', for: 'AlfaSix\\Alfakit\\Tests\\Fixtures\\Tenancy');

    expect($panel->getPages())->not->toContain(AlfaSix\Alfakit\Tests\Fixtures\Tenancy\RegisterTeam::class);
});

it('labels the tenant menu links with the registration and profile pages\' own labels', function (): void {
    [$user] = tenantUser();

    $tenancy = inertiaProps(inertiaGet($this->actingAs($user), '/app/alpha/projects'))['alfakit']['tenancy'];

    // `RegisterTeam::getLabel()`; without a profile page there is no profile link or label.
    expect($tenancy['registrationUrl'])->toBe('http://localhost/app/new')
        ->and($tenancy['registrationLabel'])->toBe('Create team')
        ->and($tenancy['profileLabel'] ?? null)->toBeNull();
});

class SharedOwnerProjectsRelationManager extends AlfaSix\Alfakit\Resources\RelationManagers\RelationManager
{
    protected static string $relationship = 'projects';

    public function table(AlfaSix\Alfakit\Tables\Table $table): AlfaSix\Alfakit\Tables\Table
    {
        return $table->columns([AlfaSix\Alfakit\Tables\Columns\TextColumn::make('name')]);
    }
}

it('keeps relation manager records in the current tenant when the owner record is not the tenant\'s', function (): void {
    [$user, $alpha, $beta] = tenantUser();
    $this->actingAs($user);
    alfakit()->setCurrentPanel(alfakit()->getPanel('app'));
    alfakit()->setTenant($alpha);

    // A shared owner (the demo's global category listing every store's products), here the
    // Beta team viewed while working in Alpha: Beta's projects must not be listed.
    $page = app(AlfaSix\Alfakit\Tests\Fixtures\Tenancy\ListProjects::class);
    $manager = SharedOwnerProjectsRelationManager::make($beta, $page);
    $names = $manager->getTable()->getQuery()->pluck('name')->all();

    expect($names)->toBe([]);

    alfakit()->setTenant($beta);
    expect(SharedOwnerProjectsRelationManager::make($beta, $page)->getTable()->getQuery()->pluck('name')->all())->toBe(['Beta project']);

    alfakit()->setTenant(null);
});
