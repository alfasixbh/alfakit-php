<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Panel;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Post;
use AlfaSix\Alfakit\Tests\Fixtures\Models\User;
use AlfaSix\Alfakit\Tests\Fixtures\Parity3\Discovery\CustomLogin;
use AlfaSix\Alfakit\Tests\Fixtures\Parity3\Discovery\GateOnlyResource;
use AlfaSix\Alfakit\Tests\Fixtures\Parity3\Discovery\GateOnlyResource as GateResource;
use AlfaSix\Alfakit\Tests\Fixtures\Parity3\Discovery\HiddenDiscoveryPage;
use AlfaSix\Alfakit\Tests\Fixtures\Parity3\Discovery\HiddenDiscoveryResource;
use AlfaSix\Alfakit\Tests\Fixtures\Parity3\Discovery\HiddenDiscoveryWidget;
use AlfaSix\Alfakit\Tests\Fixtures\Parity3\Discovery\ShownDiscoveryPage;
use AlfaSix\Alfakit\Tests\Fixtures\Parity3\Discovery\ShownDiscoveryResource;
use AlfaSix\Alfakit\Tests\Fixtures\Parity3\Discovery\ShownDiscoveryWidget;
use Illuminate\Support\Facades\Gate;

function parity3DiscoveryPanel(): Panel
{
    $directory = dirname(__DIR__).'/Fixtures/Parity3/Discovery';
    $namespace = 'AlfaSix\\Alfakit\\Tests\\Fixtures\\Parity3\\Discovery';

    return Panel::make('p3-discovery')
        ->discoverResources(in: $directory, for: $namespace)
        ->discoverPages(in: $directory, for: $namespace)
        ->discoverWidgets(in: $directory, for: $namespace);
}

it('skips resources, pages and widgets with $isDiscovered = false', function (): void {
    $panel = parity3DiscoveryPanel();

    expect($panel->getResources())->toContain(ShownDiscoveryResource::class)
        ->not->toContain(HiddenDiscoveryResource::class)
        ->not->toContain(GateOnlyResource::class)
        ->and($panel->getPages())->toContain(ShownDiscoveryPage::class)->not->toContain(HiddenDiscoveryPage::class)
        ->and($panel->getWidgets())->toContain(ShownDiscoveryWidget::class)->not->toContain(HiddenDiscoveryWidget::class)
        ->and($panel->getDiscoverableComponents()['widgets'])->not->toContain(HiddenDiscoveryWidget::class);
});

it('does not discover a custom auth page as a panel page (W-S1-15)', function (): void {
    expect(parity3DiscoveryPanel()->login(CustomLogin::class)->getPages())->not->toContain(CustomLogin::class);
});

it('still registers undiscovered classes registered by hand', function (): void {
    $panel = parity3DiscoveryPanel()->resources([HiddenDiscoveryResource::class])->widgets([HiddenDiscoveryWidget::class]);

    expect($panel->getResources())->toContain(HiddenDiscoveryResource::class)
        ->and($panel->getWidgets())->toContain(HiddenDiscoveryWidget::class);
});

it('allows abilities without a policy when the resource checks policy existence', function (): void {
    $this->actingAs(User::query()->create(['name' => 'P3', 'email' => 'p3'.uniqid().'@example.com', 'password' => 'password']));

    expect(ShownDiscoveryResource::shouldCheckPolicyExistence())->toBeTrue()
        ->and(ShownDiscoveryResource::canViewAny())->toBeTrue();
});

it('sends every check to the Gate when $shouldCheckPolicyExistence is false', function (): void {
    $user = User::query()->create(['name' => 'P3', 'email' => 'p3'.uniqid().'@example.com', 'password' => 'password']);
    $this->actingAs($user);

    expect(GateResource::shouldCheckPolicyExistence())->toBeFalse()
        ->and(GateResource::canViewAny())->toBeFalse()
        ->and(GateResource::canCreate())->toBeFalse();

    Gate::define('viewAny', fn (User $user, string $model): bool => $model === Post::class);

    expect(GateResource::canViewAny())->toBeTrue()
        ->and(GateResource::canCreate())->toBeFalse();

    Gate::before(fn (User $user, string $ability): ?bool => $ability === 'create' ? true : null);

    expect(GateResource::canCreate())->toBeTrue();
});

it('reads the navigation badge tooltip from the resource property', function (): void {
    expect(GateResource::getNavigationBadgeTooltip())->toBe('Unread posts')
        ->and(ShownDiscoveryResource::getNavigationBadgeTooltip())->toBeNull();
});
