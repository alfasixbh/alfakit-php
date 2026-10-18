<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Forms\Components\TextInput;
use AlfaSix\Alfakit\Panel;
use AlfaSix\Alfakit\Tests\Fixtures\FormsA\PickerRepeaterPage;
use AlfaSix\Alfakit\Tests\Fixtures\Models\User;

beforeEach(function (): void {
    $panel = Panel::make()->id('picker')->path('picker')->login()->middleware(['web'])->pages([PickerRepeaterPage::class]);

    alfakit()->registerPanel($panel);
    $panel->registerRoutes(app('router'));
    app('router')->getRoutes()->refreshNameLookups();
});

/**
 * @return array{0: string, 1: array<string, mixed>, 2: array<string, mixed>}
 */
function pickerRepeaterSession(AlfaSix\Alfakit\Tests\TestCase $test): array
{
    $user = User::query()->create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'password']);
    User::query()->create(['name' => 'Zed', 'email' => 'zed@example.com', 'password' => 'password']);
    User::query()->create(['name' => 'Banned', 'email' => 'banned@example.com', 'password' => 'password']);

    $props = inertiaProps(inertiaGet($test->actingAs($user), '/picker/picker-repeater'));
    $form = collect($props['page']['content'])->firstWhere('component', 'form');

    return [$props['page']['token'], $form['state'], $form['components'][0]];
}

it('keeps the onBlur and debounce options of live() when afterStateUpdated() follows', function (): void {
    $field = TextInput::make('qty')->live(onBlur: true, debounce: 500)->afterStateUpdated(fn () => null);

    expect($field->isLive())->toBeTrue()
        ->and($field->isLiveOnBlur())->toBeTrue()
        ->and($field->getLiveDebounce())->toBe(500)
        ->and(TextInput::make('qty')->afterStateUpdated(fn () => null)->isLive())->toBeTrue();
});

it('serialises the picker of repeater items and of the item template with their own paths and live options', function (): void {
    [, $state, $repeater] = pickerRepeaterSession($this);

    expect($state['comments'])->toHaveCount(1)
        ->and($repeater['items'][0]['schema'][0]['statePath'])->toBe('comments.0.author_name')
        ->and($repeater['items'][0]['schema'][0]['live'])->toBe(['onBlur' => true, 'debounce' => null])
        ->and($repeater['template'][0]['statePath'])->toBe('comments.__INDEX__.author_name')
        ->and($repeater['template'][0]['live'])->toBe(['onBlur' => true, 'debounce' => null]);
});

it('serves the picker table, filter options and labels of a blank item the browser added', function (): void {
    [$token, $state] = pickerRepeaterSession($this);
    $zed = User::query()->where('name', 'Zed')->value('id');
    // A second item added in the browser: the server has never rendered `comments.1`, and
    // all of its values are blank. The frontend sends GET states as JSON so it survives.
    $state['comments'][] = ['author_name' => null, 'body' => null];
    $query = static fn (array $extra): string => '/picker/_alfakit/schema/options?'.http_build_query(['token' => $token, 'component' => 'form', ...$extra]);
    $json = json_encode($state, JSON_THROW_ON_ERROR);

    $table = $this->getJson($query(['path' => 'comments.1.author_name', 'table' => 1, 'state' => $json, 'table_search' => 'ze']))->assertOk();

    expect($table->json('labels'))->toBe([(string) $zed => 'Zed']);

    $all = $this->getJson($query(['path' => 'comments.1.author_name', 'table' => 1, 'state' => $json]))->assertOk();

    expect(array_values($all->json('labels')))->toEqualCanonicalizing(['Admin', 'Zed']);

    $this->getJson($query(['pickerFilters' => 'comments.1.author_name', 'ownerState' => $json, 'path' => 'email.value', 'search' => 'zed']))
        ->assertOk()
        ->assertJsonPath('options.0.value', 'zed@example.com');

    $this->getJson($query(['path' => 'comments.1.author_name', 'values' => [$zed], 'state' => $json]))
        ->assertOk()
        ->assertJsonPath('labels.'.$zed, 'Zed');

    // A query-string array loses blank items, so the field couldn't be found (the bug).
    $this->getJson($query(['path' => 'comments.1.author_name', 'table' => 1, 'state' => $state]))->assertNotFound();
});

it('runs afterStateUpdated of the picker in an added item and validates its key against the picker query', function (): void {
    [$token, $state] = pickerRepeaterSession($this);
    $zed = (string) User::query()->where('name', 'Zed')->value('id');
    $banned = (string) User::query()->where('name', 'Banned')->value('id');
    $state['comments'][] = ['author_name' => $zed, 'body' => null];

    $refresh = $this->postJson('/picker/_alfakit/schema/refresh', ['token' => $token, 'component' => 'form', 'state' => $state, 'path' => 'comments.1.author_name'])->assertOk();

    expect($refresh->json('state.comments.1.body'))->toBe('Picked '.$zed);

    $page = new PickerRepeaterPage;
    $form = $page->getForm();
    $form->applyClientState(['comments' => [['author_name' => $zed], ['author_name' => $banned]]]);
    $errors = rescue(fn () => $form->getValidator()->errors()->toArray(), report: false);

    expect($errors)->toHaveKey('comments.1.author_name')
        ->and($errors)->not->toHaveKey('comments.0.author_name');
});
