<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Actions\Action;
use AlfaSix\Alfakit\Actions\ActionGroup;
use AlfaSix\Alfakit\Actions\DeleteAction;
use AlfaSix\Alfakit\Forms\Components\Builder;
use AlfaSix\Alfakit\Forms\Components\Builder\Block;
use AlfaSix\Alfakit\Forms\Components\CheckboxList;
use AlfaSix\Alfakit\Forms\Components\DatePicker;
use AlfaSix\Alfakit\Forms\Components\Radio;
use AlfaSix\Alfakit\Forms\Components\Select;
use AlfaSix\Alfakit\Forms\Components\TextInput;
use AlfaSix\Alfakit\Forms\Components\Toggle;
use AlfaSix\Alfakit\Forms\Components\ToggleButtons;
use AlfaSix\Alfakit\Infolists\Components\IconEntry;
use AlfaSix\Alfakit\Infolists\Components\TextEntry;
use AlfaSix\Alfakit\Navigation\MenuItem;
use AlfaSix\Alfakit\Navigation\NavigationGroup;
use AlfaSix\Alfakit\Navigation\NavigationItem;
use AlfaSix\Alfakit\Notifications\Notification;
use AlfaSix\Alfakit\Resources\RelationManagers\RelationGroup;
use AlfaSix\Alfakit\Schemas\Components\Callout;
use AlfaSix\Alfakit\Schemas\Components\EmptyState;
use AlfaSix\Alfakit\Schemas\Components\Icon;
use AlfaSix\Alfakit\Schemas\Components\Section;
use AlfaSix\Alfakit\Schemas\Components\Tabs;
use AlfaSix\Alfakit\Schemas\Components\Tabs\Tab;
use AlfaSix\Alfakit\Schemas\Components\Text;
use AlfaSix\Alfakit\Schemas\Components\Wizard;
use AlfaSix\Alfakit\Schemas\Components\Wizard\Step;
use AlfaSix\Alfakit\Support\Icons;
use AlfaSix\Alfakit\Tables\Columns\IconColumn;
use AlfaSix\Alfakit\Tables\Columns\TextColumn;
use AlfaSix\Alfakit\Tables\Columns\ToggleColumn;
use AlfaSix\Alfakit\Tables\Filters\QueryBuilder;
use AlfaSix\Alfakit\Tables\Filters\QueryBuilder\Constraints\TextConstraint;
use AlfaSix\Alfakit\Tables\Table;
use AlfaSix\Alfakit\Tables\TableState;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Post;
use AlfaSix\Alfakit\Tests\Fixtures\Models\User;
use AlfaSix\Alfakit\Tests\Fixtures\Panel\Resources\PostResource\RelationManagers\CommentsRelationManager;
use AlfaSix\Alfakit\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Http\Request;

/** Aliases registered by these tests: `app::<name>` resolves to `resolved-<name>`. */
const ICON_ALIAS_NAMES = [
    'affix', 'hint', 'on', 'off', 'option', 'constraint', 'column', 'true', 'false', 'entry', 'section', 'tab',
    'step', 'callout', 'empty', 'action', 'modal', 'group', 'notification', 'navigation', 'active', 'menu', 'stat',
    'block', 'relation', 'relation-group', 'text', 'icon',
];

function registerIconAliases(): void
{
    Icons::register(array_combine(
        array_map(static fn (string $name): string => "app::{$name}", ICON_ALIAS_NAMES),
        array_map(static fn (string $name): string => "resolved-{$name}", ICON_ALIAS_NAMES),
    ));
}

/**
 * Every string under an icon-ish key (`icon`, `prefixIcon`, `onIcon`…) in a payload.
 *
 * @return list<string>
 */
function iconValues(mixed $payload): array
{
    $found = [];

    if (! is_array($payload)) {
        return $found;
    }

    foreach ($payload as $key => $value) {
        if (is_string($value) && is_string($key) && preg_match('/icon$/i', $key) === 1) {
            $found[] = $value;
        }

        array_push($found, ...iconValues($value));
    }

    return $found;
}

/**
 * Assert no registered alias, and no `alfakit::` alias under an icon key, reaches the payload.
 */
function expectNoIconAliases(mixed $payload): void
{
    $aliases = array_keys(Icons::getRegistered());

    array_walk_recursive($payload, static function (mixed $value) use ($aliases): void {
        expect(in_array($value, $aliases, true))->toBeFalse("Unresolved icon alias [{$value}] in payload");
    });

    foreach (iconValues($payload) as $icon) {
        expect(str_starts_with($icon, 'alfakit::'))->toBeFalse("Unresolved icon alias [{$icon}] in payload");
    }
}

afterEach(function (): void {
    Icons::flush();
    (new ReflectionProperty(CommentsRelationManager::class, 'icon'))->setValue(null, null);
});

it('resolves icon aliases of every form, layout and infolist component', function (): void {
    registerIconAliases();
    $post = Post::query()->create(['title' => 'Hello', 'slug' => 'hello', 'is_published' => true]);

    $payload = schema([
        Section::make('Details')->icon('app::section')->schema([
            TextInput::make('title')->prefixIcon('app::affix')->suffixIcon('alfakit::actions.edit')->hint('Hint')->hintIcon('app::hint'),
            DatePicker::make('published_at')->prefixIcon(fn (): string => 'app::affix'),
            Select::make('status')->options(['draft' => 'Draft'])->icons(['draft' => 'app::option'])->suffixIcon('app::affix'),
            Toggle::make('is_published')->onIcon('app::on')->offIcon('app::off'),
            CheckboxList::make('tags')->options(['a' => 'A'])->icons(['a' => 'app::option']),
            Radio::make('kind')->options(['a' => 'A', 'b' => 'B'])->icons(['a' => 'app::option', 'b' => fn (): string => 'alfakit::actions.view']),
            ToggleButtons::make('mode')->options(['a' => 'A'])->icons(['a' => 'app::option']),
            Builder::make('content')->blocks([Block::make('hero')->icon('app::block')->schema([TextInput::make('heading')])]),
        ]),
        Tabs::make('Tabs')->tabs([Tab::make('Main')->icon('app::tab')->schema([
            Text::make('Note')->icon('app::text'),
            Icon::make('app::icon'),
            Callout::make('Heads up')->icon('app::callout'),
            EmptyState::make('Nothing')->icon('app::empty'),
        ])]),
        Wizard::make()->steps([Step::make('One')->icon('app::step')->schema([TextInput::make('slug')])]),
        TextEntry::make('title')->icon('app::entry')->hint('Hint')->hintIcon('app::hint'),
        IconEntry::make('is_published')->boolean()->trueIcon('app::true')->falseIcon('app::false'),
        IconEntry::make('slug')->icon('alfakit::actions.delete'),
    ], record: $post, operation: 'edit')->toSchema();

    expectNoIconAliases($payload);

    expect(iconValues($payload))->toContain(
        'resolved-section', 'resolved-affix', 'pencil', 'resolved-hint', 'resolved-option', 'eye', 'resolved-on',
        'resolved-off', 'resolved-block', 'resolved-tab', 'resolved-text', 'resolved-icon', 'resolved-callout',
        'resolved-empty', 'resolved-step', 'resolved-entry', 'resolved-true', 'trash-2',
    );
});

it('resolves icon aliases of table columns, constraints, empty states and actions', function (): void {
    registerIconAliases();
    Post::query()->create(['title' => 'Hello', 'slug' => 'hello', 'is_published' => false]);

    $payload = Table::make()->query(Post::query())
        ->columns([
            TextColumn::make('title')->icon('app::column'),
            IconColumn::make('is_published')->boolean()->trueIcon('app::true')->falseIcon('app::false'),
            IconColumn::make('slug')->icon(fn (): string => 'alfakit::actions.replicate'),
            ToggleColumn::make('featured')->onIcon('app::on')->offIcon('app::off'),
        ])
        ->filters([QueryBuilder::make()->constraints([TextConstraint::make('title')->icon('app::constraint')])])
        ->recordActions([
            Action::make('archive')->icon('app::action')->modalIcon('app::modal')->requiresConfirmation(),
            ActionGroup::make([DeleteAction::make()])->icon('app::group'),
        ])
        ->emptyStateIcon('app::empty')
        ->state(TableState::fromRequest(Request::create('/')))
        ->toSchema();

    expectNoIconAliases($payload);

    expect(iconValues($payload))->toContain('resolved-column', 'resolved-false', 'copy', 'resolved-on', 'resolved-off', 'resolved-constraint', 'resolved-action', 'resolved-group', 'trash-2');

    $modal = Action::make('archive')->icon('app::action')->modalIcon('app::modal')->toModalSchema();
    $confirmation = Action::make('archive')->icon('app::action')->requiresConfirmation()->toModalSchema();

    expectNoIconAliases([$modal, $confirmation]);
    expect($modal['icon'])->toBe('resolved-modal')
        ->and($confirmation['icon'])->toBe('resolved-action')
        ->and(Action::make('a')->modalIcon('app::modal')->getModalIcon())->toBe('resolved-modal');
});

it('resolves icon aliases of notifications, navigation, widgets and relation managers', function (): void {
    registerIconAliases();

    $payload = [
        Notification::make()->title('Saved')->icon('app::notification')->toArray(),
        Notification::make()->title('Saved')->success()->toArray(),
        NavigationItem::make('Posts')->icon('app::navigation')->activeIcon('app::active')->isActiveWhen(fn (): bool => true)->toSchema(),
        NavigationItem::make('Tags')->icon('app::navigation')->toSchema(),
        NavigationGroup::make('Catalog')->icon('app::group')->toSchema(),
        MenuItem::make()->label('Profile')->icon('app::menu')->toSchema(),
        Stat::make('Posts', 3)->icon('app::stat')->descriptionIcon('app::stat')->description('Up')->toSchema(),
        ['icon' => RelationGroup::make('Discussion', [CommentsRelationManager::class])->icon('app::relation-group')->getIcon()],
    ];

    expectNoIconAliases($payload);
    expect(iconValues($payload))->toContain('resolved-notification', 'circle-check', 'resolved-active', 'resolved-navigation', 'resolved-group', 'resolved-menu', 'resolved-stat', 'resolved-relation-group');

    // Relation manager tabs, eager and lazy, on a real edit page.
    (new ReflectionProperty(CommentsRelationManager::class, 'icon'))->setValue(null, 'app::relation');
    $user = User::query()->create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'password']);
    Post::query()->create(['title' => 'Hello', 'slug' => 'hello', 'author_id' => $user->id]);
    $this->actingAs($user);

    $props = inertiaProps(inertiaGet($this, '/admin/posts/1/edit'));
    $managers = collect($props['page']['content'])->firstWhere('type', 'RelationManagers')['managers'];

    expectNoIconAliases($props);
    expect($managers[0]['icon'])->toBe('resolved-relation')
        ->and(CommentsRelationManager::getIcon())->toBe('resolved-relation');
});
