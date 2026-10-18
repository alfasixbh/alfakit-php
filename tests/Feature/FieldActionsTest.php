<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Actions\Action;
use AlfaSix\Alfakit\Forms\Components\Repeater;
use AlfaSix\Alfakit\Forms\Components\Select;
use AlfaSix\Alfakit\Forms\Components\TextInput;
use AlfaSix\Alfakit\Infolists\Components\TextEntry;
use AlfaSix\Alfakit\Schemas\Components\Section;
use AlfaSix\Alfakit\Schemas\Components\Utilities\Set;
use AlfaSix\Alfakit\Tests\Fixtures\FormsB\FormsBPage;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Post;
use AlfaSix\Alfakit\Tests\Fixtures\Models\User;
use Illuminate\Support\Facades\Gate;

beforeEach(function (): void {
    FormsBPage::reset();
    $this->user = User::query()->create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'password']);
    $this->actingAs($this->user);
});

afterEach(fn () => FormsBPage::reset());

function fieldAction(string $field, string $action, array $state = [], array $data = [], array $extra = []): Illuminate\Testing\TestResponse
{
    return test()->postJson('/admin/_alfakit/actions', [
        'token' => FormsBPage::token(test()->user),
        'component' => "field:form::{$field}",
        'action' => $action,
        'state' => $state,
        'data' => $data,
        ...$extra,
    ]);
}

it('serialises prefix, suffix and hint actions on fields and entries', function (): void {
    $field = TextInput::make('code')
        ->prefixAction(Action::make('generate')->icon('sparkles'))
        ->suffixActions([Action::make('copy'), Action::make('secret')->hidden()])
        ->hintAction(Action::make('help')->url('https://example.com'));

    $schema = schema([$field])->fill();
    $serialized = $schema->toSchema()[0];

    expect(collect($serialized['prefixActions'])->pluck('name')->all())->toBe(['generate'])
        ->and(collect($serialized['suffixActions'])->pluck('name')->all())->toBe(['copy'])
        ->and($serialized['hintActions'][0]['url'])->toBe('https://example.com')
        ->and($schema->toPayload())->toHaveKey('fingerprints');

    $entry = TextEntry::make('title')->hintAction(Action::make('copyTitle'))->state('Hi');

    expect(schema([$entry])->toSchema()[0]['hintActions'][0]['name'])->toBe('copyTitle');

    // A disabled field shows its actions disabled.
    $disabled = schema([TextInput::make('code')->disabled()->suffixAction(Action::make('copy'))])->fill()->toSchema()[0];

    expect($disabled['suffixActions'][0]['disabled'])->toBeTrue();
});

it('runs a field action with the field context and returns the refreshed form', function (): void {
    FormsBPage::$formUsing = fn (): array => [
        TextInput::make('title'),
        TextInput::make('slug')->suffixAction(
            Action::make('generate')->action(function (Set $set, ?string $state, $get, $component): void {
                expect($component->getName())->toBe('slug')->and($state)->toBe('old');
                $set('slug', str($get('title'))->slug()->toString());
            }),
        ),
    ];

    fieldAction('slug', 'generate', ['title' => 'Hello World', 'slug' => 'old'])
        ->assertOk()
        ->assertJsonPath('status', 'success')
        ->assertJsonPath('form.state.slug', 'hello-world')
        ->assertJsonPath('form.state.title', 'Hello World');
});

it('answers a field action with only the changed parts when fingerprints are sent', function (): void {
    FormsBPage::$formUsing = fn (): array => [
        Section::make('Main')->schema([
            TextInput::make('title'),
            TextInput::make('slug')
                ->helperText(fn ($get): string => 'Slug for '.$get('title'))
                ->suffixAction(Action::make('fill')->action(fn (Set $set) => $set('title', 'Changed'))),
        ]),
        TextInput::make('other'),
    ];

    $token = FormsBPage::token($this->user);
    $initial = schema((FormsBPage::$formUsing)())->fill(['title' => 'A', 'slug' => 's', 'other' => 'o'])->toPayload();

    $response = fieldAction('slug', 'fill', ['title' => 'A', 'slug' => 's', 'other' => 'o'], [], ['fingerprints' => $initial['fingerprints'], 'token' => $token])
        ->assertOk()
        ->assertJsonPath('form.partial', true)
        ->assertJsonPath('form.partialState', true);

    $form = $response->json('form');

    // The title changed (state) and the slug's helper text changed (component); the
    // section itself and the untouched input are not resent.
    expect($form['state'])->toBe(['title' => 'Changed'])
        ->and(collect($form['patches'])->pluck('path')->all())->toBe(['0.1'])
        ->and($form['patches'][0]['component']['helperText'])->toBe('Slug for Changed');
});

it('refuses actions of hidden or disabled fields and unknown actions', function (): void {
    FormsBPage::$formUsing = fn (): array => [
        TextInput::make('secret')->hidden()->suffixAction(Action::make('reveal')->action(fn () => null)),
        TextInput::make('locked')->disabled()->suffixAction(Action::make('unlock')->action(fn () => null)),
        TextInput::make('open')->suffixAction(Action::make('private')->authorize(false)->action(fn () => null)),
    ];

    fieldAction('secret', 'reveal')->assertNotFound();
    fieldAction('locked', 'unlock')->assertForbidden();
    fieldAction('open', 'missing')->assertNotFound();
    fieldAction('open', 'private')->assertForbidden();

    $this->postJson('/admin/_alfakit/actions', [
        'token' => FormsBPage::token($this->user),
        'component' => 'field:no-separator',
        'action' => 'x',
    ])->assertStatus(400);
});

it('opens field action modals over POST with the form state', function (): void {
    FormsBPage::$formUsing = fn (): array => [
        TextInput::make('title')->hintAction(
            Action::make('rename')
                ->schema([TextInput::make('name')->required()])
                ->fillForm(fn ($get): array => ['name' => $get('title')])
                ->action(fn (array $data, Set $set) => $set('title', $data['name'])),
        ),
    ];

    $this->postJson('/admin/_alfakit/actions/modal', [
        'token' => FormsBPage::token($this->user),
        'component' => 'field:form::title',
        'action' => 'rename',
        'state' => ['title' => 'Current'],
    ])->assertOk()->assertJsonPath('form.state.name', 'Current');

    fieldAction('title', 'rename', ['title' => 'Current'], ['name' => ''])->assertStatus(422)->assertJsonValidationErrors(['name']);
    fieldAction('title', 'rename', ['title' => 'Current'], ['name' => 'Renamed'])->assertOk()->assertJsonPath('form.state.title', 'Renamed');
});

it('finds fields inside repeater items that only exist in the browser', function (): void {
    FormsBPage::$formUsing = fn (): array => [
        Repeater::make('lines')->schema([
            TextInput::make('sku')->suffixAction(Action::make('upper')->action(fn (Set $set, ?string $state) => $set('sku', strtoupper((string) $state)))),
        ])->defaultItems(0),
    ];

    fieldAction('lines.0.sku', 'upper', ['lines' => [['sku' => 'abc']]])
        ->assertOk()
        ->assertJsonPath('form.state.lines.0.sku', 'ABC');
});

it('runs hint actions of infolist entries', function (): void {
    $post = Post::query()->create(['title' => 'Hello', 'slug' => 'hello', 'author_id' => $this->user->id]);

    FormsBPage::$infolistUsing = fn (): array => [
        TextEntry::make('title')->hintAction(Action::make('ping')->action(fn ($record): string => 'pong '.$record->title)),
    ];

    $this->postJson('/admin/_alfakit/actions', [
        'token' => FormsBPage::token($this->user, $post),
        'component' => 'field:infolist::title',
        'action' => 'ping',
    ])->assertOk()->assertJsonPath('result', 'pong Hello');
});

it('creates a related record from a select and selects it', function (): void {
    FormsBPage::$formUsing = fn (): array => [
        Select::make('author_id')->relationship('author', 'name')->searchable()->createOptionForm([
            TextInput::make('name')->required(),
            TextInput::make('email')->required()->email(),
            TextInput::make('password')->required(),
        ]),
    ];

    $schema = schema((FormsBPage::$formUsing)(), model: Post::class)->fill();

    expect(collect($schema->toSchema()[0]['suffixActions'])->pluck('name')->all())->toBe(['createOption']);

    $this->postJson('/admin/_alfakit/actions/modal', [
        'token' => FormsBPage::token($this->user),
        'component' => 'field:form::author_id',
        'action' => 'createOption',
        'state' => ['author_id' => null],
    ])->assertOk()->assertJsonPath('heading', 'Create author');

    fieldAction('author_id', 'createOption', ['author_id' => null], ['name' => ''])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['name', 'email']);

    $response = fieldAction('author_id', 'createOption', ['author_id' => null], ['name' => 'Zed', 'email' => 'zed@example.com', 'password' => 'secret'])
        ->assertOk();

    $zed = User::query()->where('email', 'zed@example.com')->firstOrFail();

    expect($response->json('form.state.author_id'))->toBe((string) $zed->id)
        ->and($response->json('form.components.0.selectedLabels'))->toBe([(string) $zed->id => 'Zed']);
});

it('adds a created option to a multiple select', function (): void {
    $tag = AlfaSix\Alfakit\Tests\Fixtures\Models\Tag::query()->create(['name' => 'php']);

    FormsBPage::$formUsing = fn (): array => [
        Select::make('tags')->relationship('tags', 'name')->multiple()->preload()->createOptionForm([TextInput::make('name')->required()]),
    ];

    $response = fieldAction('tags', 'createOption', ['tags' => [(string) $tag->id]], ['name' => 'laravel'])->assertOk();
    $laravel = AlfaSix\Alfakit\Tests\Fixtures\Models\Tag::query()->where('name', 'laravel')->firstOrFail();

    expect($response->json('form.state.tags'))->toBe([(string) $tag->id, (string) $laravel->id])
        ->and(collect($response->json('form.components.0.options'))->pluck('label')->all())->toContain('laravel');
});

it('edits the selected related record', function (): void {
    $other = User::query()->create(['name' => 'Old name', 'email' => 'old@example.com', 'password' => 'x']);

    FormsBPage::$formUsing = fn (): array => [
        Select::make('author_id')->relationship('author', 'name')->editOptionForm([TextInput::make('name')->required()]),
    ];

    // Nothing selected: no edit button.
    expect(schema((FormsBPage::$formUsing)(), model: Post::class)->fill()->toSchema()[0])->not->toHaveKey('suffixActions');

    $this->postJson('/admin/_alfakit/actions/modal', [
        'token' => FormsBPage::token($this->user),
        'component' => 'field:form::author_id',
        'action' => 'editOption',
        'state' => ['author_id' => (string) $other->id],
    ])->assertOk()->assertJsonPath('form.state.name', 'Old name');

    $response = fieldAction('author_id', 'editOption', ['author_id' => (string) $other->id], ['name' => 'New name'])->assertOk();

    expect($other->fresh()->name)->toBe('New name')
        ->and(collect($response->json('form.components.0.options'))->firstWhere('value', (string) $other->id)['label'])->toBe('New name');

    // An option outside the relationship query can't be edited.
    fieldAction('author_id', 'editOption', ['author_id' => '999'], ['name' => 'x'])->assertForbidden();
});

it('checks the related model policy before creating or editing options', function (): void {
    Gate::policy(User::class, FormsBDenyUserPolicy::class);

    FormsBPage::$formUsing = fn (): array => [
        Select::make('author_id')->relationship('author', 'name')
            ->createOptionForm([TextInput::make('name')])
            ->editOptionForm([TextInput::make('name')]),
    ];

    fieldAction('author_id', 'createOption', ['author_id' => null], ['name' => 'Nope'])->assertForbidden();
    fieldAction('author_id', 'editOption', ['author_id' => (string) $this->user->id], ['name' => 'Nope'])->assertForbidden();

    expect(User::query()->where('name', 'Nope')->exists())->toBeFalse()
        ->and($this->user->fresh()->name)->toBe('Admin');
});

it('creates options with a custom callback for selects without a relationship', function (): void {
    FormsBPage::$formUsing = fn (): array => [
        Select::make('status')->options(['draft' => 'Draft'])
            ->createOptionForm([TextInput::make('label')->required()])
            ->createOptionUsing(fn (array $data): string => str($data['label'])->slug()->toString()),
    ];

    fieldAction('status', 'createOption', ['status' => null], ['label' => 'In Review'])
        ->assertOk()
        ->assertJsonPath('form.state.status', 'in-review');
});

it('hides the option buttons of read-only selects', function (): void {
    $field = Select::make('author_id')->relationship('author', 'name')->readOnly()->createOptionForm([TextInput::make('name')]);

    expect(schema([$field], model: Post::class)->fill()->toSchema()[0])->not->toHaveKey('suffixActions');
});

class FormsBDenyUserPolicy
{
    public function create(): bool
    {
        return false;
    }

    public function update(): bool
    {
        return false;
    }
}
