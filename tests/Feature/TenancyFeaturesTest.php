<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Forms\Components\FileUpload;
use AlfaSix\Alfakit\Notifications\BroadcastNotification;
use AlfaSix\Alfakit\Notifications\Notification;
use AlfaSix\Alfakit\Pages\Dashboard;
use AlfaSix\Alfakit\Panel;
use AlfaSix\Alfakit\Support\Enums\ToastPosition;
use AlfaSix\Alfakit\Tests\Fixtures\AuthTenancy\FakeBillingProvider;
use AlfaSix\Alfakit\Tests\Fixtures\AuthTenancy\Panels;
use AlfaSix\Alfakit\Tests\Fixtures\AuthTenancy\RequireSubscription;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Project;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Team;
use AlfaSix\Alfakit\Tests\Fixtures\Models\TenantUser;
use AlfaSix\Alfakit\Tests\Fixtures\Tenancy\ProjectResource;
use Illuminate\Support\Facades\Notification as NotificationFacade;

/*
 * F16 / F17: tenant domains, billing, per-tenant notifications and upload folders,
 * realtime notifications and the toast position.
 */

function teamsFixture(): array
{
    $user = TenantUser::query()->create(['name' => 'Ada', 'email' => 'ada'.uniqid().'@example.com', 'password' => 'secret']);
    $alpha = Team::query()->create(['name' => 'Alpha', 'slug' => 'alpha']);
    $beta = Team::query()->create(['name' => 'Beta', 'slug' => 'beta']);
    $other = Team::query()->create(['name' => 'Other', 'slug' => 'other']);
    $user->teams()->attach([$alpha->id, $beta->id]);

    Project::query()->create(['team_id' => $alpha->id, 'name' => 'Alpha project']);
    Project::query()->create(['team_id' => $beta->id, 'name' => 'Beta project']);

    return [$user, $alpha, $beta, $other];
}

it('identifies the tenant from a subdomain', function (): void {
    Panels::register('domains', fn (Panel $panel): Panel => $panel
        ->login()
        ->tenant(Team::class)
        ->tenantDomain('{tenant:slug}.example.test')
        ->resources([ProjectResource::class])
        ->pages([Dashboard::class]));

    [$user] = teamsFixture();
    $this->actingAs($user);

    expect(alfakit()->getPanel('domains')->getTenantSlugAttribute())->toBe('slug')
        ->and(route('alfakit.domains.resources.projects.index', ['tenant' => 'alpha']))->toBe('http://alpha.example.test/domains/projects');

    $props = inertiaProps(inertiaGet($this, 'http://alpha.example.test/domains/projects'));

    expect(array_column(array_column($props['page']['content'][0]['records'], 'cells'), 'name'))->toHaveCount(1)
        ->and($props['page']['content'][0]['records'][0]['cells']['name']['state'])->toBe('Alpha project')
        ->and($props['alfakit']['endpoints']['action'])->toBe('http://alpha.example.test/domains/_alfakit/actions')
        ->and($props['alfakit']['tenancy']['tenants'][1]['url'])->toBe('http://beta.example.test/domains');

    $this->get('http://other.example.test/domains/projects')->assertNotFound();
    $this->get('http://nope.example.test/domains/projects')->assertNotFound();

    // The tenant of an internal request comes from the token, so actions work on the subdomain.
    $token = inertiaProps(inertiaGet($this, 'http://alpha.example.test/domains/projects/create'))['page']['token'];

    inertiaPost($this, 'http://alpha.example.test/domains/_alfakit/actions', ['token' => $token, 'action' => 'create', 'data' => ['name' => 'Subdomain project']])
        ->assertRedirect('http://alpha.example.test/domains/projects');

    expect(Project::query()->where('name', 'Subdomain project')->value('team_id'))->toBe(Team::query()->where('slug', 'alpha')->value('id'));
});

it('supports a custom domain per tenant', function (): void {
    Panels::register('custom', fn (Panel $panel): Panel => $panel
        ->tenant(Team::class)
        ->tenantDomain('{tenant:domain}')
        ->resources([ProjectResource::class]));

    [$user, $alpha, $beta] = teamsFixture();
    $alpha->forceFill(['domain' => 'alpha-corp.test'])->save();
    $beta->forceFill(['domain' => 'beta.example.org'])->save();
    $this->actingAs($user);

    expect(route('alfakit.custom.resources.projects.index', ['tenant' => 'alpha-corp.test']))->toBe('http://alpha-corp.test/custom/projects');

    $props = inertiaProps(inertiaGet($this, 'http://alpha-corp.test/custom/projects'));

    expect($props['page']['content'][0]['records'][0]['cells']['name']['state'])->toBe('Alpha project')
        ->and($props['alfakit']['tenancy']['tenants'][1]['url'])->toBe('http://beta.example.org/custom/projects');
});

it('sends unsubscribed tenants to billing and adds a billing menu item', function (): void {
    Panels::register('billing', fn (Panel $panel): Panel => $panel
        ->tenant(Team::class, slugAttribute: 'slug')
        ->tenantBillingProvider(new FakeBillingProvider)
        ->requiresTenantSubscription()
        ->resources([ProjectResource::class])
        ->pages([Dashboard::class]));

    RequireSubscription::$subscribed = ['alpha'];
    [$user] = teamsFixture();
    $this->actingAs($user);

    $props = inertiaProps(inertiaGet($this, '/billing/alpha/projects'));

    expect(collect($props['alfakit']['tenancy']['menu'])->pluck('url'))->toContain('http://localhost/billing/alpha/billing');

    $this->get('/billing/beta/projects')->assertRedirect('http://localhost/billing/beta/billing');
    $this->get('/billing/beta/billing')->assertOk()->assertSee('portal for beta');

    // Internal requests of an unsubscribed tenant are refused as well.
    RequireSubscription::$subscribed = ['alpha', 'beta'];
    $token = inertiaProps(inertiaGet($this, '/billing/beta/projects/create'))['page']['token'];
    RequireSubscription::$subscribed = ['alpha'];

    $this->postJson('/billing/beta/_alfakit/actions', ['token' => $token, 'action' => 'create', 'data' => ['name' => 'Free ride']])->assertRedirect('http://localhost/billing/beta/billing');
    expect(Project::query()->where('name', 'Free ride')->exists())->toBeFalse();
});

it('keeps database notifications in the tenant they were sent in', function (): void {
    Panels::register('inbox', fn (Panel $panel): Panel => $panel
        ->tenant(Team::class, slugAttribute: 'slug')
        ->databaseNotifications()
        ->pages([Dashboard::class]));

    [$user, $alpha, $beta] = teamsFixture();
    $this->actingAs($user);

    Notification::make()->title('For alpha')->tenant($alpha)->sendToDatabase($user);
    Notification::make()->title('For beta')->tenant($beta)->sendToDatabase($user);
    Notification::make()->title('For everyone')->sendToDatabase($user);

    $titles = fn (string $tenant): array => array_column($this->getJson("/inbox/{$tenant}/_alfakit/notifications")->assertOk()->json('notifications'), 'title');

    expect($titles('alpha'))->toEqualCanonicalizing(['For alpha', 'For everyone'])
        ->and($titles('beta'))->toEqualCanonicalizing(['For beta', 'For everyone'])
        ->and(inertiaProps(inertiaGet($this, '/inbox/alpha'))['alfakit']['databaseNotifications']['unreadCount'])->toBe(2);

    $this->postJson('/inbox/alpha/_alfakit/notifications/read-all')->assertOk();

    expect($user->unreadNotifications()->count())->toBe(1);

    $this->deleteJson('/inbox/beta/_alfakit/notifications')->assertOk();

    expect(array_column($user->notifications()->get()->pluck('data')->all(), 'title'))->toBe(['For alpha']);
});

it('tags notifications sent during a tenant request with that tenant', function (): void {
    $panel = Panels::register('inbox2', fn (Panel $panel): Panel => $panel->tenant(Team::class, slugAttribute: 'slug')->databaseNotifications());
    [$user, $alpha] = teamsFixture();

    alfakit()->setCurrentPanel($panel);
    alfakit()->setTenant($alpha);

    expect(Notification::make()->title('x')->toDatabaseData())->toHaveKey('alfakitTenant')
        ->and(Notification::make()->title('x')->forAllTenants()->toDatabaseData())->not->toHaveKey('alfakitTenant');
});

it('stores uploads of each tenant in its own folder', function (): void {
    $panel = Panels::register('files', fn (Panel $panel): Panel => $panel->tenant(Team::class, slugAttribute: 'slug')->tenantUploadDirectory());
    [, $alpha] = teamsFixture();

    alfakit()->setCurrentPanel($panel);

    expect(FileUpload::make('file')->directory('avatars')->getDirectory())->toBe('avatars');

    alfakit()->setTenant($alpha);

    expect(FileUpload::make('file')->directory('avatars')->getDirectory())->toBe("tenants/{$alpha->id}/avatars")
        ->and(FileUpload::make('file')->getDirectory())->toBe("tenants/{$alpha->id}");

    $panel->tenantUploadDirectory(fn (Team $tenant): string => 'teams/'.$tenant->slug);

    expect(FileUpload::make('file')->directory('docs')->getDirectory())->toBe('teams/alpha/docs');

    $panel->tenantUploadDirectory('../{tenant}');

    expect($panel->getTenantUploadDirectory())->toBeNull();
});

it('shares the realtime channel and toast position with the renderers', function (): void {
    Panels::register('live', fn (Panel $panel): Panel => $panel->realtimeNotifications()->toastPosition(ToastPosition::BottomStart)->pages([Dashboard::class]));
    $user = AlfaSix\Alfakit\Tests\Fixtures\Models\User::query()->create(['name' => 'Li', 'email' => 'li@example.com', 'password' => 'secret']);
    $this->actingAs($user);

    $panel = inertiaProps(inertiaGet($this, '/live'))['alfakit'];

    expect($panel['realtime'])->toBe(['channel' => 'AlfaSix.Alfakit.Tests.Fixtures.Models.User.'.$user->id])
        ->and($panel['toastPosition'])->toBe('bottom-start');

    // The admin panel doesn't opt in and keeps the default position.
    $admin = inertiaProps(inertiaGet($this, '/admin'))['alfakit'];

    expect($admin)->not->toHaveKey('realtime')->not->toHaveKey('toastPosition');

    expect(fn () => Panel::make()->toastPosition('middle'))->toThrow(InvalidArgumentException::class);
});

it('broadcasts notifications and database refreshes', function (): void {
    NotificationFacade::fake();
    $user = AlfaSix\Alfakit\Tests\Fixtures\Models\User::query()->create(['name' => 'Li', 'email' => 'li2@example.com', 'password' => 'secret']);

    Notification::make('n-1')->title('Deployed')->success()->broadcast($user);

    NotificationFacade::assertSentTo($user, BroadcastNotification::class, function (BroadcastNotification $notification) use ($user): bool {
        return $notification->via($user) === ['broadcast']
            && $notification->broadcastType() === BroadcastNotification::TYPE
            && $notification->toBroadcast($user)->data['title'] === 'Deployed'
            && $notification->id === 'n-1';
    });

    Notification::make()->title('Stored')->sendToDatabase($user, isEventDispatched: true);

    NotificationFacade::assertSentTo($user, BroadcastNotification::class, fn (BroadcastNotification $notification): bool => $notification->broadcastType() === BroadcastNotification::DATABASE_NOTIFICATIONS_SENT);
});

it('explains a missing ownership relationship instead of calling a scope of the same name', function (): void {
    Panels::register('ownership', fn (Panel $panel): Panel => $panel->login()->tenant(Team::class, ownershipRelationship: 'team')->resources([TenancyTestMemberResource::class]));
    alfakit()->setCurrentPanel(alfakit()->getPanel('ownership'));
    [$user, $alpha, $beta] = teamsFixture();
    $outsider = TenantUser::query()->create(['name' => 'Bob', 'email' => 'bob'.uniqid().'@example.com', 'password' => 'secret']);
    $outsider->teams()->attach($beta->id);

    expect(fn () => TenancyTestUserResource::scopeEloquentQueryToTenant(TenantUser::query(), $alpha))
        ->toThrow(LogicException::class, 'has no [team] relationship')
        // Through the members relationship (BelongsToMany) only the tenant's users remain.
        ->and(TenancyTestMemberResource::scopeEloquentQueryToTenant(TenantUser::query(), $alpha)->pluck('id')->all())->toBe([$user->id]);
});

class TenancyTestUserResource extends AlfaSix\Alfakit\Resources\Resource
{
    protected static ?string $model = TenantUser::class;
}

class TenancyTestMemberResource extends AlfaSix\Alfakit\Resources\Resource
{
    protected static ?string $model = TenantUser::class;

    protected static ?string $tenantOwnershipRelationshipName = 'teams';
}

it('sends an impersonated user to one of their own tenants, not the impersonator\'s', function (): void {
    $panel = Panels::register('imptenants', fn (Panel $panel): Panel => $panel->login()->tenant(Team::class)->resources([ProjectResource::class])->pages([Dashboard::class]));
    [$admin, $alpha, $beta] = teamsFixture();
    $member = TenancyImpersonatableUser::query()->create(['name' => 'Mia', 'email' => 'mia'.uniqid().'@example.com', 'password' => 'secret']);
    $member->teams()->attach($beta->id);
    $impersonator = TenancyImpersonatableUser::query()->findOrFail($admin->getKey());
    $this->actingAs($impersonator);

    // The admin is working in Alpha, which the member doesn't belong to.
    alfakit()->setCurrentPanel($panel);
    alfakit()->setTenant($alpha);

    expect(AlfaSix\Alfakit\Auth\Impersonation\Impersonation::start($panel, $member)->getTargetUrl())
        ->toBe('http://localhost/imptenants/'.$beta->getKey());
});

class TenancyImpersonatableUser extends TenantUser implements AlfaSix\Alfakit\Contracts\CanImpersonate
{
    public function canImpersonate(): bool
    {
        return true;
    }
}
