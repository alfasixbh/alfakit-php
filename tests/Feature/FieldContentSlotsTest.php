<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Actions\Action;
use AlfaSix\Alfakit\Actions\ActionGroup;
use AlfaSix\Alfakit\Forms\Components\Select;
use AlfaSix\Alfakit\Forms\Components\TextInput;
use AlfaSix\Alfakit\Infolists\Components\TextEntry;
use AlfaSix\Alfakit\Schemas\Components\FusedGroup;
use AlfaSix\Alfakit\Schemas\Components\Icon;
use AlfaSix\Alfakit\Schemas\Components\Text;
use AlfaSix\Alfakit\Schemas\Components\Utilities\Set;
use AlfaSix\Alfakit\Support\Enums\Alignment;
use AlfaSix\Alfakit\Tests\Fixtures\FormsB\FormsBPage;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Post;
use AlfaSix\Alfakit\Tests\Fixtures\Models\User;
use Illuminate\Support\HtmlString;

beforeEach(function (): void {
    FormsBPage::reset();
    $this->user = User::query()->create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'password']);
    $this->actingAs($this->user);
});

afterEach(fn () => FormsBPage::reset());

function slotFieldAction(string $field, string $action, array $state = []): Illuminate\Testing\TestResponse
{
    return test()->postJson('/admin/_alfakit/actions', [
        'token' => FormsBPage::token(test()->user),
        'component' => "field:form::{$field}",
        'action' => $action,
        'state' => $state,
        'data' => [],
    ]);
}

it('serialises strings, html, components and actions in field slots', function (): void {
    $field = TextInput::make('name')
        ->aboveLabel('Above the label')
        ->beforeLabel(Icon::make('user'))
        ->afterLabel([Text::make('Optional'), Action::make('help')])
        ->belowLabel(new HtmlString('<strong>Bold</strong>'))
        ->aboveContent(fn (): string => 'Computed')
        ->beforeContent(Icon::make('at-sign'))
        ->afterContent(Action::make('generate'), Alignment::End)
        ->belowContent(['One', 'Two'], 'between')
        ->aboveErrorMessage('Check the spelling')
        ->belowErrorMessage(Action::make('reset'));

    $slots = schema([$field])->fill()->toSchema()[0]['slots'];

    expect(array_keys($slots))->toBe([
        'aboveLabel', 'beforeLabel', 'afterLabel', 'belowLabel', 'aboveContent', 'beforeContent',
        'afterContent', 'belowContent', 'aboveErrorMessage', 'belowErrorMessage',
    ])
        ->and($slots['aboveLabel']['items'][0]['component'])->toMatchArray(['type' => 'Text', 'content' => 'Above the label'])
        ->and($slots['aboveLabel']['alignment'])->toBe('start')
        ->and($slots['beforeLabel']['items'][0]['component'])->toMatchArray(['type' => 'Icon', 'icon' => 'user'])
        ->and($slots['afterLabel']['alignment'])->toBe('end')
        ->and($slots['afterLabel']['items'][0]['component']['content'])->toBe('Optional')
        ->and($slots['afterLabel']['items'][1]['action']['name'])->toBe('help')
        ->and($slots['belowLabel']['items'][0]['component']['type'])->toBe('Html')
        ->and($slots['belowLabel']['items'][0]['component']['content'])->toBe('<strong>Bold</strong>')
        ->and($slots['aboveContent']['items'][0]['component']['content'])->toBe('Computed')
        ->and($slots['afterContent']['alignment'])->toBe('end')
        ->and($slots['belowContent']['alignment'])->toBe('between')
        ->and(collect($slots['belowContent']['items'])->pluck('component.content')->all())->toBe(['One', 'Two'])
        ->and($slots['belowErrorMessage']['items'][0]['action']['name'])->toBe('reset');

    // Each slot component gets its own key, so renderers can tell them apart.
    $keys = collect($slots)->flatMap(fn (array $slot) => collect($slot['items'])->pluck('component.key')->filter())->all();

    expect($keys)->toBe(array_unique($keys));
});

it('escapes plain strings in slots', function (): void {
    $slots = schema([TextInput::make('name')->belowContent('<b>not bold</b>')])->fill()->toSchema()[0]['slots'];

    // Strings become Text components, which renderers print as text.
    expect($slots['belowContent']['items'][0]['component'])->toMatchArray(['type' => 'Text', 'content' => '<b>not bold</b>']);
});

it('leaves out empty slots, hidden components and hidden actions', function (): void {
    $field = TextInput::make('name')
        ->aboveLabel(null)
        ->belowContent(fn (): array => [])
        ->afterContent([Text::make('secret')->hidden(), Action::make('private')->hidden()]);

    expect(schema([$field])->fill()->toSchema()[0])->not->toHaveKey('slots');
});

it('injects the record and state into slot closures', function (): void {
    $post = Post::query()->create(['title' => 'Hello', 'slug' => 'hello']);

    $field = TextInput::make('title')
        ->belowContent(fn (?Post $record, ?string $state): string => "{$record?->slug}:{$state}");

    $slots = schema([$field], record: $post, operation: 'edit')->fill()->toSchema()[0]['slots'];

    expect($slots['belowContent']['items'][0]['component']['content'])->toBe('hello:Hello');
});

it('serialises entry slots and wrapper attributes', function (): void {
    $entry = TextEntry::make('title')
        ->state('Hi')
        ->afterLabel(Action::make('copy'))
        ->belowContent('Shown below')
        ->extraEntryWrapperAttributes(['class' => 'gap-4', 'data-a' => '1'])
        ->extraEntryWrapperAttributes(['data-b' => '2'], merge: true);

    $serialized = schema([$entry])->toSchema()[0];

    expect($serialized['slots']['afterLabel']['items'][0]['action']['name'])->toBe('copy')
        ->and($serialized['slots']['belowContent']['items'][0]['component']['content'])->toBe('Shown below')
        ->and($serialized['extraEntryWrapperAttributes'])->toBe(['class' => 'gap-4', 'data-a' => '1', 'data-b' => '2']);
});

it('serialises field wrapper attributes separately from extra attributes', function (): void {
    $field = TextInput::make('name')
        ->extraAttributes(['class' => 'outer'])
        ->extraFieldWrapperAttributes(fn (): array => ['class' => 'wrapper', 'data-x' => 'y']);

    $serialized = schema([$field])->fill()->toSchema()[0];

    expect($serialized['extraAttributes'])->toBe(['class' => 'outer'])
        ->and($serialized['extraFieldWrapperAttributes'])->toBe(['class' => 'wrapper', 'data-x' => 'y']);

    $replaced = TextInput::make('a')->extraFieldWrapperAttributes(['a' => 1])->extraFieldWrapperAttributes(['b' => 2]);

    expect($replaced->getExtraFieldWrapperAttributes())->toBe(['b' => 2]);
});

it('runs slot actions through the field action endpoint', function (): void {
    FormsBPage::$formUsing = fn (): array => [
        TextInput::make('title'),
        TextInput::make('slug')
            ->belowContent([
                'Generated from the title',
                Action::make('generate')->action(fn (Set $set, $get) => $set('slug', str($get('title'))->slug()->toString())),
            ])
            ->afterLabel(ActionGroup::make([
                Action::make('clear')->action(fn (Set $set) => $set('slug', null)),
            ])),
    ];

    slotFieldAction('slug', 'generate', ['title' => 'Hello World', 'slug' => ''])
        ->assertOk()
        ->assertJsonPath('form.state.slug', 'hello-world');

    slotFieldAction('slug', 'clear', ['title' => 'x', 'slug' => 'y'])
        ->assertOk()
        ->assertJsonPath('form.state.slug', null);

    slotFieldAction('slug', 'missing')->assertNotFound();
});

it('refuses slot actions of hidden or disabled fields and unauthorised slot actions', function (): void {
    FormsBPage::$formUsing = fn (): array => [
        TextInput::make('secret')->hidden()->belowContent(Action::make('reveal')->action(fn () => null)),
        TextInput::make('locked')->disabled()->belowContent(Action::make('unlock')->action(fn () => null)),
        TextInput::make('open')->belowContent(Action::make('private')->authorize(false)->action(fn () => null)),
    ];

    slotFieldAction('secret', 'reveal')->assertNotFound();
    slotFieldAction('locked', 'unlock')->assertForbidden();
    slotFieldAction('open', 'private')->assertForbidden();

    $locked = schema([TextInput::make('locked')->disabled()->belowContent(Action::make('unlock'))])->fill()->toSchema()[0];

    expect($locked['slots']['belowContent']['items'][0]['action']['disabled'])->toBeTrue();
});

it('serialises a fused group with its label, columns and child fields', function (): void {
    $group = FusedGroup::make([
        Select::make('country')->options(['bh' => 'Bahrain', 'sa' => 'Saudi Arabia'])->placeholder('Country'),
        TextInput::make('phone')->placeholder('Phone')->columnSpan(2),
    ])->label('Phone number')->columns(3);

    $schema = schema([$group])->fill(['country' => 'bh', 'phone' => '123']);
    $serialized = $schema->toSchema()[0];

    expect($serialized)->toMatchArray([
        'type' => 'FusedGroup',
        'label' => 'Phone number',
        'columns' => ['default' => 1, 'lg' => 3],
    ])
        ->and(collect($serialized['schema'])->pluck('statePath')->all())->toBe(['country', 'phone'])
        ->and($schema->getState())->toBe(['country' => 'bh', 'phone' => '123'])
        ->and(FusedGroup::make([TextInput::make('a')])->toSchema())->not->toHaveKey('label');
});
