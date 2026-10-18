<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Panel;
use AlfaSix\Alfakit\Tests\Fixtures\AuthTenancy\FakeBillingProvider;
use AlfaSix\Alfakit\Tests\Fixtures\AuthTenancy\Panels;
use AlfaSix\Alfakit\Tests\Fixtures\AuthTenancy\RequireSubscription;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Team;
use AlfaSix\Alfakit\Tests\Fixtures\Models\TenantUser;
use AlfaSix\Alfakit\Tests\Fixtures\Parity3\Tenancy\P3FreePage;
use AlfaSix\Alfakit\Tests\Fixtures\Parity3\Tenancy\P3LabelledTeam;
use AlfaSix\Alfakit\Tests\Fixtures\Parity3\Tenancy\P3PaidPage;
use AlfaSix\Alfakit\Tests\Fixtures\Parity3\Tenancy\P3ProjectResource;

function p3TenantUser(): TenantUser
{
    $user = TenantUser::query()->create(['name' => 'Ada', 'email' => 'ada'.uniqid().'@example.com', 'password' => 'secret']);
    $alpha = Team::query()->create(['name' => 'Alpha', 'slug' => 'alpha']);
    $beta = Team::query()->create(['name' => 'Beta', 'slug' => 'beta']);
    $user->teams()->attach([$alpha->id, $beta->id]);

    return $user;
}

it('exempts or requires a subscription per page', function (): void {
    Panels::register('p3billing', fn (Panel $panel): Panel => $panel
        ->tenant(Team::class, slugAttribute: 'slug')
        ->tenantBillingProvider(new FakeBillingProvider)
        ->requiresTenantSubscription()
        ->pages([P3FreePage::class]));

    Panels::register('p3optional', fn (Panel $panel): Panel => $panel
        ->tenant(Team::class, slugAttribute: 'slug')
        ->tenantBillingProvider(new FakeBillingProvider)
        ->pages([P3PaidPage::class]));

    RequireSubscription::$subscribed = ['alpha'];
    $this->actingAs(p3TenantUser());

    // The panel requires a subscription, the free page doesn't.
    inertiaGet($this, '/p3billing/beta/free')->assertOk();

    // The panel doesn't, the paid page does.
    inertiaGet($this, '/p3optional/alpha/paid')->assertOk();
    $this->get('/p3optional/beta/paid')->assertRedirect('http://localhost/p3optional/beta/billing');
});

it('shows HasCurrentTenantLabel as a caption, keeping the tenant name', function (): void {
    Panels::register('p3label', fn (Panel $panel): Panel => $panel
        ->tenant(P3LabelledTeam::class, slugAttribute: 'slug')
        ->pages([P3FreePage::class]));

    $this->actingAs(p3TenantUser());

    $tenancy = inertiaProps(inertiaGet($this, '/p3label/alpha/free'))['alfakit']['tenancy'];

    expect($tenancy['current']['name'])->toBe('Alpha')
        ->and($tenancy['current']['label'])->toBe('Active team')
        ->and(alfakit()->getPanel('p3label')->getTenantLabel(P3LabelledTeam::query()->firstOrFail()))->toBe('Alpha');
});

it('prefixes tenant routes with tenantRoutePrefix()', function (): void {
    $panel = Panels::register('p3prefix', fn (Panel $panel): Panel => $panel
        ->tenant(Team::class, slugAttribute: 'slug')
        ->tenantRoutePrefix('team')
        ->pages([P3FreePage::class]));

    $this->actingAs(p3TenantUser());

    inertiaGet($this, '/p3prefix/team/alpha/free')->assertOk();
    $this->get('/p3prefix/alpha/free')->assertNotFound();

    expect($panel->route('pages.free', ['tenant' => Team::query()->where('slug', 'alpha')->firstOrFail()]))->toBe('http://localhost/p3prefix/team/alpha/free');
});

it('creates records through $tenantRelationshipName', function (): void {
    $team = Team::query()->create(['name' => 'Gamma', 'slug' => 'gamma']);

    expect(P3ProjectResource::getTenantRelationship($team))->toBeInstanceOf(Illuminate\Database\Eloquent\Relations\HasMany::class)
        ->and(AlfaSix\Alfakit\Tests\Fixtures\Tenancy\ProjectResource::getTenantRelationship($team))->toBeNull()
        ->and(P3ProjectResource::getTenantRelationshipName())->toBe('projects');
});
