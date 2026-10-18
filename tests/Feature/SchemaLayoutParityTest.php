<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Actions\Action;
use AlfaSix\Alfakit\Actions\ActionGroup;
use AlfaSix\Alfakit\Forms\Components\TextInput;
use AlfaSix\Alfakit\Infolists\Components\TextEntry;
use AlfaSix\Alfakit\Schemas\Components\Callout;
use AlfaSix\Alfakit\Schemas\Components\Fieldset;
use AlfaSix\Alfakit\Schemas\Components\Grid;
use AlfaSix\Alfakit\Schemas\Components\Group;
use AlfaSix\Alfakit\Schemas\Components\Section;
use AlfaSix\Alfakit\Schemas\Components\Tabs;
use AlfaSix\Alfakit\Schemas\Components\Tabs\Tab;
use AlfaSix\Alfakit\Schemas\Components\Text;
use AlfaSix\Alfakit\Schemas\Components\View;
use AlfaSix\Alfakit\Support\Enums\Alignment;
use AlfaSix\Alfakit\Support\Enums\Size;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Post;
use AlfaSix\Alfakit\Tests\Fixtures\Models\User;
use AlfaSix\Alfakit\Tests\Fixtures\SchemaActions\SchemaActionsPage;

// ---------------------------------------------------------------- Heading levels

it('gives top-level sections h2 and nested sections one level deeper', function (): void {
    $serialized = schema([
        Section::make('Outer')->schema([
            Section::make('Inner')->schema([
                Section::make('Deepest')->schema([TextInput::make('title')]),
            ]),
        ]),
    ])->fill()->toSchema();

    expect($serialized[0]['headingLevel'])->toBe(2)
        ->and($serialized[0]['schema'][0]['headingLevel'])->toBe(3)
        ->and($serialized[0]['schema'][0]['schema'][0]['headingLevel'])->toBe(4);
});

it('starts at a root heading level set on a layout component, a closure, or the section', function (): void {
    $serialized = schema([
        Group::make([
            Section::make('Top')->schema([Section::make('Nested')->schema([])]),
        ])->rootHeadingLevel(fn (): int => 3),
        Section::make('Explicit')->headingLevel(5)->schema([
            Section::make('Below explicit')->schema([]),
        ]),
        Group::make([Section::make('Clamped')->schema([])])->rootHeadingLevel(9),
        Section::make()->schema([]),
    ])->fill()->toSchema();

    expect($serialized[0]['schema'][0]['headingLevel'])->toBe(3)
        ->and($serialized[0]['schema'][0]['schema'][0]['headingLevel'])->toBe(4)
        ->and($serialized[1]['headingLevel'])->toBe(5)
        ->and($serialized[1]['schema'][0]['headingLevel'])->toBe(6)
        ->and($serialized[2]['schema'][0]['headingLevel'])->toBe(6)
        ->and($serialized[3])->not->toHaveKey('headingLevel');
});

it('reads the root heading level from a schema that provides one', function (): void {
    $schema = new class extends AlfaSix\Alfakit\Schemas\Schema
    {
        public function getRootHeadingLevel(): ?int
        {
            return 4;
        }
    };

    $serialized = $schema->components([Section::make('Top')->schema([])])->fill()->toSchema();

    expect($serialized[0]['headingLevel'])->toBe(4);
});

// ---------------------------------------------------------------- Column order, dense, gap

it('serialises the column order per breakpoint', function (): void {
    $serialized = schema([
        TextInput::make('first')->columnOrder(3),
        TextInput::make('second')->columnOrder(['default' => 1, 'lg' => 2]),
        TextInput::make('third')->columnOrder(fn (): int => 1),
        TextInput::make('plain'),
    ])->fill()->toSchema();

    expect($serialized[0]['columnOrder'])->toBe(['default' => 3])
        ->and($serialized[1]['columnOrder'])->toBe(['default' => 1, 'lg' => 2])
        ->and($serialized[2]['columnOrder'])->toBe(['default' => 1])
        ->and($serialized[3])->not->toHaveKey('columnOrder');
});

it('serialises dense and gap(false) on layout components only', function (): void {
    $serialized = schema([
        Fieldset::make('Dense')->dense()->schema([TextInput::make('a')]),
        Grid::make(2)->gap(false)->schema([TextInput::make('b')]),
        Section::make('Plain')->schema([TextInput::make('c')]),
        TextInput::make('field')->dense(),
    ])->fill()->toSchema();

    expect($serialized[0]['dense'])->toBeTrue()
        ->and($serialized[0])->not->toHaveKey('gap')
        ->and($serialized[1]['gap'])->toBeFalse()
        ->and($serialized[2])->not->toHaveKeys(['dense', 'gap'])
        ->and($serialized[3])->not->toHaveKey('dense');
});

// ---------------------------------------------------------------- Sections

it('persists the collapsed state under the id or the heading', function (): void {
    $serialized = schema([
        Section::make('Order cart')->collapsible()->persistCollapsed()->schema([]),
        Section::make('Order cart')->id('cart-2')->persistCollapsed()->schema([]),
        Section::make('Not collapsible')->persistCollapsed(false)->schema([]),
        Section::make('Off')->collapsible()->persistCollapsed(fn (): bool => false)->schema([]),
    ])->fill()->toSchema();

    expect($serialized[0]['persistCollapsed'])->toBe('alfakit.section.order-cart')
        ->and($serialized[1]['persistCollapsed'])->toBe('alfakit.section.cart-2')
        ->and($serialized[1]['collapsible'])->toBeTrue()
        ->and($serialized[2])->not->toHaveKey('persistCollapsed')
        ->and($serialized[2])->not->toHaveKey('collapsible')
        ->and($serialized[3])->not->toHaveKey('persistCollapsed');
});

it('serialises actions and components after the section header', function (): void {
    $serialized = schema([
        Section::make('Details')->key('details')->afterHeader([
            Text::make('Beta')->badge(),
            Action::make('edit'),
            Action::make('secret')->hidden(),
            ActionGroup::make([Action::make('archive')]),
            Text::make('Hidden')->hidden(),
            'Plain text',
        ])->schema([TextInput::make('title')]),
    ])->fill()->toSchema();

    $items = $serialized[0]['afterHeader'];

    expect($items)->toHaveCount(4)
        ->and($items[0]['component']['type'])->toBe('Text')
        ->and($items[0]['component']['key'])->toBe('details::afterHeader.0')
        ->and($items[1]['action']['name'])->toBe('edit')
        ->and($items[2]['action']['type'])->toBe('ActionGroup')
        ->and($items[3]['component']['content'])->toBe('Plain text');
});

it('evaluates after-header closures with the record', function (): void {
    $post = new Post(['title' => 'Draft post']);

    $serialized = schema([
        Section::make('Details')->afterHeader(fn (?Post $record): string => "Editing {$record?->title}")->schema([]),
    ], record: $post, operation: 'edit')->fill()->toSchema();

    expect($serialized[0]['afterHeader'][0]['component']['content'])->toBe('Editing Draft post');
});

it('runs after-header and callout control actions through the page action endpoint', function (): void {
    $user = User::query()->create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'password']);
    $this->actingAs($user);
    $ran = [];
    SchemaActionsPage::reset();
    SchemaActionsPage::$formUsing = function () use (&$ran): array {
        return [
            Section::make('Details')->afterHeader([
                Action::make('ping')->action(function () use (&$ran): void {
                    $ran[] = 'ping';
                }),
                ActionGroup::make([Action::make('grouped')->action(function () use (&$ran): void {
                    $ran[] = 'grouped';
                })]),
            ])->schema([TextInput::make('title')]),
            Callout::make('Tip')->controlActions([
                Action::make('dismiss')->iconButton()->action(function () use (&$ran): void {
                    $ran[] = 'dismiss';
                }),
            ])->controls([Action::make('more')->action(function () use (&$ran): void {
                $ran[] = 'more';
            })]),
        ];
    };

    foreach (['ping', 'grouped', 'dismiss', 'more'] as $name) {
        $this->postJson('/admin/_alfakit/actions', [
            'token' => SchemaActionsPage::token($user),
            'component' => 'page',
            'action' => $name,
            'state' => ['title' => 'x'],
        ])->assertOk();
    }

    expect($ran)->toBe(['ping', 'grouped', 'dismiss', 'more']);
    SchemaActionsPage::reset();
});

it('does not run an after-header action that is hidden', function (): void {
    $user = User::query()->create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'password']);
    $this->actingAs($user);
    SchemaActionsPage::reset();
    SchemaActionsPage::$formUsing = fn (): array => [
        Section::make('Details')->afterHeader([
            Action::make('forbidden')->hidden()->action(fn () => throw new RuntimeException('ran')),
        ])->schema([]),
    ];

    $response = $this->postJson('/admin/_alfakit/actions', [
        'token' => SchemaActionsPage::token($user),
        'component' => 'page',
        'action' => 'forbidden',
        'state' => [],
    ]);

    expect($response->status())->toBeIn([403, 404]);
    SchemaActionsPage::reset();
});

// ---------------------------------------------------------------- Tabs

it('serialises scrollable(false) and persistTab()', function (): void {
    $serialized = schema([
        Tabs::make('Orders')->id('order-tabs')->scrollable(false)->persistTab()->tabs([Tab::make('One')->schema([])]),
        Tabs::make('Other')->key('other')->persistTab(storage: 'session')->tabs([Tab::make('Two')->schema([])]),
        Tabs::make('Plain')->persistTab(fn (): bool => false)->tabs([Tab::make('Three')->schema([])]),
    ])->fill()->toSchema();

    expect($serialized[0]['scrollable'])->toBeFalse()
        ->and($serialized[0]['persistTab'])->toBe(['key' => 'alfakit.tabs.order-tabs', 'storage' => 'local'])
        ->and($serialized[1]['persistTab'])->toBe(['key' => 'alfakit.tabs.other', 'storage' => 'session'])
        ->and($serialized[1])->not->toHaveKey('scrollable')
        ->and($serialized[2])->not->toHaveKey('persistTab');
});

// ---------------------------------------------------------------- Callouts

it('serialises the callout icon size, footer alignment, controls and control actions', function (): void {
    $serialized = schema([
        Callout::make('Tip')
            ->iconSize(Size::Small)
            ->footerActionsAlignment(Alignment::End)
            ->actions([Action::make('upgrade')])
            ->controls([Text::make('New'), Action::make('help')])
            ->controlActions([Action::make('dismiss')->iconButton(), Action::make('gone')->hidden()]),
        Callout::make('Plain')->iconSize('lg')->footerActionsAlignment('between'),
    ])->fill()->toSchema();

    $controls = $serialized[0]['controls'];

    expect($serialized[0]['iconSize'])->toBe('sm')
        ->and($serialized[0]['footerActionsAlignment'])->toBe('end')
        ->and(array_column($serialized[0]['actions'], 'name'))->toBe(['upgrade'])
        ->and($controls)->toHaveCount(3)
        ->and($controls[0]['component']['type'])->toBe('Text')
        ->and($controls[1]['action']['name'])->toBe('help')
        ->and($controls[2]['action']['name'])->toBe('dismiss')
        ->and($serialized[1]['iconSize'])->toBe('lg')
        ->and($serialized[1]['footerActionsAlignment'])->toBe('between')
        ->and($serialized[1])->not->toHaveKey('controls');
});

// ---------------------------------------------------------------- Custom component context

it('sends no context unless the component exposes it', function (): void {
    $serialized = schema([View::make('map-picker')], record: new Post(['title' => 'Secret']))->fill()->toSchema();

    expect($serialized[0])->not->toHaveKey('context');
});

it('exposes the operation, record key, listed attributes and data', function (): void {
    $post = Post::query()->create(['title' => 'Hello', 'slug' => 'hello', 'content' => 'one two three']);

    $serialized = schema([
        View::make('word-count')->exposeContext(
            record: ['title', 'missing'],
            data: fn (?Post $record, string $operation): array => ['words' => str_word_count((string) $record?->content), 'operation' => $operation],
        ),
        TextEntry::make('title')->exposeContext(),
    ], record: $post, operation: 'edit')->fill()->toSchema();

    expect($serialized[0]['context'])->toBe([
        'operation' => 'edit',
        'recordKey' => $post->getKey(),
        'record' => ['title' => 'Hello', 'missing' => null],
        'data' => ['words' => 3, 'operation' => 'edit'],
        'basePath' => null,
    ])
        ->and($serialized[1]['context'])->toBe(['operation' => 'edit', 'recordKey' => $post->getKey(), 'record' => null, 'data' => null, 'basePath' => null])
        ->and($serialized[0]['context']['record'])->not->toHaveKey('body');
});

it('exposes context without a record on create', function (): void {
    $serialized = schema([View::make('x')->exposeContext(record: ['title'])], model: Post::class)->fill()->toSchema();

    expect($serialized[0]['context'])->toBe(['operation' => 'create', 'recordKey' => null, 'record' => null, 'data' => null, 'basePath' => null]);
});

it('serialises the section footer actions alignment', function (): void {
    $serialized = schema([
        Section::make('A')->footerActions([Action::make('save')])->footerActionsAlignment(Alignment::End)->schema([]),
        Section::make('B')->footerActionsAlignment(fn (): string => 'center')->schema([]),
        Section::make('C')->schema([]),
    ])->fill()->toSchema();

    expect($serialized[0]['footerActionsAlignment'])->toBe('end')
        ->and($serialized[1]['footerActionsAlignment'])->toBe('center')
        ->and($serialized[2])->not->toHaveKey('footerActionsAlignment');
});

it('starts at the root heading level set on the schema itself', function (): void {
    $serialized = schema([
        Section::make('Top')->schema([Section::make('Nested')->schema([TextInput::make('title')])]),
    ])->rootHeadingLevel(4)->fill()->toSchema();

    expect($serialized[0]['headingLevel'])->toBe(4)
        ->and($serialized[0]['schema'][0]['headingLevel'])->toBe(5);
});
