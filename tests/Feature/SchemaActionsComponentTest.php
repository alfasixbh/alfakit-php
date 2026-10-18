<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Actions\Action;
use AlfaSix\Alfakit\Actions\ActionGroup;
use AlfaSix\Alfakit\Forms\Components\TextInput;
use AlfaSix\Alfakit\Infolists\Components\TextEntry;
use AlfaSix\Alfakit\Schemas\Components\Actions;
use AlfaSix\Alfakit\Schemas\Components\Grid;
use AlfaSix\Alfakit\Schemas\Components\Utilities\Set;
use AlfaSix\Alfakit\Support\Enums\Alignment;
use AlfaSix\Alfakit\Support\Enums\VerticalAlignment;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Post;
use AlfaSix\Alfakit\Tests\Fixtures\Models\User;
use AlfaSix\Alfakit\Tests\Fixtures\SchemaActions\SchemaActionsPage;

beforeEach(function (): void {
    SchemaActionsPage::reset();
    $this->user = User::query()->create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'password']);
    $this->actingAs($this->user);
});

afterEach(fn () => SchemaActionsPage::reset());

function schemaActionsRequest(string $component, string $action, array $state = [], array $extra = []): Illuminate\Testing\TestResponse
{
    return test()->postJson('/admin/_alfakit/actions', [
        'token' => SchemaActionsPage::token(test()->user),
        'component' => $component,
        'action' => $action,
        'state' => $state,
        ...$extra,
    ]);
}

it('serialises the actions with alignment, vertical alignment and full width', function (): void {
    $serialized = schema([
        Actions::make([
            Action::make('star')->icon('star'),
            Action::make('secret')->hidden(),
            ActionGroup::make([Action::make('archive'), Action::make('delete')]),
        ])->alignment(Alignment::Center)->verticalAlignment(VerticalAlignment::End)->fullWidth(),
        Actions::make(fn (): array => [Action::make('plain')])->alignEnd(),
    ])->fill()->toSchema();

    expect($serialized[0]['type'])->toBe('Actions')
        ->and($serialized[0]['key'])->toBeString()
        ->and(collect($serialized[0]['actions'])->pluck('name')->filter()->values()->all())->toBe(['star'])
        ->and($serialized[0]['actions'][1]['type'])->toBe('ActionGroup')
        ->and($serialized[0]['alignment'])->toBe('center')
        ->and($serialized[0]['verticalAlignment'])->toBe('end')
        ->and($serialized[0]['fullWidth'])->toBeTrue()
        ->and($serialized[0])->not->toHaveKey('label')
        ->and($serialized[1]['alignment'])->toBe('end')
        ->and($serialized[1])->not->toHaveKey('fullWidth');
});

it('runs an action of the component in the form with $get and $set', function (): void {
    SchemaActionsPage::$formUsing = fn (): array => [
        TextInput::make('title'),
        Grid::make(2)->schema([
            TextInput::make('rating'),
            Actions::make([
                Action::make('star')->action(function (Set $set, $get): void {
                    $set('rating', $get('title') === 'Great' ? '5' : '1');
                }),
                ActionGroup::make([
                    Action::make('reset')->action(fn (Set $set) => $set('rating', null)),
                ]),
            ])->key('ratingActions'),
        ]),
    ];

    schemaActionsRequest('field:form::ratingActions', 'star', ['title' => 'Great', 'rating' => '2'])
        ->assertOk()
        ->assertJsonPath('status', 'success')
        ->assertJsonPath('form.state.rating', '5');

    // Actions inside a group are resolvable too.
    schemaActionsRequest('field:form::ratingActions', 'reset', ['title' => 'Great', 'rating' => '2'])
        ->assertOk()
        ->assertJsonPath('form.state.rating', null);

    schemaActionsRequest('field:form::ratingActions', 'missing')->assertNotFound();
});

it('resolves the component by its generated key', function (): void {
    SchemaActionsPage::$formUsing = fn (): array => [
        TextInput::make('rating'),
        Actions::make([Action::make('star')->action(fn (Set $set) => $set('rating', '5'))]),
    ];

    $key = schema((SchemaActionsPage::$formUsing)())->fill()->toSchema()[1]['key'];

    schemaActionsRequest("field:form::{$key}", 'star', ['rating' => '1'])
        ->assertOk()
        ->assertJsonPath('form.state.rating', '5');
});

it('refuses actions of hidden components, unauthorised and hidden actions', function (): void {
    SchemaActionsPage::$formUsing = fn (): array => [
        Actions::make([Action::make('run')->action(fn () => null)])->key('hiddenRow')->hidden(),
        Actions::make([
            Action::make('private')->authorize(false)->action(fn () => null),
            Action::make('invisible')->hidden()->action(fn () => null),
        ])->key('row'),
        Actions::make([Action::make('locked')->action(fn () => null)])->key('lockedRow')->disabled(),
    ];

    schemaActionsRequest('field:form::hiddenRow', 'run')->assertNotFound();
    schemaActionsRequest('field:form::row', 'private')->assertForbidden();
    schemaActionsRequest('field:form::row', 'invisible')->assertForbidden();
    schemaActionsRequest('field:form::lockedRow', 'locked')->assertForbidden();

    $locked = schema((SchemaActionsPage::$formUsing)())->fill()->toSchema();

    expect(collect($locked)->firstWhere('key', 'lockedRow')['actions'][0]['disabled'])->toBeTrue();
});

it('runs the actions of an infolist with the record', function (): void {
    $post = Post::query()->create(['title' => 'Hello', 'slug' => 'hello']);

    SchemaActionsPage::$infolistUsing = fn (): array => [
        TextEntry::make('title'),
        Actions::make([
            Action::make('publish')->action(fn (Post $record) => $record->update(['is_published' => true])),
        ])->key('postActions'),
    ];

    $this->postJson('/admin/_alfakit/actions', [
        'token' => SchemaActionsPage::token($this->user, $post),
        'component' => 'field:infolist::postActions',
        'action' => 'publish',
    ])->assertOk()->assertJsonPath('status', 'success');

    expect($post->refresh()->is_published)->toBeTrue();
});

it('lets pages resolve the actions by name like section actions', function (): void {
    SchemaActionsPage::$formUsing = fn (): array => [
        Actions::make([Action::make('star'), ActionGroup::make([Action::make('grouped')])]),
        Actions::make([Action::make('notShown')])->hidden(),
    ];

    $page = app(SchemaActionsPage::class);

    expect($page->getAction('star'))->not->toBeNull()
        ->and($page->getAction('grouped'))->not->toBeNull()
        ->and($page->getAction('notShown'))->toBeNull();
});

it('runs the component inside an action modal form', function (): void {
    SchemaActionsPage::$headerActionsUsing = fn (): array => [
        Action::make('review')
            ->schema([
                TextInput::make('score'),
                Actions::make([
                    Action::make('max')->action(fn (Set $set) => $set('score', '10')),
                ])->key('scoreActions'),
            ])
            ->action(fn () => null),
    ];

    schemaActionsRequest('field:action:page.review::scoreActions', 'max', ['score' => '3'])
        ->assertOk()
        ->assertJsonPath('status', 'success')
        ->assertJsonPath('form.state.score', '10');
});

it('does not run component actions inside a hidden modal action', function (): void {
    SchemaActionsPage::$headerActionsUsing = fn (): array => [
        Action::make('review')
            ->hidden()
            ->schema([Actions::make([Action::make('max')->action(fn () => null)])->key('scoreActions')]),
    ];

    schemaActionsRequest('field:action:page.review::scoreActions', 'max')->assertForbidden();
});

it('runs section header and footer actions in the form with $get and $set', function (): void {
    SchemaActionsPage::$formUsing = fn (): array => [
        TextInput::make('title'),
        AlfaSix\Alfakit\Schemas\Components\Section::make('Rating')
            ->key('ratingSection')
            ->headerActions([
                Action::make('star')->action(function (Set $set, $get): void {
                    $set('rating', $get('title') === 'Great' ? '5' : '1');
                }),
            ])
            ->footerActions([
                Action::make('clear')->action(fn (Set $set) => $set('rating', null)),
            ])
            ->schema([TextInput::make('rating')]),
    ];

    schemaActionsRequest('field:form::ratingSection', 'star', ['title' => 'Great', 'rating' => '2'])
        ->assertOk()
        ->assertJsonPath('form.state.rating', '5');

    schemaActionsRequest('field:form::ratingSection', 'clear', ['title' => 'Great', 'rating' => '2'])
        ->assertOk()
        ->assertJsonPath('form.state.rating', null);
});
