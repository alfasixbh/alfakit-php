<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Actions\Action;
use AlfaSix\Alfakit\Actions\ActionGroup;
use AlfaSix\Alfakit\Forms\Components\TextInput;
use AlfaSix\Alfakit\Schemas\Components\Callout;
use AlfaSix\Alfakit\Schemas\Components\EmptyState;
use AlfaSix\Alfakit\Schemas\Components\Section;
use AlfaSix\Alfakit\Tests\Fixtures\Models\User;
use AlfaSix\Alfakit\Tests\Fixtures\SchemaActions\SchemaActionsPage;

it('serialises action groups in section header and footer actions', function (): void {
    $serialized = schema([
        Section::make('Details')
            ->headerActions([
                Action::make('edit'),
                ActionGroup::make([Action::make('copy'), Action::make('gone')->hidden()])->label('More'),
                ActionGroup::make([Action::make('hiddenOnly')->hidden()]),
            ])
            ->footerActions([fn (): array => [ActionGroup::make([Action::make('export')])]])
            ->schema([TextInput::make('title')]),
    ])->fill()->toSchema();

    $header = $serialized[0]['headerActions'];

    expect(collect($header)->pluck('type')->all())->toBe(['Action', 'ActionGroup'])
        ->and($header[1]['label'])->toBe('More')
        ->and(collect($header[1]['actions'])->pluck('name')->all())->toBe(['copy'])
        ->and($serialized[0]['footerActions'][0]['type'])->toBe('ActionGroup')
        ->and($serialized[0]['footerActions'][0]['actions'][0]['name'])->toBe('export');
});

it('binds grouped header actions to the section', function (): void {
    $section = Section::make('Details')->headerActions([
        ActionGroup::make([Action::make('copy')]),
        fn (): Action => Action::make('lazy'),
    ]);

    $actions = $section->getHeaderActions();

    expect($actions)->toHaveCount(2)
        ->and($actions[0])->toBeInstanceOf(ActionGroup::class)
        ->and($actions[0]->getFlatActions()[0]->getSchemaComponent())->toBe($section)
        ->and($actions[1]->getSchemaComponent())->toBe($section)
        ->and(collect($section->getAllActions())->map->getName()->all())->toBe(['copy', 'lazy']);
});

it('serialises action groups in callout and empty state actions', function (): void {
    $serialized = schema([
        Callout::make('Tip')
            ->actions([ActionGroup::make([Action::make('upgrade')])])
            ->controlActions([ActionGroup::make([Action::make('snooze')])->iconButton()]),
        EmptyState::make('Nothing here')->actions([ActionGroup::make([Action::make('create')])]),
    ])->fill()->toSchema();

    expect($serialized[0]['actions'][0]['type'])->toBe('ActionGroup')
        ->and($serialized[0]['actions'][0]['actions'][0]['name'])->toBe('upgrade')
        ->and($serialized[0]['controls'][0]['action']['type'])->toBe('ActionGroup')
        ->and($serialized[1]['actions'][0]['actions'][0]['name'])->toBe('create');
});

it('runs grouped section, callout and empty state actions through the page action endpoint', function (): void {
    $user = User::query()->create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'password']);
    $this->actingAs($user);
    $ran = [];
    $record = function (string $name) use (&$ran): Closure {
        return function () use (&$ran, $name): void {
            $ran[] = $name;
        };
    };
    SchemaActionsPage::reset();
    SchemaActionsPage::$formUsing = fn (): array => [
        Section::make('Details')
            ->headerActions([ActionGroup::make([Action::make('copy')->action($record('copy'))])])
            ->footerActions([ActionGroup::make([ActionGroup::make([Action::make('nested')->action($record('nested'))])])])
            ->schema([TextInput::make('title')]),
        Callout::make('Tip')
            ->actions([ActionGroup::make([Action::make('upgrade')->action($record('upgrade'))])])
            ->controlActions([ActionGroup::make([Action::make('snooze')->action($record('snooze'))])]),
        EmptyState::make('Nothing')->actions([ActionGroup::make([Action::make('create')->action($record('create'))])]),
    ];

    foreach (['copy', 'nested', 'upgrade', 'snooze', 'create'] as $name) {
        $this->postJson('/admin/_alfakit/actions', [
            'token' => SchemaActionsPage::token($user),
            'component' => 'page',
            'action' => $name,
            'state' => ['title' => 'x'],
        ])->assertOk();
    }

    expect($ran)->toBe(['copy', 'nested', 'upgrade', 'snooze', 'create']);
    SchemaActionsPage::reset();
});

it('does not run a hidden action inside a section header group', function (): void {
    $user = User::query()->create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'password']);
    $this->actingAs($user);
    SchemaActionsPage::reset();
    SchemaActionsPage::$formUsing = fn (): array => [
        Section::make('Details')->headerActions([
            ActionGroup::make([Action::make('forbidden')->hidden()->action(fn () => throw new RuntimeException('ran'))]),
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

it('gives callout, empty state and mixed-content actions $get and $set like section header actions', function (): void {
    $user = User::query()->create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'password']);
    $this->actingAs($user);
    $seen = [];
    $read = function (string $name) use (&$seen): Closure {
        return function (AlfaSix\Alfakit\Schemas\Components\Utilities\Get $get, AlfaSix\Alfakit\Schemas\Components\Utilities\Set $set) use (&$seen, $name): void {
            $seen[$name] = $get('title');
            $set('title', $name);
        };
    };
    SchemaActionsPage::reset();
    SchemaActionsPage::$formUsing = fn (): array => [
        TextInput::make('title'),
        Section::make('Details')
            ->key('details')
            ->headerActions([Action::make('header')->action($read('header'))])
            ->afterHeader([Action::make('afterHeader')->action($read('afterHeader'))])
            ->footer([Action::make('sectionFooter')->action($read('sectionFooter'))])
            ->schema([]),
        Callout::make('Tip')
            ->key('tip')
            ->actions([Action::make('pay')->action($read('pay'))])
            ->controlActions([Action::make('dismiss')->action($read('dismiss'))])
            ->footer([Action::make('calloutFooter')->action($read('calloutFooter'))]),
        EmptyState::make('Nothing')
            ->key('nothing')
            ->actions([Action::make('create')->action($read('create'))])
            ->footer([Action::make('import')->action($read('import'))]),
    ];

    $names = ['header', 'afterHeader', 'sectionFooter', 'pay', 'dismiss', 'calloutFooter', 'create', 'import'];

    foreach ($names as $name) {
        $this->postJson('/admin/_alfakit/actions', [
            'token' => SchemaActionsPage::token($user),
            'component' => 'page',
            'action' => $name,
            'state' => ['title' => 'x'],
        ])->assertOk();
    }

    // Each action got the same form context as the section's header action.
    expect(array_keys($seen))->toBe($names)
        ->and(array_unique($seen))->toHaveCount(1);

    // As the renderers post them inside a form: through the field action endpoint of the
    // owning component, with the form's state, so `$get` reads it and `$set` writes back.
    $owners = ['header' => 'details', 'afterHeader' => 'details', 'sectionFooter' => 'details', 'pay' => 'tip', 'dismiss' => 'tip', 'calloutFooter' => 'tip', 'create' => 'nothing', 'import' => 'nothing'];
    $seen = [];

    foreach ($owners as $name => $owner) {
        $response = $this->postJson('/admin/_alfakit/actions', [
            'token' => SchemaActionsPage::token($user),
            'component' => 'field:form::'.$owner,
            'action' => $name,
            'state' => ['title' => 'x'],
        ])->assertOk();

        expect($response->json('form.state.title'))->toBe($name);
    }

    expect($seen)->toBe(array_fill_keys($names, 'x'));
    SchemaActionsPage::reset();
});
