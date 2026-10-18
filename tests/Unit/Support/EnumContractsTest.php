<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Forms\Components\CheckboxList;
use AlfaSix\Alfakit\Forms\Components\Radio;
use AlfaSix\Alfakit\Forms\Components\Select;
use AlfaSix\Alfakit\Forms\Components\ToggleButtons;
use AlfaSix\Alfakit\Infolists\Components\IconEntry;
use AlfaSix\Alfakit\Infolists\Components\TextEntry;
use AlfaSix\Alfakit\Migration\FilamentClassMap;
use AlfaSix\Alfakit\Navigation\NavigationGroup;
use AlfaSix\Alfakit\Navigation\NavigationItem;
use AlfaSix\Alfakit\Panel;
use AlfaSix\Alfakit\Support\EnumState;
use AlfaSix\Alfakit\Tables\Columns\IconColumn;
use AlfaSix\Alfakit\Tables\Columns\SelectColumn;
use AlfaSix\Alfakit\Tables\Columns\TextColumn;
use AlfaSix\Alfakit\Tables\Filters\SelectFilter;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Post;
use AlfaSix\Alfakit\Tests\Fixtures\PageTable\EnumPost;
use AlfaSix\Alfakit\Tests\Fixtures\PageTable\PostStatus;
use AlfaSix\Alfakit\Tests\Fixtures\PageTable\Section;

enum EnumContractsPriority: int
{
    case Low = 1;
    case UrgentHigh = 2;
}

function enumPost(array $attributes = []): Post
{
    return Post::query()->create(['title' => 'T', 'slug' => uniqid('t'), 'status' => 'published', ...$attributes]);
}

it('shows the label, colour and icon of a cast enum in a text column', function (): void {
    $post = EnumPost::query()->findOrFail(enumPost()->getKey());

    $cell = TextColumn::make('status')->badge()->toCell($post);

    expect($post->status)->toBe(PostStatus::Published)
        ->and($cell)->toMatchArray(['state' => 'Live', 'color' => 'success', 'icon' => 'circle-check', 'iconPosition' => 'before'])
        ->and($cell)->not->toHaveKey('itemColors');
});

it('resolves a stored backing value when the column names the enum', function (): void {
    $post = enumPost(['status' => 'draft']);

    $hinted = TextColumn::make('status')->enum(PostStatus::class)->toCell($post);
    $raw = TextColumn::make('status')->toCell($post);
    $unknown = TextColumn::make('status')->enum(PostStatus::class)->toCell(enumPost(['status' => 'nope']));

    expect($hinted)->toMatchArray(['state' => 'Draft copy', 'color' => 'gray', 'icon' => 'pencil'])
        ->and($raw)->toBe(['state' => 'draft'])
        ->and($unknown)->toBe(['state' => 'nope']);
});

it('lets explicit colours, icons and formatters win over the enum', function (): void {
    $post = EnumPost::query()->findOrFail(enumPost()->getKey());

    $cell = TextColumn::make('status')
        ->color('danger')
        ->icon(fn (PostStatus $state): string => $state === PostStatus::Published ? 'star' : 'x')
        ->formatStateUsing(fn (PostStatus $state): string => strtoupper($state->value))
        ->toCell($post);

    expect($cell)->toMatchArray(['state' => 'PUBLISHED', 'color' => 'danger', 'icon' => 'star']);
});

it('falls back to the headlined case name and ignores missing colours', function (): void {
    $cell = TextColumn::make('priority')->state(EnumContractsPriority::UrgentHigh)->toCell(['priority' => 2]);
    $archived = TextColumn::make('status')->enum(PostStatus::class)->toCell(['status' => 'archived']);
    $int = TextColumn::make('priority')->enum(EnumContractsPriority::class)->toCell(['priority' => '1']);

    expect($cell)->toBe(['state' => 'Urgent High'])
        ->and($archived)->toBe(['state' => 'Archived'])
        ->and($int)->toBe(['state' => 'Low']);
});

it('resolves every item of a list state', function (): void {
    $post = EnumPost::query()->findOrFail(enumPost(['tags_list' => ['draft', 'published']])->getKey());

    $mixed = TextColumn::make('tags_list')->badge()->toCell($post);
    $same = TextColumn::make('tags_list')->enum(PostStatus::class)->toCell(['tags_list' => ['published', 'published']]);
    $raw = TextColumn::make('tags_list')->enum(PostStatus::class)->toCell(['tags_list' => ['draft', 'archived']]);

    expect($mixed)->toMatchArray([
        'state' => ['Draft copy', 'Live'],
        'itemColors' => ['gray', 'success'],
        'itemIcons' => ['pencil', 'circle-check'],
        'iconPosition' => 'before',
    ])
        ->and($mixed)->not->toHaveKey('color')
        ->and($same)->toMatchArray(['state' => ['Live', 'Live'], 'color' => 'success', 'icon' => 'circle-check'])
        ->and($same)->not->toHaveKey('itemColors')
        ->and($raw)->toMatchArray(['state' => ['Draft copy', 'Archived'], 'itemColors' => ['gray', null]]);
});

it('shows enums in text entries', function (): void {
    $post = EnumPost::query()->findOrFail(enumPost()->getKey());
    $entry = TextEntry::make('status')->badge();
    schema([$entry], $post);

    $hinted = TextEntry::make('step')->enum(PostStatus::class)->state('draft')->toSchema();
    $list = TextEntry::make('steps')->state([PostStatus::Draft, PostStatus::Published])->toSchema();

    expect($entry->toSchema())->toMatchArray(['state' => 'Live', 'color' => 'success', 'icon' => 'circle-check'])
        ->and($hinted)->toMatchArray(['state' => 'Draft copy', 'color' => 'gray', 'icon' => 'pencil'])
        ->and($list)->toMatchArray(['state' => ['Draft copy', 'Live'], 'itemColors' => ['gray', 'success']]);
});

it('takes icon column and entry icons and colours from the enum', function (): void {
    $post = EnumPost::query()->findOrFail(enumPost()->getKey());

    $column = IconColumn::make('status')->toCell($post);
    $raw = IconColumn::make('status')->enum(PostStatus::class)->toCell(['status' => 'draft']);
    $explicit = IconColumn::make('status')->icon('flag')->color('warning')->toCell($post);
    $entry = IconEntry::make('status')->enum(PostStatus::class)->state('published')->toSchema();

    expect($column)->toMatchArray(['state' => 'published', 'icon' => 'circle-check', 'color' => 'success'])
        ->and($raw)->toMatchArray(['icon' => 'pencil', 'color' => 'gray'])
        ->and($explicit)->toMatchArray(['icon' => 'flag', 'color' => 'warning'])
        ->and($entry)->toMatchArray(['icon' => 'circle-check', 'color' => 'success']);
});

it('builds options with enum labels, descriptions, colours and icons', function (): void {
    $radio = Radio::make('status')->options(PostStatus::class);
    $checkboxes = CheckboxList::make('statuses')->options(PostStatus::class)->descriptions(['draft' => 'Custom']);
    $toggles = ToggleButtons::make('status')->options(fn (): string => PostStatus::class)->icons(['draft' => 'file']);
    $select = Select::make('status')->options(PostStatus::class);
    schema([$radio, $checkboxes, $toggles, $select])->fill();

    expect($radio->toSchema()['options'])->toBe([
        ['value' => 'draft', 'label' => 'Draft copy'],
        ['value' => 'published', 'label' => 'Live', 'description' => 'Visible to everyone'],
        ['value' => 'archived', 'label' => 'Archived'],
    ])
        ->and($checkboxes->toSchema()['options'][0])->toBe(['value' => 'draft', 'label' => 'Draft copy', 'description' => 'Custom'])
        ->and($toggles->toSchema()['options'])->toBe([
            ['value' => 'draft', 'label' => 'Draft copy', 'icon' => 'file', 'color' => 'gray'],
            ['value' => 'published', 'label' => 'Live', 'icon' => 'circle-check', 'color' => 'success'],
            ['value' => 'archived', 'label' => 'Archived'],
        ])
        // Selects only take the labels.
        ->and($select->toSchema()['options'][1])->toBe(['value' => 'published', 'label' => 'Live'])
        ->and(SelectColumn::make('status')->options(PostStatus::class)->toSchema()['options'][1])->toBe(['value' => 'published', 'label' => 'Live'])
        ->and(SelectFilter::make('status')->options(PostStatus::class)->getOptions())->toBe(['draft' => 'Draft copy', 'published' => 'Live', 'archived' => 'Archived'])
        ->and(Select::make('priority')->options(EnumContractsPriority::class)->getOptions())->toBe([1 => 'Low', 2 => 'Urgent High']);
});

it('builds navigation groups from enums', function (): void {
    $group = NavigationGroup::make(Section::Shop);
    $item = NavigationItem::make('Orders')->group(Section::Shop);

    expect($group->getLabel())->toBe('Shop floor')
        ->and($group->getIcon())->toBe('shopping-bag')
        ->and($group->getEnum())->toBe(Section::Shop)
        ->and($group->toSchema())->toMatchArray(['label' => 'Shop floor', 'icon' => 'shopping-bag'])
        ->and($item->getGroup())->toBe('Shop floor')
        ->and($item->getGroupSource())->toBe(Section::Shop)
        ->and(NavigationItem::make('Posts')->group('Content')->getGroupSource())->toBe('Content');
});

it('resolves cases from stored values', function (): void {
    expect(EnumState::resolve('draft', PostStatus::class))->toBe(PostStatus::Draft)
        ->and(EnumState::resolve(PostStatus::Draft, PostStatus::class))->toBe(PostStatus::Draft)
        ->and(EnumState::resolve(Section::Shop, PostStatus::class))->toBeNull()
        ->and(EnumState::resolve('Shop', Section::class))->toBe(Section::Shop)
        ->and(EnumState::resolve('2', EnumContractsPriority::class))->toBe(EnumContractsPriority::UrgentHigh)
        ->and(EnumState::resolve('x', EnumContractsPriority::class))->toBeNull()
        ->and(EnumState::resolve(['draft'], PostStatus::class))->toBeNull()
        ->and(EnumState::resolve('draft'))->toBeNull();
});

it('maps the Filament enum contracts and page table concerns in the migrator', function (): void {
    $map = FilamentClassMap::classes();

    expect($map['Filament\\Support\\Contracts\\HasLabel'])->toBe('AlfaSix\\Alfakit\\Support\\Contracts\\HasLabel')
        ->and($map['Filament\\Support\\Contracts\\HasColor'])->toBe('AlfaSix\\Alfakit\\Support\\Contracts\\HasColor')
        ->and($map['Filament\\Support\\Contracts\\HasIcon'])->toBe('AlfaSix\\Alfakit\\Support\\Contracts\\HasIcon')
        ->and($map['Filament\\Support\\Contracts\\HasDescription'])->toBe('AlfaSix\\Alfakit\\Support\\Contracts\\HasDescription')
        ->and($map['Filament\\Widgets\\Concerns\\InteractsWithPageTable'])->toBe('AlfaSix\\Alfakit\\Widgets\\Concerns\\InteractsWithPageTable')
        ->and($map['Filament\\Pages\\Concerns\\ExposesTableToWidgets'])->toBe('AlfaSix\\Alfakit\\Pages\\Concerns\\ExposesTableToWidgets');

    foreach (['HasLabel', 'HasColor', 'HasIcon', 'HasDescription'] as $contract) {
        expect(interface_exists('AlfaSix\\Alfakit\\Support\\Contracts\\'.$contract))->toBeTrue();
    }
});

it('groups panel navigation by enum cases, with the enum icon and the configured order', function (): void {
    $panel = Panel::make('enum-nav')
        ->navigationGroups([Section::Content, Section::Shop])
        ->navigationItems([
            NavigationItem::make('Orders')->url('/orders')->group(Section::Shop),
            NavigationItem::make('Posts')->url('/posts')->group(Section::Content),
            NavigationItem::make('Stock')->url('/stock')->group(Section::Shop),
        ]);

    $groups = $panel->getNavigation();

    expect(array_map(fn ($group) => $group->getLabel(), $groups))->toBe(['Content', 'Shop floor'])
        ->and($groups[1]->getIcon())->toBe('shopping-bag')
        ->and(array_map(fn ($item) => $item->getLabel(), $groups[1]->getItems()))->toBe(['Orders', 'Stock']);

    // Without a definition the group still takes its icon from the enum.
    $undefined = Panel::make('enum-nav-2')->navigationItems([NavigationItem::make('Orders')->url('/orders')->group(Section::Shop)])->getNavigation();

    expect($undefined[0]->getLabel())->toBe('Shop floor')
        ->and($undefined[0]->getIcon())->toBe('shopping-bag');
});
