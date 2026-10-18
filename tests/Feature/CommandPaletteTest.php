<?php

declare(strict_types=1);

use AlfaSix\Alfakit\CommandPalette\Command;
use AlfaSix\Alfakit\CommandPalette\KeyboardShortcuts;
use AlfaSix\Alfakit\CommandPalette\RecentRecords;
use AlfaSix\Alfakit\CommandPalette\UserPreferences;
use AlfaSix\Alfakit\Pages\Dashboard;
use AlfaSix\Alfakit\Panel;
use AlfaSix\Alfakit\Tests\Fixtures\AuthTenancy\Panels;
use AlfaSix\Alfakit\Tests\Fixtures\CommandPalette\PalettePostPolicy;
use AlfaSix\Alfakit\Tests\Fixtures\CommandPalette\VersionedPost;
use AlfaSix\Alfakit\Tests\Fixtures\CommandPalette\VersionedPostResource;
use AlfaSix\Alfakit\Tests\Fixtures\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/*
 * Command palette 2.0: panel commands (run through the action endpoint), "create" links,
 * recent records (re-authorised on read) and per-user keyboard shortcut preferences.
 */

/** @var array<int, string> */
$GLOBALS['paletteRuns'] = [];

function palettePanel(string $id = 'palette', ?Closure $configure = null): Panel
{
    return Panels::register($id, function (Panel $panel) use ($configure): Panel {
        $panel = $panel
            ->login()
            ->globalSearch()
            ->resources([VersionedPostResource::class])
            ->pages([Dashboard::class])
            ->commands([
                Command::make('clear-cache')
                    ->label('Clear cache')
                    ->keywords(['flush', 'reset'])
                    ->group('Maintenance')
                    ->description('Empties the application cache')
                    ->shortcut('mod+shift+x')
                    ->action(function (): void {
                        $GLOBALS['paletteRuns'][] = 'clear-cache';
                    }),
                Command::make('secret')->hidden()->action(function (): void {
                    $GLOBALS['paletteRuns'][] = 'secret';
                }),
                // Unauthorised commands are hidden (and refused by the endpoint).
                Command::make('forbidden')->authorize(false)->action(function (): void {
                    $GLOBALS['paletteRuns'][] = 'forbidden';
                }),
                Command::make('docs')->label('Docs')->url('https://alfakit.dev')->shortcut('g h'),
            ]);

        return $configure !== null ? $configure($panel) : $panel;
    });
}

function paletteUser(string $name = 'Ada'): User
{
    return User::query()->create(['name' => $name, 'email' => strtolower($name).uniqid().'@example.com', 'password' => 'secret']);
}

function palettePost(User $author, array $attributes = []): VersionedPost
{
    return VersionedPost::query()->create([...['title' => 'Hello', 'slug' => 'hello-'.uniqid(), 'is_published' => false, 'author_id' => $author->getKey()], ...$attributes]);
}

beforeEach(function (): void {
    $GLOBALS['paletteRuns'] = [];
    PalettePostPolicy::$canCreate = true;
    UserPreferences::flush();
    Gate::policy(VersionedPost::class, PalettePostPolicy::class);
    (require dirname(__DIR__, 2).'/database/migrations/0001_01_01_000300_create_alfakit_record_versions_table.php')->up();
});

afterEach(fn () => UserPreferences::flush());

// ---------------------------------------------------------------- Commands

it('shares visible panel commands with their keywords, group and shortcut', function (): void {
    palettePanel();
    $this->actingAs(paletteUser());

    $props = inertiaProps(inertiaGet($this, '/palette'));
    $commands = collect($props['alfakit']['commandPalette']['commands'])->keyBy('name');

    expect($commands->keys()->all())->toBe(['clear-cache', 'docs'])
        ->and($commands['clear-cache']['keywords'])->toBe(['flush', 'reset'])
        ->and($commands['clear-cache']['group'])->toBe('Maintenance')
        ->and($commands['clear-cache']['description'])->toBe('Empties the application cache')
        ->and($commands['clear-cache']['keyBindings'])->toBe(['mod+shift+x'])
        ->and($commands['docs']['url'])->toBe('https://alfakit.dev')
        ->and($props['alfakit']['endpoints']['commandPalette'])->toBe('http://localhost/palette/_alfakit/command-palette')
        ->and($props['alfakit']['endpoints']['shortcuts'])->toBe('http://localhost/palette/_alfakit/shortcuts')
        ->and($props['alfakit']['keyboardShortcuts'])->toBe(['customizable' => true]);
});

it('runs commands through the action endpoint with the action authorisation', function (): void {
    palettePanel();
    $this->actingAs(paletteUser());
    $token = inertiaProps(inertiaGet($this, '/palette'))['page']['token'];

    inertiaPost($this, '/palette/_alfakit/actions', ['token' => $token, 'component' => Panel::COMMANDS_COMPONENT, 'action' => 'clear-cache'])->assertRedirect();
    inertiaPost($this, '/palette/_alfakit/actions', ['token' => $token, 'component' => Panel::COMMANDS_COMPONENT, 'action' => 'secret'])->assertForbidden();
    inertiaPost($this, '/palette/_alfakit/actions', ['token' => $token, 'component' => Panel::COMMANDS_COMPONENT, 'action' => 'forbidden'])->assertForbidden();
    // Links navigate in the browser; they are never executed.
    inertiaPost($this, '/palette/_alfakit/actions', ['token' => $token, 'component' => Panel::COMMANDS_COMPONENT, 'action' => 'docs'])->assertNotFound();
    inertiaPost($this, '/palette/_alfakit/actions', ['token' => $token, 'component' => Panel::COMMANDS_COMPONENT, 'action' => 'missing'])->assertNotFound();

    expect($GLOBALS['paletteRuns'])->toBe(['clear-cache']);
});

it('refuses commands to other users tokens and guests', function (): void {
    palettePanel();
    $this->actingAs(paletteUser());
    $token = inertiaProps(inertiaGet($this, '/palette'))['page']['token'];

    $this->actingAs(paletteUser('Bob'));
    inertiaPost($this, '/palette/_alfakit/actions', ['token' => $token, 'component' => Panel::COMMANDS_COMPONENT, 'action' => 'clear-cache'])->assertForbidden();

    expect($GLOBALS['paletteRuns'])->toBe([]);
});

it('turns the command palette off', function (): void {
    palettePanel('palette-off', fn (Panel $panel): Panel => $panel->commandPalette(false)->keyboardShortcuts(false));
    $this->actingAs(paletteUser());

    $props = inertiaProps(inertiaGet($this, '/palette-off'));

    expect($props['alfakit'])->not->toHaveKey('commandPalette')
        ->and($props['alfakit']['keyboardShortcuts'])->toBeFalse();

    $this->getJson('/palette-off/_alfakit/command-palette')->assertOk()->assertExactJson(['create' => [], 'recent' => []]);
});

// ---------------------------------------------------------------- Create links

it('lists create links only for resources the user may create records in', function (): void {
    palettePanel();
    $this->actingAs(paletteUser());

    $create = $this->getJson('/palette/_alfakit/command-palette')->assertOk()->json('create');

    expect($create)->toHaveCount(1)
        ->and($create[0]['url'])->toBe('http://localhost/palette/versioned-posts/create')
        ->and($create[0]['icon'])->toBe('file-text');

    PalettePostPolicy::$canCreate = false;

    expect($this->getJson('/palette/_alfakit/command-palette')->json('create'))->toBe([]);
});

// ---------------------------------------------------------------- Recent records

it('remembers the records a user opened, newest first', function (): void {
    palettePanel();
    $user = paletteUser();
    $this->actingAs($user);
    $first = palettePost($user, ['title' => 'First']);
    $second = palettePost($user, ['title' => 'Second']);

    inertiaGet($this, "/palette/versioned-posts/{$first->getKey()}/edit")->assertOk();
    inertiaGet($this, "/palette/versioned-posts/{$second->getKey()}")->assertOk();
    // Opening a record again moves it to the top instead of listing it twice.
    inertiaGet($this, "/palette/versioned-posts/{$first->getKey()}/edit")->assertOk();

    $recent = $this->getJson('/palette/_alfakit/command-palette')->json('recent');

    expect(array_column($recent, 'title'))->toBe(['First', 'Second'])
        ->and($recent[0]['url'])->toBe("http://localhost/palette/versioned-posts/{$first->getKey()}/edit")
        ->and($recent[1]['url'])->toBe("http://localhost/palette/versioned-posts/{$second->getKey()}")
        ->and($recent[0]['group'])->toBe('Versioned post');
});

it('only shows recent records the user can still view, and keeps them per user', function (): void {
    palettePanel();
    $ada = paletteUser();
    $bob = paletteUser('Bob');
    $public = palettePost($ada, ['title' => 'Public', 'is_published' => true]);
    $private = palettePost($ada, ['title' => 'Private', 'is_published' => true]);
    $gone = palettePost($ada, ['title' => 'Gone', 'is_published' => true]);

    $this->actingAs($bob);

    foreach ([$public, $private, $gone] as $post) {
        inertiaGet($this, "/palette/versioned-posts/{$post->getKey()}")->assertOk();
    }

    // Bob loses access to one record and another is deleted for good.
    $private->update(['is_published' => false]);
    $gone->forceDelete();

    expect(array_column($this->getJson('/palette/_alfakit/command-palette')->json('recent'), 'title'))->toBe(['Public']);

    // Ada never opened anything: Bob's visits are his own.
    $this->actingAs($ada);
    expect($this->getJson('/palette/_alfakit/command-palette')->json('recent'))->toBe([]);
});

it('links to the view page when the user may no longer edit a recent record', function (): void {
    palettePanel();
    $ada = paletteUser();
    $post = palettePost($ada, ['title' => 'Mine', 'is_published' => true]);
    $this->actingAs($ada);

    inertiaGet($this, "/palette/versioned-posts/{$post->getKey()}/edit")->assertOk();
    $post->update(['author_id' => paletteUser('Cy')->getKey()]);

    expect($this->getJson('/palette/_alfakit/command-palette')->json('recent.0.url'))->toBe("http://localhost/palette/versioned-posts/{$post->getKey()}");
});

it('does not remember records when recent records are off', function (): void {
    $panel = palettePanel('palette-norecent', fn (Panel $panel): Panel => $panel->recentRecords(false));
    $user = paletteUser();
    $this->actingAs($user);
    $post = palettePost($user);

    inertiaGet($this, "/palette-norecent/versioned-posts/{$post->getKey()}/edit")->assertOk();

    expect(inertiaProps(inertiaGet($this, '/palette-norecent'))['alfakit']['commandPalette'])->not->toHaveKey('recentRecords')
        // Nothing was stored: turning them back on shows no visits.
        ->and(RecentRecords::get($panel->recentRecords(5), $user))->toBe([]);
});

// ---------------------------------------------------------------- Keyboard shortcuts

it('stores customised shortcuts per user (cache without the table)', function (): void {
    palettePanel();
    $ada = paletteUser();
    $this->actingAs($ada);

    $stored = $this->postJson('/palette/_alfakit/shortcuts', ['shortcuts' => ['command:clear-cache' => ['mod+j'], 'go.dashboard' => ['g h', 'shift+d'], 'help.overlay' => []]])
        ->assertOk()
        ->json('shortcuts');

    expect($stored)->toBe(['command:clear-cache' => ['mod+j'], 'go.dashboard' => ['g h', 'shift+d'], 'help.overlay' => []]);

    expect(inertiaProps(inertiaGet($this, '/palette'))['alfakit']['keyboardShortcuts']['overrides'])
        ->toBe(['command:clear-cache' => ['mod+j'], 'go.dashboard' => ['g h', 'shift+d'], 'help.overlay' => []]);

    // `null` restores the default.
    $this->postJson('/palette/_alfakit/shortcuts', ['shortcuts' => ['go.dashboard' => null]])->assertOk();

    expect(array_keys(KeyboardShortcuts::overridesFor(alfakit()->getPanel('palette'), $ada)))->toBe(['command:clear-cache', 'help.overlay']);

    // Other users keep the defaults.
    $this->actingAs(paletteUser('Bob'));
    expect(inertiaProps(inertiaGet($this, '/palette'))['alfakit']['keyboardShortcuts'])->not->toHaveKey('overrides');
});

it('stores customised shortcuts in the preferences table when it exists', function (): void {
    (require dirname(__DIR__, 2).'/database/migrations/0001_01_01_000200_create_alfakit_user_preferences_table.php')->up();
    UserPreferences::flush();
    palettePanel();
    $ada = paletteUser();
    $this->actingAs($ada);

    $this->postJson('/palette/_alfakit/shortcuts', ['shortcuts' => ['palette.open' => ['mod+p']]])->assertOk();

    expect(DB::table('alfakit_user_preferences')->count())->toBe(1)
        ->and(json_decode((string) DB::table('alfakit_user_preferences')->value('value'), true))->toBe(['palette.open' => ['mod+p']])
        ->and(KeyboardShortcuts::overridesFor(alfakit()->getPanel('palette'), $ada))->toBe(['palette.open' => ['mod+p']]);
});

it('rejects malformed shortcuts', function (array $shortcuts): void {
    palettePanel();
    $this->actingAs(paletteUser());

    $this->postJson('/palette/_alfakit/shortcuts', ['shortcuts' => $shortcuts])->assertStatus(422);
})->with([
    'script' => [['palette.open' => ['<script>']]],
    'unknown modifier' => [['palette.open' => ['hyper+k']]],
    'upper case' => [['palette.open' => ['Mod+K']]],
    'too many steps' => [['palette.open' => ['g a b c']]],
    'too many bindings' => [['palette.open' => ['a', 'b', 'c', 'd']]],
    'bad id' => [['../etc' => ['a']]],
    'not a list' => [['palette.open' => 'mod+k']],
]);

it('refuses shortcut changes to guests and when customising is off', function (): void {
    palettePanel('palette-fixed', fn (Panel $panel): Panel => $panel->keyboardShortcuts(customizable: false));

    $this->postJson('/palette-fixed/_alfakit/shortcuts', ['shortcuts' => ['palette.open' => ['mod+p']]])->assertUnauthorized();

    $this->actingAs(paletteUser());
    $this->postJson('/palette-fixed/_alfakit/shortcuts', ['shortcuts' => ['palette.open' => ['mod+p']]])->assertForbidden();

    expect(inertiaProps(inertiaGet($this, '/palette-fixed'))['alfakit']['keyboardShortcuts'])->toBe([]);
});

it('validates bindings', function (): void {
    expect(KeyboardShortcuts::isValidBinding('mod+shift+k'))->toBeTrue()
        ->and(KeyboardShortcuts::isValidBinding('g d'))->toBeTrue()
        ->and(KeyboardShortcuts::isValidBinding('?'))->toBeTrue()
        ->and(KeyboardShortcuts::isValidBinding('/'))->toBeTrue()
        ->and(KeyboardShortcuts::isValidBinding('alt+arrowup'))->toBeTrue()
        ->and(KeyboardShortcuts::isValidBinding('mod+'))->toBeFalse()
        ->and(KeyboardShortcuts::isValidBinding('g  d'))->toBeFalse()
        ->and(KeyboardShortcuts::isValidBinding('javascript:alert(1)'))->toBeFalse();
});
