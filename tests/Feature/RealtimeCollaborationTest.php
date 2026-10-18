<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Notifications\BroadcastNotification;
use AlfaSix\Alfakit\Notifications\DatabaseNotification;
use AlfaSix\Alfakit\Panel;
use AlfaSix\Alfakit\Realtime\ModelChanges;
use AlfaSix\Alfakit\Realtime\Realtime;
use AlfaSix\Alfakit\Realtime\RealtimeChannels;
use AlfaSix\Alfakit\Realtime\RecordChanged;
use AlfaSix\Alfakit\Realtime\RecordVersion;
use AlfaSix\Alfakit\Tests\Fixtures\AuthTenancy\Panels;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Post;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Project;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Team;
use AlfaSix\Alfakit\Tests\Fixtures\Models\TenantUser;
use AlfaSix\Alfakit\Tests\Fixtures\Realtime\DenyingPostPolicy;
use AlfaSix\Alfakit\Tests\Fixtures\Realtime\LivePostResource;
use AlfaSix\Alfakit\Tests\Fixtures\Realtime\LiveProjectResource;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Illuminate\Support\Facades\Route;

/*
 * Real-time collaboration (`Panel::realtime()`): channel authorisation (policies, tenant
 * isolation), key-only change broadcasts, live tables, presence and optimistic
 * concurrency on Edit pages (which works without realtime too).
 */

const LIVE_POST_SEGMENT = 'AlfaSix-Alfakit-Tests-Fixtures-Models-Post';

beforeEach(function (): void {
    // A Pusher-protocol connection signs channel auth locally (no server is contacted).
    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb' => [
            'driver' => 'reverb',
            'key' => 'test-key',
            'secret' => 'test-secret',
            'app_id' => '1001',
            'options' => ['host' => 'localhost', 'port' => 8080, 'scheme' => 'http', 'useTLS' => false],
        ],
    ]);

    // Changes are broadcast through the (synchronous) queue: record them instead.
    Event::fake([RecordChanged::class]);

    Realtime::flush();
});

function livePanel(?Closure $configure = null): Panel
{
    $panel = Panels::register('live', function (Panel $panel) use ($configure): Panel {
        $panel->realtime()->resources([LivePostResource::class]);

        return $configure === null ? $panel : $configure($panel);
    });

    Realtime::bootPanel($panel);

    return $panel;
}

function liveTeamsPanel(): Panel
{
    $panel = Panels::register('teams', fn (Panel $panel): Panel => $panel
        ->realtime()
        ->tenant(Team::class, slugAttribute: 'slug')
        ->resources([LiveProjectResource::class]));

    Realtime::bootPanel($panel);

    return $panel;
}

function authorizeChannel(mixed $test, string $url, string $channel): Illuminate\Testing\TestResponse
{
    return $test->postJson($url, ['socket_id' => '1234.5678', 'channel_name' => $channel]);
}

it('registers nothing while realtime is off', function (): void {
    $user = admin();
    $post = Post::query()->create(['title' => 'Old', 'slug' => 'old', 'author_id' => $user->id]);

    $props = inertiaProps(inertiaGet($this->actingAs($user), "/admin/posts/{$post->id}/edit"));

    expect(Route::has('alfakit.admin.internal.realtime.auth'))->toBeFalse()
        ->and(RealtimeChannels::isRegistered('admin'))->toBeFalse()
        ->and(ModelChanges::isObserved(Post::class))->toBeFalse()
        ->and(app(BroadcastManager::class)->connection()->getChannels()->keys()->filter(fn (string $name): bool => str_starts_with($name, 'alfakit.'))->all())->toBe([])
        ->and($props['page'])->not->toHaveKey('presence')
        ->and($props['alfakit'] ?? [])->not->toHaveKey('realtime');

    $post->update(['title' => 'New']);

    Event::assertNotDispatched(RecordChanged::class);
});

it('shares the presence channel, the record version and the connection settings on record pages', function (): void {
    livePanel();
    $user = admin();
    $post = Post::query()->create(['title' => 'Old', 'slug' => 'old', 'author_id' => $user->id]);

    $edit = inertiaProps(inertiaGet($this->actingAs($user), "/live/live-posts/{$post->id}/edit"));
    $view = inertiaProps(inertiaGet($this, "/live/live-posts/{$post->id}"));

    expect($edit['page']['presence'])->toBe(['channel' => 'alfakit.live._.'.LIVE_POST_SEGMENT.'.'.$post->id, 'state' => 'editing'])
        ->and($edit['page']['conflicts']['version'])->toBe(RecordVersion::of($post->fresh()))
        ->and($view['page']['presence']['state'])->toBe('viewing')
        ->and($view['page'])->not->toHaveKey('conflicts')
        ->and($edit['alfakit']['realtime']['live'])->toBeTrue()
        ->and($edit['alfakit']['realtime']['authEndpoint'])->toBe('http://localhost/live/_alfakit/broadcasting/auth')
        ->and($edit['alfakit']['endpoints']['conflicts'])->toBe('http://localhost/live/_alfakit/conflicts');
});

it('authorises presence channels with the record policy and shares the member name', function (): void {
    livePanel();
    $user = admin();
    $post = Post::query()->create(['title' => 'Old', 'slug' => 'old', 'author_id' => $user->id]);
    $channel = 'presence-alfakit.live._.'.LIVE_POST_SEGMENT.'.'.$post->id;

    $response = authorizeChannel($this->actingAs($user), '/live/_alfakit/broadcasting/auth', $channel)->assertOk();

    expect(json_decode((string) $response->json('channel_data'), true))->toMatchArray([
        'user_id' => $user->id,
        'user_info' => ['id' => $user->id, 'name' => 'Admin', 'avatar' => null],
    ])->and($response->json('auth'))->toStartWith('test-key:');

    authorizeChannel($this, '/live/_alfakit/broadcasting/auth', 'private-alfakit.live._.'.LIVE_POST_SEGMENT)->assertOk();

    // Unknown records, other panels and malformed model segments are refused.
    authorizeChannel($this, '/live/_alfakit/broadcasting/auth', 'presence-alfakit.live._.'.LIVE_POST_SEGMENT.'.999')->assertForbidden();
    authorizeChannel($this, '/live/_alfakit/broadcasting/auth', 'presence-alfakit.admin._.'.LIVE_POST_SEGMENT.'.'.$post->id)->assertForbidden();
    authorizeChannel($this, '/live/_alfakit/broadcasting/auth', 'private-alfakit.live._.Illuminate-Support-Str')->assertForbidden();
});

it('denies channels when the policy denies viewing the record', function (): void {
    livePanel();
    Gate::policy(Post::class, DenyingPostPolicy::class);
    $user = admin();
    $post = Post::query()->create(['title' => 'Old', 'slug' => 'old', 'author_id' => $user->id]);

    authorizeChannel($this->actingAs($user), '/live/_alfakit/broadcasting/auth', 'presence-alfakit.live._.'.LIVE_POST_SEGMENT.'.'.$post->id)->assertForbidden();
    authorizeChannel($this, '/live/_alfakit/broadcasting/auth', 'private-alfakit.live._.'.LIVE_POST_SEGMENT)->assertForbidden();
});

it('keeps tenants apart: channels name the tenant and membership is checked', function (): void {
    liveTeamsPanel();
    $user = TenantUser::query()->create(['name' => 'Dana', 'email' => 'dana@example.com', 'password' => 'password']);
    $mine = Team::query()->create(['name' => 'Mine', 'slug' => 'mine']);
    $other = Team::query()->create(['name' => 'Other', 'slug' => 'other']);
    $user->teams()->attach($mine);
    $project = Project::query()->create(['team_id' => $mine->id, 'name' => 'Ours']);
    $foreign = Project::query()->create(['team_id' => $other->id, 'name' => 'Theirs']);
    $segment = 'AlfaSix-Alfakit-Tests-Fixtures-Models-Project';
    $url = '/teams/_alfakit/broadcasting/auth';

    $this->actingAs($user);

    authorizeChannel($this, $url, "private-alfakit.teams.{$mine->id}.{$segment}")->assertOk();
    authorizeChannel($this, $url, "presence-alfakit.teams.{$mine->id}.{$segment}.{$project->id}")->assertOk();

    // Another tenant's channels, another tenant's record under my tenant, or no tenant at all.
    authorizeChannel($this, $url, "private-alfakit.teams.{$other->id}.{$segment}")->assertForbidden();
    authorizeChannel($this, $url, "presence-alfakit.teams.{$other->id}.{$segment}.{$foreign->id}")->assertForbidden();
    authorizeChannel($this, $url, "presence-alfakit.teams.{$mine->id}.{$segment}.{$foreign->id}")->assertForbidden();
    authorizeChannel($this, $url, "private-alfakit.teams._.{$segment}")->assertForbidden();

    $props = inertiaProps(inertiaGet($this, '/teams/mine/projects'));

    expect($props['page']['content'][0]['live']['channels'])->toBe(["alfakit.teams.{$mine->id}.{$segment}"]);
});

it('broadcasts keys only, never attributes, to the model and record channels', function (): void {
    livePanel();
    $user = admin();

    $post = Post::query()->create(['title' => 'Secret title', 'slug' => 'secret', 'author_id' => $user->id, 'content' => 'Confidential']);
    $post->update(['title' => 'Still secret']);
    $post->delete();

    $events = [];

    Event::assertDispatched(RecordChanged::class, function (RecordChanged $event) use (&$events): bool {
        $events[] = $event;

        return true;
    });

    expect(array_map(fn (RecordChanged $event): string => $event->broadcastWith()['event'], $events))->toBe(['created', 'updated', 'deleted']);

    foreach ($events as $event) {
        $payload = $event->broadcastWith();

        expect(array_keys($payload))->toBe(['event', 'model', 'key', 'by', 'at'])
            ->and($payload['key'])->toBe($post->id)
            ->and($payload['model'])->toBe(LIVE_POST_SEGMENT)
            ->and(json_encode($payload))->not->toContain('secret')->not->toContain('Confidential')
            ->and($event->broadcastAs())->toBe('alfakit.record')
            ->and(array_map(fn ($channel): string => $channel->name, $event->broadcastOn()))->toBe([
                'private-alfakit.live._.'.LIVE_POST_SEGMENT,
                'presence-alfakit.live._.'.LIVE_POST_SEGMENT.'.'.$post->id,
            ]);
    }
});

it('serves live table channels, and only fallback polling without realtime', function (): void {
    livePanel();
    $user = admin();

    $props = inertiaProps(inertiaGet($this->actingAs($user), '/live/live-posts'));

    expect($props['page']['content'][0]['live'])->toBe([
        'channels' => ['alfakit.live._.'.LIVE_POST_SEGMENT],
        'debounce' => 250,
        'fallbackPollingInterval' => '30s',
        'newRecordsIndicator' => true,
    ]);

    Panels::register('quiet', fn (Panel $panel): Panel => $panel->resources([LivePostResource::class]));

    $quiet = inertiaProps(inertiaGet($this, '/quiet/live-posts'));

    expect($quiet['page']['content'][0]['live']['channels'])->toBe([]);
});

it('pushes stored database notifications to the bell', function (): void {
    livePanel();
    $user = admin();

    NotificationFacade::fake();

    event(new NotificationSent($user, new DatabaseNotification(['title' => 'Hi']), 'database'));

    NotificationFacade::assertSentTo($user, BroadcastNotification::class, fn (BroadcastNotification $notification): bool => $notification->broadcastType() === BroadcastNotification::DATABASE_NOTIFICATIONS_SENT);
});

it('refuses to save over changes saved since the form was loaded', function (): void {
    $user = admin();
    $post = Post::query()->create(['title' => 'Old', 'slug' => 'old', 'author_id' => $user->id]);
    $props = inertiaProps(inertiaGet($this->actingAs($user), "/admin/posts/{$post->id}/edit"));
    $version = $props['page']['conflicts']['version'];
    $data = ['title' => 'Mine', 'slug' => 'old', 'author_id' => $user->id, 'tags' => [], 'is_published' => false, 'comments' => []];

    // Somebody else saves meanwhile.
    $post->update(['title' => 'Theirs']);

    inertiaPost($this, '/admin/_alfakit/actions', ['token' => $props['page']['token'], 'action' => 'save', 'recordVersion' => $version, 'data' => $data])
        ->assertStatus(422)
        ->assertJsonValidationErrors([RecordVersion::CONFLICT_ERROR]);

    expect($post->fresh()->title)->toBe('Theirs');

    // What changed, against the user's unsaved values.
    $conflict = $this->postJson('/admin/_alfakit/conflicts', [
        'token' => $props['page']['token'],
        'original' => $props['page']['content'][0]['state'],
        'state' => [...$props['page']['content'][0]['state'], 'title' => 'Mine'],
    ])->assertOk()->json();

    expect($conflict['version'])->toBe(RecordVersion::of($post->fresh()))
        ->and($conflict['fields'])->toHaveCount(1)
        ->and($conflict['fields'][0])->toMatchArray(['path' => 'title', 'label' => 'Title', 'theirs' => 'Theirs', 'mine' => 'Mine', 'value' => 'Theirs', 'conflicting' => true]);

    // "Keep mine": saving with the current version overwrites.
    inertiaPost($this, '/admin/_alfakit/actions', ['token' => $props['page']['token'], 'action' => 'save', 'recordVersion' => $conflict['version'], 'data' => $data])
        ->assertRedirect();

    expect($post->fresh()->title)->toBe('Mine');
});

it('saves without checking when conflict detection is off', function (): void {
    Panels::register('loose', fn (Panel $panel): Panel => $panel->detectConflicts(false)->resources([LivePostResource::class]));
    $user = admin();
    $post = Post::query()->create(['title' => 'Old', 'slug' => 'old', 'author_id' => $user->id]);
    $props = inertiaProps(inertiaGet($this->actingAs($user), "/loose/live-posts/{$post->id}/edit"));

    expect($props['page'])->not->toHaveKey('conflicts');

    $post->update(['title' => 'Theirs']);

    inertiaPost($this, '/loose/_alfakit/actions', ['token' => $props['page']['token'], 'action' => 'save', 'recordVersion' => 'stale', 'data' => ['title' => 'Mine', 'slug' => 'old']])
        ->assertRedirect();

    expect($post->fresh()->title)->toBe('Mine');

    $this->postJson('/loose/_alfakit/conflicts', ['token' => $props['page']['token']])->assertNotFound();
});
