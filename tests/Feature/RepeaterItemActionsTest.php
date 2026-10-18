<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Actions\Action;
use AlfaSix\Alfakit\Forms\Components\Builder;
use AlfaSix\Alfakit\Forms\Components\Builder\Block;
use AlfaSix\Alfakit\Forms\Components\KeyValue;
use AlfaSix\Alfakit\Forms\Components\Repeater;
use AlfaSix\Alfakit\Forms\Components\TextInput;
use AlfaSix\Alfakit\Schemas\Components\Utilities\Set;
use AlfaSix\Alfakit\Tests\Fixtures\FormsB\FormsBPage;
use AlfaSix\Alfakit\Tests\Fixtures\Models\User;

beforeEach(function (): void {
    FormsBPage::reset();
    $this->user = User::query()->create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'password']);
    $this->actingAs($this->user);
});

afterEach(fn () => FormsBPage::reset());

function runItemAction(string $field, string $action, array $state, array $arguments = [], array $data = []): Illuminate\Testing\TestResponse
{
    return test()->postJson('/admin/_alfakit/actions', [
        'token' => FormsBPage::token(test()->user),
        'component' => "field:form::{$field}",
        'action' => $action,
        'state' => $state,
        'arguments' => $arguments,
        'data' => $data,
    ]);
}

function membersRepeater(): Repeater
{
    return Repeater::make('members')->schema([TextInput::make('name')])->defaultItems(0);
}

$members = ['members' => [['name' => 'Ann'], ['name' => 'Bob'], ['name' => 'Cy']]];

it('serialises the built-in item actions and the item controls', function (): void {
    $restyled = schema([membersRepeater()->cloneable()->cloneAction(fn (Action $action) => $action->icon('files'))])
        ->fill(['members' => [['name' => 'Ann']]])->toSchema()[0];

    // Restyled only: still runs in the browser.
    expect($restyled['itemActions']['clone']['icon'])->toBe('files')
        ->and($restyled['itemActions']['clone'])->not->toHaveKey('server');

    $repeater = membersRepeater()
        ->cloneable()
        ->collapsible()
        ->reorderableWithDragAndDrop(false)
        ->addActionAlignment(AlfaSix\Alfakit\Support\Enums\Alignment::End)
        ->itemNumbers()
        ->deleteAction(fn (Action $action) => $action->requiresConfirmation());

    $props = schema([$repeater])->fill(['members' => [['name' => 'Ann']]])->toSchema()[0];

    // Only customised actions are sent; the renderer draws the others itself.
    expect(array_keys($props['itemActions']))->toBe(['delete'])
        ->and($props['itemActions']['delete']['icon'])->toBe('trash-2')
        ->and($props['itemActions']['delete']['color'])->toBe('danger')
        // Customised to confirm: it runs on the server.
        ->and($props['itemActions']['delete']['server'])->toBeTrue()
        ->and($props['itemActions']['delete']['hasModal'])->toBeTrue()
        ->and($props['reorderableWithDragAndDrop'])->toBeFalse()
        ->and($props)->not->toHaveKey('reorderableWithButtons')
        ->and($props['addActionAlignment'])->toBe('end')
        ->and($props['itemNumbers'])->toBeTrue()
        // Customised item actions are evaluated per item too.
        ->and($props['items'][0]['itemActions']['delete']['server'])->toBeTrue();
});

it('leaves out the actions the field switches off and everything when read-only', function (): void {
    $props = schema([membersRepeater()->addable(false)->deletable(false)->reorderable(false)])
        ->fill(['members' => [['name' => 'Ann']]])->toSchema()[0];

    expect(array_keys($props['itemActions'] ?? []))->toBe([]);

    $readOnly = schema([membersRepeater()->cloneable()->readOnly()])->fill(['members' => [['name' => 'Ann']]])->toSchema()[0];

    expect($readOnly)->not->toHaveKey('itemActions');
});

it('runs a customised delete action on the server with the item key', function () use ($members): void {
    $seen = new ArrayObject;

    FormsBPage::$formUsing = fn (): array => [
        membersRepeater()->deleteAction(fn (Action $action) => $action
            ->requiresConfirmation()
            ->before(function (array $arguments, Repeater $component) use ($seen): void {
                $seen[] = $component->getRawItemState($arguments['item'])['name'] ?? null;
            })),
    ];

    runItemAction('members', 'delete', $members, ['item' => '1'])
        ->assertOk()
        ->assertJsonPath('status', 'success')
        ->assertJsonPath('form.state.members', [['name' => 'Ann'], ['name' => 'Cy']]);

    expect($seen->getArrayCopy())->toBe(['Bob']);
    $seen->exchangeArray([]);

    // Unknown item keys change nothing.
    runItemAction('members', 'delete', $members, ['item' => '9'])
        ->assertJsonPath('form.state.members', $members['members']);
});

it('runs add, clone, move and reorder on the server and respects the limits', function () use ($members): void {
    FormsBPage::$formUsing = fn (): array => [
        membersRepeater()->cloneable()->maxItems(4)->minItems(3)
            ->addAction(fn (Action $action) => $action->after(fn () => null)),
    ];

    runItemAction('members', 'add', $members)->assertJsonPath('form.state.members.3', ['name' => null]);
    runItemAction('members', 'clone', $members, ['item' => '0'])
        ->assertJsonPath('form.state.members', [['name' => 'Ann'], ['name' => 'Ann'], ['name' => 'Bob'], ['name' => 'Cy']]);
    runItemAction('members', 'moveDown', $members, ['item' => '0'])
        ->assertJsonPath('form.state.members', [['name' => 'Bob'], ['name' => 'Ann'], ['name' => 'Cy']]);
    runItemAction('members', 'moveUp', $members, ['item' => '0'])
        ->assertJsonPath('form.state.members', $members['members']);
    runItemAction('members', 'reorder', $members, ['items' => ['2', '0', '1']])
        ->assertJsonPath('form.state.members', [['name' => 'Cy'], ['name' => 'Ann'], ['name' => 'Bob']]);
    // Not a permutation of the item keys: ignored.
    runItemAction('members', 'reorder', $members, ['items' => ['2', '2', '1']])
        ->assertJsonPath('form.state.members', $members['members']);
    // `minItems(3)`: deleting is refused.
    runItemAction('members', 'delete', $members, ['item' => '0'])
        ->assertJsonPath('form.state.members', $members['members']);

    $full = ['members' => [...$members['members'], ['name' => 'Di']]];

    runItemAction('members', 'add', $full)->assertJsonPath('form.state.members', $full['members']);
    runItemAction('members', 'clone', $full, ['item' => '0'])->assertJsonPath('form.state.members', $full['members']);
});

it('refuses switched off, hidden and unauthorised item actions', function () use ($members): void {
    FormsBPage::$formUsing = fn (): array => [
        membersRepeater()->deletable(false)
            ->cloneable()
            ->cloneAction(fn (Action $action) => $action->authorize(false))
            ->moveUpAction(fn (Action $action) => $action->hidden(fn (array $arguments): bool => $arguments['item'] === '1')),
        membersRepeater()->name('locked')->statePath('locked')->disabled(),
    ];

    runItemAction('members', 'delete', $members, ['item' => '0'])->assertNotFound();
    runItemAction('members', 'clone', $members, ['item' => '0'])->assertForbidden();
    runItemAction('members', 'moveUp', $members, ['item' => '1'])->assertForbidden();
    runItemAction('members', 'moveUp', $members, ['item' => '2'])
        ->assertJsonPath('form.state.members', [['name' => 'Ann'], ['name' => 'Cy'], ['name' => 'Bob']]);
    runItemAction('locked', 'add', ['locked' => []])->assertForbidden();
});

it('hides a per-item action for that item only', function (): void {
    $repeater = membersRepeater()->deleteAction(fn (Action $action) => $action->hidden(fn (array $arguments): bool => $arguments['item'] === '0'));

    $props = schema([$repeater])->fill(['members' => [['name' => 'Ann'], ['name' => 'Bob']]])->toSchema()[0];

    expect($props['items'][0]['itemActions']['delete'])->toBeNull()
        ->and($props['items'][1]['itemActions']['delete']['name'])->toBe('delete')
        ->and($props['itemActions']['delete']['name'])->toBe('delete')
        ->and($props['deletable'])->toBeTrue();
});

it('treats a hidden add or delete action as add / delete switched off', function (): void {
    $repeater = membersRepeater()->deleteAction(fn (Action $action) => $action->hidden());
    $schema = schema([$repeater])->fill(['members' => [['name' => 'Ann'], ['name' => 'Bob']]]);
    $props = $schema->toSchema()[0];

    expect($props['deletable'])->toBeFalse()
        ->and($props['itemActions']['delete'])->toBeNull();

    // The browser deleting anyway fails validation, as with `deletable(false)`.
    $schema->applyClientState(['members' => [['name' => 'Ann']]]);

    expect(fn () => $schema->validate())->toThrow(Illuminate\Validation\ValidationException::class);
});

it('runs extra item actions with the item key and lets them change the state', function () use ($members): void {
    FormsBPage::$formUsing = fn (): array => [
        membersRepeater()->extraItemActions([
            Action::make('shout')
                ->icon('megaphone')
                ->visible(fn (array $arguments): bool => ($arguments['item'] ?? null) !== '2')
                ->action(function (array $arguments, Repeater $component): void {
                    $state = $component->getState();
                    $state[$arguments['item']]['name'] = strtoupper($component->getItemState($arguments['item'])['name']);
                    $component->state($state);
                }),
            Action::make('rename')->action(fn (array $arguments, Set $set) => $set("members.{$arguments['item']}.name", 'Renamed')),
        ]),
    ];

    $props = schema((FormsBPage::$formUsing)())->fill($members)->toSchema()[0];

    expect(collect($props['items'][0]['actions'])->pluck('name')->all())->toBe(['shout', 'rename'])
        ->and(collect($props['items'][2]['actions'])->pluck('name')->all())->toBe(['rename']);

    runItemAction('members', 'shout', $members, ['item' => '1'])
        ->assertOk()
        ->assertJsonPath('form.state.members.1.name', 'BOB');
    runItemAction('members', 'shout', $members, ['item' => '2'])->assertForbidden();
    runItemAction('members', 'rename', $members, ['item' => '0'])->assertJsonPath('form.state.members.0.name', 'Renamed');
});

it('runs the builder add action with the block argument and keeps block limits', function (): void {
    FormsBPage::$formUsing = fn (): array => [
        Builder::make('content')
            ->blocks([
                Block::make('heading')->maxItems(1)->schema([TextInput::make('text')->default('New')]),
                Block::make('secret')->hidden()->schema([TextInput::make('value')]),
            ])
            ->cloneable()
            ->addAction(fn (Action $action) => $action->requiresConfirmation()),
    ];

    runItemAction('content', 'add', ['content' => []], ['block' => 'heading'])
        ->assertJsonPath('form.state.content', [['type' => 'heading', 'data' => ['text' => 'New']]]);

    $one = ['content' => [['type' => 'heading', 'data' => ['text' => 'A']]]];

    runItemAction('content', 'add', $one, ['block' => 'heading'])->assertJsonPath('form.state.content', $one['content']);
    runItemAction('content', 'clone', $one, ['item' => '0'])->assertJsonPath('form.state.content', $one['content']);
    runItemAction('content', 'add', ['content' => []], ['block' => 'secret'])->assertJsonPath('form.state.content', []);
});

it('customises the key-value add, delete and reorder actions', function (): void {
    FormsBPage::$formUsing = fn (): array => [
        KeyValue::make('meta')
            ->reorderable()
            ->addAction(fn (Action $action) => $action->label('New pair')->icon('circle-plus'))
            ->deleteAction(fn (Action $action) => $action->requiresConfirmation()),
    ];

    $props = schema((FormsBPage::$formUsing)())->fill(['meta' => ['a' => '1']])->toSchema()[0];

    expect($props['itemActions']['add']['label'])->toBe('New pair')
        ->and($props['itemActions']['add'])->not->toHaveKey('server')
        ->and($props['itemActions']['delete']['server'])->toBeTrue()
        ->and($props['itemActions'])->not->toHaveKey('reorder');

    $state = ['meta' => [['key' => 'a', 'value' => '1'], ['key' => 'b', 'value' => '2']]];

    runItemAction('meta', 'delete', $state, ['item' => '0'])->assertJsonPath('form.state.meta', [['key' => 'b', 'value' => '2']]);
    runItemAction('meta', 'reorder', $state, ['items' => ['1', '0']])
        ->assertJsonPath('form.state.meta', [['key' => 'b', 'value' => '2'], ['key' => 'a', 'value' => '1']]);
    runItemAction('meta', 'add', ['meta' => ['a' => '1']])
        ->assertJsonPath('form.state.meta', [['key' => 'a', 'value' => '1'], ['key' => '', 'value' => '']]);
});
