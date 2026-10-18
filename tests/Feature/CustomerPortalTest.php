<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Auth\Notifications\RegistrationInvitation;
use AlfaSix\Alfakit\Panel;
use AlfaSix\Alfakit\Tests\Fixtures\AuthTenancy\Panels;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Post;
use AlfaSix\Alfakit\Tests\Fixtures\Models\User;
use AlfaSix\Alfakit\Tests\Fixtures\Panel\Resources\PostResource;
use AlfaSix\Alfakit\Tests\Fixtures\Portal\MyPostResource;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Illuminate\Support\Str;

/*
 * Customer portals: the `portal()` preset, invitation-only registration and record
 * scoping to the signed-in user (`scopeToUser()`).
 */

afterEach(function (): void {
    MyPostResource::scopeToUser(null);
});

function portalPanel(?Closure $configure = null): Panel
{
    return Panels::register('portal', function (Panel $panel) use ($configure): Panel {
        $panel->portal()->resources([MyPostResource::class])->scopeToUser('author_id');

        return $configure === null ? $panel : $configure($panel);
    });
}

function portalUser(string $name = 'Cora'): User
{
    return User::query()->create(['name' => $name, 'email' => Str::lower($name).uniqid().'@example.com', 'password' => 'password']);
}

function portalPost(User $author, string $title): Post
{
    return Post::query()->create(['title' => $title, 'slug' => Str::slug($title).uniqid(), 'author_id' => $author->id]);
}

it('sets up simple, customer-facing chrome with the portal preset', function (): void {
    $panel = portalPanel();

    expect($panel->isPortal())->toBeTrue()
        ->and((fn (): bool => $this->hasTopNavigation)->call($panel))->toBeTrue()
        ->and($panel->hasGlobalSearch())->toBeFalse()
        ->and($panel->hasCommandPalette())->toBeFalse()
        ->and($panel->hasBreadcrumbs())->toBeFalse()
        ->and($panel->hasLogin())->toBeTrue()
        ->and($panel->hasPasswordReset())->toBeTrue()
        ->and($panel->hasProfile())->toBeTrue()
        ->and($panel->isProfilePageSimple())->toBeFalse()
        ->and($panel->hasCredit())->toBeFalse()
        ->and($panel->getMaxContentWidth())->toBe('6xl');

    // Every option can still be changed afterwards.
    expect(Panel::make('other')->portal()->breadcrumbs()->hasBreadcrumbs())->toBeTrue();
});

it('only lists, opens and edits the signed-in user\'s own records', function (): void {
    portalPanel();
    $cora = portalUser();
    $dan = portalUser('Dan');
    $mine = portalPost($cora, 'Mine');
    $theirs = portalPost($dan, 'Theirs');

    $props = inertiaProps(inertiaGet($this->actingAs($cora), '/portal/my-posts'));
    $titles = array_column(array_column(array_column($props['page']['content'][0]['records'], 'cells'), 'title'), 'state');

    expect($titles)->toBe(['Mine']);

    inertiaGet($this, "/portal/my-posts/{$mine->id}/edit")->assertOk();
    inertiaGet($this, "/portal/my-posts/{$theirs->id}/edit")->assertNotFound();
});

it('makes the signed-in user the owner of new records', function (): void {
    portalPanel();
    $cora = portalUser();

    $props = inertiaProps(inertiaGet($this->actingAs($cora), '/portal/my-posts/create'));

    inertiaPost($this, '/portal/_alfakit/actions', ['token' => $props['page']['token'], 'action' => 'create', 'data' => ['title' => 'Fresh', 'slug' => 'fresh']])
        ->assertRedirect();

    expect(Post::query()->where('slug', 'fresh')->value('author_id'))->toBe($cora->id);
});

it('scopes by a relationship, with an owner key resolver, per resource or per registration', function (): void {
    $cora = portalUser();
    $dan = portalUser('Dan');
    portalPost($cora, 'Cora post');
    portalPost($dan, 'Dan post');

    // A relationship of the model, and the owner key from a closure (e.g. the user's customer).
    portalPanel(fn (Panel $panel) => $panel->scopeToUser('author', fn (User $user): int => $dan->id));

    $titles = fn (): array => array_column(array_column(array_column(inertiaProps(inertiaGet($this->actingAs($cora), '/portal/my-posts'))['page']['content'][0]['records'], 'cells'), 'title'), 'state');

    expect($titles())->toBe(['Dan post']);

    // The resource opts out of the panel's scope.
    MyPostResource::scopeToUser(false);

    expect($titles())->toHaveCount(2);

    // A registration's own scope wins.
    expect(MyPostResource::make('mine')->scopeToUser('author_id')->getUserOwnership())->toBe('author_id');
});

it('shows nothing to a user without an owner record and never scopes unscoped panels', function (): void {
    portalPanel(fn (Panel $panel) => $panel->scopeToUser('author_id', fn (): ?int => null));
    $cora = portalUser();
    portalPost($cora, 'Mine');

    $records = inertiaProps(inertiaGet($this->actingAs($cora), '/portal/my-posts'))['page']['content'][0]['records'];

    expect($records)->toBe([])
        ->and(PostResource::getUserOwnership())->toBeNull();
});

it('lets only invited addresses register on invitation-only portals', function (): void {
    NotificationFacade::fake();
    $panel = portalPanel(fn (Panel $panel) => $panel->invitationOnlyRegistration());

    expect($panel->hasRegistration())->toBeTrue()
        ->and($panel->hasInvitationOnlyRegistration())->toBeTrue();

    $this->get('/portal/register')->assertRedirect('http://localhost/portal/login');

    $login = inertiaProps(inertiaGet($this, '/portal/login'));

    expect(json_encode($login['page']['content']))->not->toContain('/portal/register');

    $url = $panel->inviteToRegister('guest@example.com');

    NotificationFacade::assertSentOnDemand(RegistrationInvitation::class);
    expect($url)->toStartWith('http://localhost/portal/register?');

    $page = inertiaProps(inertiaGet($this, $url))['page'];

    expect($page['content'][0]['state']['email'])->toBe('guest@example.com');

    // The address is read-only: another one sent by hand is replaced by the invited one.
    inertiaPost($this, '/portal/_alfakit/actions', ['token' => $page['token'], 'action' => 'register', 'data' => ['name' => 'Gus', 'email' => 'other@example.com', 'password' => 'secret-pass-1', 'password_confirmation' => 'secret-pass-1']])
        ->assertRedirect();

    expect(User::query()->where('email', 'other@example.com')->exists())->toBeFalse()
        ->and(User::query()->where('email', 'guest@example.com')->exists())->toBeTrue();
    $this->assertAuthenticated();
});
