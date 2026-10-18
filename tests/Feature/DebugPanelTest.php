<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Forms\Components\TextInput;
use AlfaSix\Alfakit\Pages\Page;
use AlfaSix\Alfakit\Support\Debug\ClosureRecorder;
use AlfaSix\Alfakit\Support\Debug\SchemaDebugger;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Post;
use AlfaSix\Alfakit\Tests\Fixtures\Models\User;
use AlfaSix\Alfakit\Tests\Fixtures\Panel\Resources\PostResource\Pages\EditPost;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/*
 * The developer debug panel: the `alfakitDebug` prop (schema tree with visibility and
 * authorisation, evaluated closures, server timings) only exists while `app.debug` is on
 * and the panel enables it, and `PageTester::dump()` prints the same tree in tests.
 */

class DebugPanelTestPostPolicy
{
    public static bool $delete = true;

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Post $post): bool
    {
        return true;
    }

    public function delete(User $user, Post $post): bool
    {
        return self::$delete;
    }

    public function deleteAny(User $user): bool
    {
        return true;
    }
}

function debugAdmin(): User
{
    return User::query()->create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'password']);
}

beforeEach(function (): void {
    Gate::policy(Post::class, DebugPanelTestPostPolicy::class);
    DebugPanelTestPostPolicy::$delete = true;
    $this->actingAs(debugAdmin());
    $this->post = Post::query()->create(['title' => 'Hello', 'slug' => 'hello', 'author_id' => auth()->id(), 'is_published' => false]);
});

afterEach(function (): void {
    alfakit()->getPanel('admin')->debugPanel(fn (): ?bool => null);
    ClosureRecorder::flush();
});

it('never includes the debug prop when app.debug is off, whatever the panel says', function (): void {
    config(['app.debug' => false]);
    alfakit()->getPanel('admin')->debugPanel();

    $props = inertiaProps(inertiaGet($this, '/admin/posts'));
    $response = inertiaGet($this, '/admin/posts');

    expect($props)->not->toHaveKey(Page::DEBUG_PROP)
        ->and(alfakit()->getPanel('admin')->hasDebugPanel())->toBeFalse()
        ->and($response->headers->has('Server-Timing'))->toBeFalse();
});

it('leaves the debug prop out when the panel disables it or outside local without opting in', function (): void {
    config(['app.debug' => true]);

    // The test environment is not `local`: off unless the panel opts in.
    expect(inertiaProps(inertiaGet($this, '/admin/posts')))->not->toHaveKey(Page::DEBUG_PROP);

    alfakit()->getPanel('admin')->debugPanel(false);

    expect(inertiaProps(inertiaGet($this, '/admin/posts')))->not->toHaveKey(Page::DEBUG_PROP);
});

it('describes the resolved schema tree with visibility and authorisation when enabled', function (): void {
    config(['app.debug' => true]);
    alfakit()->getPanel('admin')->debugPanel(fn (?User $user): bool => $user?->email === 'admin@example.com');
    DebugPanelTestPostPolicy::$delete = false;

    $response = inertiaGet($this, "/admin/posts/{$this->post->getKey()}/edit");
    $debug = inertiaProps($response)[Page::DEBUG_PROP];

    expect($response->headers->get('Server-Timing'))->toStartWith('alfakit;dur=')
        ->and($debug['page'])->toBe(EditPost::class)
        ->and($debug['panel'])->toBe('admin')
        ->and($debug['server'])->toHaveKeys(['duration', 'memory', 'queries', 'queryTime', 'php', 'laravel'])
        ->and($debug['server']['queries'])->toBeGreaterThan(0);

    $byKey = collect($debug['tree'])->keyBy('key');

    // The unauthorised delete action is reported as hidden because of authorisation.
    $delete = collect($byKey['headerActions']['children'])->firstWhere('key', 'delete');
    expect($delete)->toMatchArray(['type' => 'Action', 'visible' => false, 'authorized' => false, 'reason' => 'unauthorized']);

    // The form tree keeps the nesting of the schema and names each field's state path.
    $section = $byKey['form']['children'][0];
    expect($byKey['form']['details'])->toBe(['operation' => 'edit'])
        ->and($section['type'])->toBe('Section')
        ->and($section['visible'])->toBeTrue()
        ->and(collect($section['children'])->firstWhere('key', 'title'))->toMatchArray(['type' => 'TextInput', 'visible' => true, 'details' => ['statePath' => 'title']]);

    // Closures that ran are listed with their owner and source location.
    expect($debug['closures'])->not->toBeEmpty()
        ->and(collect($debug['closures'])->pluck('owner')->all())->toContain('DeleteAction[delete]');
    expect($debug['closures'][0])->toHaveKeys(['owner', 'file', 'line', 'count']);
});

it('covers tables: columns, toggled columns, filters and record actions', function (): void {
    config(['app.debug' => true]);
    alfakit()->getPanel('admin')->debugPanel();

    $debug = inertiaProps(inertiaGet($this, '/admin/posts'))[Page::DEBUG_PROP];
    $table = collect($debug['tree'])->firstWhere('key', 'table');
    $children = collect($table['children'])->keyBy('key');

    expect(collect($children['columns']['children'])->pluck('key')->all())->toBe(['title', 'author.name', 'is_published'])
        ->and($children['columns']['children'][0])->toMatchArray(['type' => 'TextColumn', 'visible' => true])
        ->and($children['filters']['children'][0])->toMatchArray(['type' => 'TernaryFilter', 'key' => 'is_published', 'visible' => true])
        ->and(collect($children['recordActions']['children'])->pluck('key')->all())->toBe(['edit', 'delete'])
        ->and(collect($children['bulkActions']['children'])->pluck('key')->all())->toBe(['delete']);
});

it('lets PageTester dump the tree without enabling the debug panel', function (): void {
    config(['app.debug' => false]);

    $tester = alfakit()->testPage(EditPost::class, ['record' => $this->post]);

    expect($tester->getProps())->not->toHaveKey(Page::DEBUG_PROP);

    $tree = $tester->getDebugTree();
    $text = SchemaDebugger::render($tree);

    expect($tree['page'])->toBe(EditPost::class)
        ->and($text)->toContain(EditPost::class)
        ->and($text)->toContain('├─ HeaderActions headerActions')
        ->and($text)->toContain('Action delete "Delete"')
        ->and($text)->toContain('TextInput title "Title"  [statePath: title]')
        ->and($text)->toContain('Closures evaluated:')
        ->and($text)->toContain('Server: ');

    // dump() prints and returns the tester for chaining.
    ob_start();
    $result = $tester->dump();
    ob_end_clean();

    expect($result)->toBe($tester);
});

it('ignores the testing header outside unit tests', function (): void {
    config(['app.debug' => false]);
    $request = Request::create('/admin/posts', server: ['HTTP_X_ALFAKIT_DEBUG' => '1']);

    expect(SchemaDebugger::shouldInclude(alfakit()->getPanel('admin'), $request))->toBeTrue();

    // runningUnitTests() reads the detected environment: pretend we are serving in production.
    $this->app->instance('env', 'production');

    expect(app()->runningUnitTests())->toBeFalse()
        ->and(SchemaDebugger::shouldInclude(alfakit()->getPanel('admin'), $request))->toBeFalse();

    $this->app->instance('env', 'testing');
});

it('explains why a component is hidden', function (): void {
    expect(TextInput::make('a')->hidden()->getHiddenReason())->toBe('hidden()')
        ->and(TextInput::make('b')->visible(false)->getHiddenReason())->toBe('visible(false)')
        ->and(TextInput::make('c')->getHiddenReason())->toBeNull();

    $schema = schema([TextInput::make('d')->hiddenOn('edit'), TextInput::make('e')->visibleOn(['create'])], operation: 'edit');
    [$d, $e] = $schema->getComponents(withHidden: true);

    expect($d->getHiddenReason())->toBe('hiddenOn(edit)')
        ->and($e->getHiddenReason())->toBe('visibleOn(create)')
        ->and($e->isHidden())->toBeTrue();
});

it('names navigation items with closure labels and no key without recursing', function (): void {
    config(['app.debug' => true]);
    $panel = alfakit()->getPanel('admin');
    $panel->debugPanel(fn (): bool => true)
        ->navigationItems([AlfaSix\Alfakit\Navigation\NavigationItem::make(fn (): string => 'Reports')->url('/reports')]);

    try {
        // Describing the item for the recorder evaluates its label closure, which must
        // not be recorded (and described) again.
        ClosureRecorder::start();
        $item = AlfaSix\Alfakit\Navigation\NavigationItem::make(fn (): string => 'Reports');

        expect($item->getLabel())->toBe('Reports')
            ->and($item->getKey())->toBe('Reports')
            ->and(collect(ClosureRecorder::all())->pluck('owner')->all())->toContain('NavigationItem[Reports]');

        ClosureRecorder::flush();

        $debug = inertiaProps(inertiaGet($this, '/admin/posts'))[Page::DEBUG_PROP];

        expect(collect($debug['closures'])->pluck('owner')->all())->toContain('NavigationItem[Reports]');
    } finally {
        $panel->navigationItems([]);
    }
});
