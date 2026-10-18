<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Actions\Action;
use AlfaSix\Alfakit\Forms\Components\TextInput;
use AlfaSix\Alfakit\Infolists\Components\TextEntry;
use AlfaSix\Alfakit\Schemas\Components\Callout;
use AlfaSix\Alfakit\Schemas\Components\EmptyState;
use AlfaSix\Alfakit\Schemas\Components\Fieldset;
use AlfaSix\Alfakit\Schemas\Components\Grid;
use AlfaSix\Alfakit\Schemas\Components\Section;
use AlfaSix\Alfakit\Schemas\Components\Text;
use AlfaSix\Alfakit\Schemas\Components\Wizard;
use AlfaSix\Alfakit\Schemas\Components\Wizard\Step;
use AlfaSix\Alfakit\Schemas\DeferredLoading;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Post;
use AlfaSix\Alfakit\Tests\Fixtures\Models\User;
use AlfaSix\Alfakit\Tests\Fixtures\Parity3\P3PanelProvider;

beforeEach(function (): void {
    $this->panel = P3PanelProvider::registerPanel();
    alfakit()->setCurrentPanel($this->panel);
    $this->user = User::query()->create(['name' => 'P3', 'email' => 'p3'.uniqid().'@example.com', 'password' => 'password']);
    $this->actingAs($this->user);
    $this->post = Post::query()->create(['title' => 'Hello', 'slug' => 'hello-'.uniqid(), 'rating' => 7, 'status' => 'draft']);
});

/**
 * @param  array<int, array<string, mixed>>  $components
 * @return array<string, mixed>|null
 */
function p3Find(array $components, string $key): ?array
{
    foreach ($components as $component) {
        if (($component['key'] ?? null) === $key) {
            return $component;
        }

        if (($found = p3Find($component['schema'] ?? [], $key)) !== null) {
            return $found;
        }
    }

    return null;
}

/**
 * @return array<string, mixed>
 */
function p3Form(array $props): array
{
    return collect($props['page']['content'])->firstWhere('type', 'Form');
}

it('sends deferLoading() components as placeholders on the first page response', function (): void {
    $props = inertiaProps(inertiaGet($this, "/p3/posts/{$this->post->id}/edit"));
    $form = p3Form($props);
    $details = p3Find($form['components'], 'details');

    expect($details['deferred'])->toBeTrue()
        ->and($details['schema'] ?? [])->toBe([])
        ->and($details['deferredPaths'])->toBe(['slug'])
        // The state of the fields inside is sent as usual.
        ->and($form['state']['slug'])->toBe($this->post->slug)
        ->and($props['alfakit']['endpoints']['schemaDeferred'])->toEndWith('/p3/_alfakit/schema/deferred');

    $later = p3Find($form['components'], 'later');
    $meta = p3Find($form['components'], 'meta');

    expect($later['deferred'])->toBeTrue()
        ->and($meta)->not->toHaveKey('deferred')
        ->and($meta['badgeDeferred'])->toBeTrue()
        ->and($meta)->not->toHaveKey('badge');
});

it('loads a deferred component and a deferred tab badge from the endpoint', function (): void {
    $token = p3Form(inertiaProps(inertiaGet($this, "/p3/posts/{$this->post->id}/edit")))['token']
        ?? inertiaProps(inertiaGet($this, "/p3/posts/{$this->post->id}/edit"))['page']['token'];

    $component = $this->postJson('/p3/_alfakit/schema/deferred', ['token' => $token, 'component' => 'form::details', 'state' => ['title' => 'x']])
        ->assertOk()->json('component');

    expect($component['key'])->toBe('details')
        ->and($component)->not->toHaveKey('deferred')
        ->and($component['schema'][0]['statePath'] ?? $component['schema'][0]['key'])->toBe('slug');

    $badge = $this->postJson('/p3/_alfakit/schema/deferred', ['token' => $token, 'component' => 'form::meta', 'only' => 'badge'])
        ->assertOk()->json('badge');

    expect($badge)->toMatchArray(['badge' => 7, 'badgeColor' => 'danger']);

    // A deferred badge is only for tabs.
    $this->postJson('/p3/_alfakit/schema/deferred', ['token' => $token, 'component' => 'form::details', 'only' => 'badge'])->assertNotFound();
    // Unknown components don't exist.
    $this->postJson('/p3/_alfakit/schema/deferred', ['token' => $token, 'component' => 'form::nope'])->assertNotFound();
    $this->postJson('/p3/_alfakit/schema/deferred', ['token' => $token, 'component' => 'nope'])->assertStatus(400);
});

it('refuses deferred loads without a valid token', function (): void {
    $this->postJson('/p3/_alfakit/schema/deferred', ['component' => 'form::details'])->assertStatus(400);
});

it('defers infolist sections too', function (): void {
    $props = inertiaProps(inertiaGet($this, "/p3/posts/{$this->post->id}"));
    $infolist = collect($props['page']['content'])->firstWhere('type', 'Infolist');

    expect(p3Find($infolist['components'], 'summary')['deferred'])->toBeTrue();

    $component = $this->postJson('/p3/_alfakit/schema/deferred', ['token' => $props['page']['token'], 'component' => 'infolist::summary'])
        ->assertOk()->json('component');

    expect($component['schema'][0]['state'])->toBe('Hello');
});

it('renders deferred components in full outside a page first render', function (): void {
    $schema = schema([Section::make('A')->key('a')->deferLoading()->schema([TextInput::make('name')])]);

    expect($schema->toSchema()[0])->not->toHaveKey('deferred')
        ->and(DeferredLoading::withPlaceholders(fn (): array => $schema->toSchema())[0]['deferred'])->toBeTrue()
        ->and(DeferredLoading::rendersPlaceholders())->toBeFalse();

    $conditional = schema([Grid::make()->key('g')->deferLoading(fn (): bool => false)->schema([TextInput::make('name')])]);

    expect(DeferredLoading::withPlaceholders(fn (): array => $conditional->toSchema())[0])->not->toHaveKey('deferred');
});

it('defers wizard steps through the wizard', function (): void {
    $schema = schema([Wizard::make([Step::make('One')->key('one')->schema([TextInput::make('a')])])->key('w')->deferLoading()]);
    $wizard = DeferredLoading::withPlaceholders(fn (): array => $schema->toSchema())[0];

    expect($wizard)->not->toHaveKey('deferred')
        ->and($wizard['schema'][0]['deferred'])->toBeTrue()
        ->and($wizard['schema'][0]['deferredPaths'])->toBe(['a']);
});

it('serialises Fieldset::contained(false)', function (): void {
    expect(schema([Fieldset::make('A')->schema([TextInput::make('a')])])->toSchema()[0])->not->toHaveKey('contained')
        ->and(schema([Fieldset::make('A')->contained(false)->schema([TextInput::make('a')])])->toSchema()[0]['contained'])->toBeFalse();
});

it('accepts components and actions in Section, Callout and EmptyState footers', function (): void {
    $section = Section::make('S')->key('s')->schema([TextInput::make('a')])
        ->footer([Text::make('Last saved today'), Action::make('preview')]);
    $callout = Callout::make('C')->key('c')->footer([Text::make('Hint'), Action::make('fix')]);
    $empty = EmptyState::make('E')->key('e')->actions([Action::make('create')])->footer([Text::make('or import'), Action::make('import')]);

    [$s, $c, $e] = schema([$section, $callout, $empty])->toSchema();

    expect($s['footer'][0]['component']['content'])->toBe('Last saved today')
        ->and($s['footer'][1]['action']['name'])->toBe('preview')
        ->and(collect($section->getAllActions())->map->getName()->all())->toContain('preview')
        ->and($c['footer'][0]['component']['content'])->toBe('Hint')
        ->and(collect($callout->getAllActions())->map->getName()->all())->toContain('fix')
        ->and($e['actions'][0]['name'])->toBe('create')
        ->and($e['footer'][1]['action']['name'])->toBe('import')
        ->and(collect($empty->getAllActions())->map->getName()->all())->toBe(['create', 'import']);
});

it('keeps the callout status after color(null) and accepts iconColor()', function (): void {
    [$plain, $custom] = schema([
        Callout::make('A')->danger()->color(null),
        Callout::make('B')->warning()->iconColor('primary'),
    ])->toSchema();

    expect($plain)->not->toHaveKey('color')
        ->and($plain['status'])->toBe('danger')
        ->and($plain['icon'])->toBe('circle-x')
        ->and($custom['iconColor'])->toBe('primary')
        ->and($custom['color'])->toBe('warning');
});

it('serialises Text fontFamily, tooltip and copyable', function (): void {
    $text = schema([Text::make('ABC-123')->color('neutral')->fontFamily('mono')->tooltip('Order number')->copyable(copyMessage: 'Copied!', copyMessageDuration: 1500)])->toSchema()[0];

    expect($text)->toMatchArray([
        'color' => 'neutral',
        'fontFamily' => 'mono',
        'tooltip' => 'Order number',
        'copyable' => true,
        'copyMessage' => 'Copied!',
        'copyMessageDuration' => 1500,
    ])->and(schema([Text::make('x')])->toSchema()[0])->not->toHaveKey('copyable');
});

it('accepts live debounce durations as strings', function (string|int $debounce, ?int $milliseconds): void {
    $field = TextInput::make('a')->live(debounce: $debounce);
    schema([$field]);

    expect($field->getLiveDebounce())->toBe($milliseconds);
})->with([
    [500, 500],
    ['500', 500],
    ['750ms', 750],
    ['1s', 1000],
    ['1.5s', 1500],
    ['soon', null],
]);

it('serialises entry alignment and keeps wrapper attributes apart', function (): void {
    [$a, $b, $c] = schema([
        TextEntry::make('title')->alignEnd()->extraAttributes(['class' => 'font-bold'])->extraEntryWrapperAttributes(['data-x' => '1']),
        TextEntry::make('slug')->alignment('center'),
        TextEntry::make('status')->alignCenter(false),
    ], $this->post)->toSchema();

    expect($a['alignment'])->toBe('end')
        ->and($a['extraAttributes'])->toBe(['class' => 'font-bold'])
        ->and($a['extraEntryWrapperAttributes'])->toBe(['data-x' => '1'])
        ->and($b['alignment'])->toBe('center')
        ->and($c)->not->toHaveKey('alignment');
});
