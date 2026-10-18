<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Actions\LocaleSwitcher;
use AlfaSix\Alfakit\Forms\Components\FileUpload;
use AlfaSix\Alfakit\Forms\Components\MarkdownEditor;
use AlfaSix\Alfakit\Forms\Components\Repeater;
use AlfaSix\Alfakit\Localization\TranslatableLocale;
use AlfaSix\Alfakit\Tests\Fixtures\FormsActions\DocumentResource;
use AlfaSix\Alfakit\Tests\Fixtures\FormsActions\FaDashboard;
use AlfaSix\Alfakit\Tests\Fixtures\FormsActions\FaPage;
use AlfaSix\Alfakit\Tests\Fixtures\FormsActions\FaPanelProvider;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Post;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Team;
use AlfaSix\Alfakit\Tests\Fixtures\Models\TenantUser;
use AlfaSix\Alfakit\Tests\Fixtures\Models\User;
use AlfaSix\Alfakit\Tests\Fixtures\Standalone\TeamForm;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/*
 * Open items after the feature build (AUDIT.md): single-file uploads in action modals,
 * live bulk-action forms, searchable picker filters, per-step wizard validation,
 * translatable Markdown and the LocaleSwitcher, live dashboard filters and the tenant
 * check of standalone components.
 */

beforeEach(function (): void {
    FaPanelProvider::registerPanel();
    FaPage::$runs = [];
    FaPage::$stepHooks = [];
    DocumentResource::$runs = [];
    FaDashboard::$onlyLive = false;
    TeamForm::$submitted = [];
});

function faUser(): User
{
    return User::query()->create(['name' => 'Fa', 'email' => 'fa'.uniqid().'@example.com', 'password' => 'password']);
}

function faToken(mixed $test, string $url): string
{
    return inertiaProps(inertiaGet($test, $url))['page']['token'];
}

function faPost(array $attributes = []): Post
{
    static $i = 0;
    $i++;

    return Post::query()->create(['title' => "Doc {$i}", 'slug' => "doc-{$i}-".uniqid(), ...$attributes]);
}

function faUpload(mixed $test): string
{
    return $test->post('/fa/_alfakit/uploads', ['file' => UploadedFile::fake()->image('photo.png', 10, 10)], ['Accept' => 'application/json'])
        ->assertOk()
        ->json('key');
}

// ---------------------------------------------------------------- 1. Single-file uploads

it('accepts a single upload sent as one path, in action modals and page forms alike', function (): void {
    $token = faToken($this->actingAs(faUser()), '/fa/tools');
    $key = faUpload($this);

    // A renderer sends a single FileUpload's state as the path itself.
    inertiaPost($this, '/fa/_alfakit/actions', ['token' => $token, 'action' => 'attach', 'data' => ['file' => $key]])->assertRedirect();

    expect(FaPage::$runs)->toBe([['attach' => ['file' => $key]]]);

    // A list (what the server itself sends back) still works, and more than one is refused.
    inertiaPost($this, '/fa/_alfakit/actions', ['token' => $token, 'action' => 'attach', 'data' => ['file' => [$key]]])->assertRedirect();
    $this->postJson('/fa/_alfakit/actions', ['token' => $token, 'action' => 'attach', 'data' => ['file' => [$key, $key]]])->assertStatus(422);
    $this->postJson('/fa/_alfakit/actions', ['token' => $token, 'action' => 'attach', 'data' => ['file' => '']])->assertJsonValidationErrors('file');

    // The page form's live refresh normalises the path to a list too.
    $refresh = $this->postJson('/fa/_alfakit/schema/refresh', ['token' => $token, 'component' => 'form', 'state' => ['attachment' => $key], 'path' => 'attachment'])
        ->assertOk()
        ->json();

    expect($refresh['state']['attachment'])->toBe([$key]);
});

it('normalises single uploads inside repeater items and json strings', function (): void {
    $form = schema([
        FileUpload::make('cover')->storeFiles(false),
        Repeater::make('gallery')->schema([FileUpload::make('image')->storeFiles(false)]),
    ])->fill();

    $form->applyClientState(['cover' => '["a.png"]', 'gallery' => [['image' => 'b.png'], ['image' => null]]]);

    expect($form->getRawState())->toBe(['cover' => ['a.png'], 'gallery' => [['image' => ['b.png']], ['image' => []]]]);
});

// ---------------------------------------------------------------- 2. Live bulk-action forms

it('refreshes a bulk action form with the selection, including nested modal actions', function (): void {
    $posts = [faPost(), faPost(), faPost()];
    $token = faToken($this->actingAs(faUser()), '/fa/documents');
    $records = [$posts[0]->id, $posts[1]->id];

    $payload = $this->postJson('/fa/_alfakit/schema/refresh', [
        'token' => $token,
        'component' => 'action:table.assign',
        'bulk' => 1,
        'records' => $records,
        'state' => ['kind' => 'b', 'note' => null, 'author' => null],
        'path' => 'kind',
    ])->assertOk()->json();

    $components = collect($payload['components'])->keyBy('name');

    expect($components)->toHaveKey('note')
        ->and($payload['state']['note'])->toBe('selected 2');

    // Searchable selects inside the bulk form see the selection too.
    $this->getJson('/fa/_alfakit/schema/options?'.http_build_query(['token' => $token, 'component' => 'action:table.assign', 'bulk' => 1, 'records' => $records, 'path' => 'author', 'search' => 'ann']))
        ->assertOk()
        ->assertJsonPath('options.0.label', 'ann for 2');

    // The action nested in the bulk modal shares the selection.
    $nested = $this->postJson('/fa/_alfakit/schema/refresh', [
        'token' => $token,
        'component' => 'action:table.assign>nested',
        'bulk' => 1,
        'records' => $records,
        'state' => ['kind' => 'b', 'echo' => null],
        'path' => 'kind',
    ])->assertOk()->json();

    expect($nested['state']['echo'])->toBe('nested 2');

    // "All records" selections carry the page's query string.
    $all = $this->postJson('/fa/_alfakit/schema/refresh', [
        'token' => $token,
        'component' => 'action:table.assign',
        'bulk' => 1,
        'records' => [],
        'allRecords' => 1,
        'tableQuery' => 'search=Doc+1',
        'state' => ['kind' => 'b', 'note' => null, 'author' => null],
        'path' => 'kind',
    ])->assertOk()->json();

    expect($all['state']['note'])->toBe('selected 1');

    // Without the bulk flag the bulk action is not a record action.
    $this->postJson('/fa/_alfakit/schema/refresh', ['token' => $token, 'component' => 'action:table.assign', 'state' => [], 'path' => 'kind'])
        ->assertNotFound();
});

// ---------------------------------------------------------------- 3. Picker and table filters

it('serves the options of searchable select filters inside a picker and a table', function (): void {
    $user = faUser();
    $ann = User::query()->create(['name' => 'Ann', 'email' => 'ann@example.com', 'password' => 'password']);
    $bob = User::query()->create(['name' => 'Bob', 'email' => 'bob@example.com', 'password' => 'password']);
    faPost(['author_id' => $ann->id]);
    faPost(['author_id' => $bob->id]);

    $token = faToken($this->actingAs($user), '/fa/tools');
    $query = fn (array $extra): string => '/fa/_alfakit/schema/options?'.http_build_query(['token' => $token, 'component' => 'form', 'pickerFilters' => 'post_ids', ...$extra]);

    $this->getJson($query(['path' => 'author.value', 'search' => 'bo']))
        ->assertOk()
        ->assertJsonCount(1, 'options')
        ->assertJsonPath('options.0.label', 'Bob');

    $this->getJson($query(['path' => 'author.value', 'values' => [$ann->id]]))
        ->assertOk()
        ->assertJsonPath('labels.'.$ann->id, 'Ann');

    // Unknown fields, non-picker fields and a 404 for pickers the page doesn't show.
    $this->getJson($query(['path' => 'nope.value', 'search' => 'x']))->assertNotFound();
    $this->getJson('/fa/_alfakit/schema/options?'.http_build_query(['token' => $token, 'component' => 'form', 'pickerFilters' => 'title', 'path' => 'author.value', 'search' => 'x']))->assertNotFound();

    // The same for a table's own filters form (`table.filters`).
    $listToken = faToken($this, '/fa/documents');

    $this->getJson('/fa/_alfakit/schema/options?'.http_build_query(['token' => $listToken, 'component' => 'table.filters', 'path' => 'author.value', 'search' => 'an']))
        ->assertOk()
        ->assertJsonPath('options.0.label', 'Ann');
});

// ---------------------------------------------------------------- 4. Wizard steps

it('validates one wizard step on "Next", in action modals and page forms', function (): void {
    $token = faToken($this->actingAs(faUser()), '/fa/tools');

    // Only the current step's fields are checked: the empty second step doesn't fail the first.
    $this->postJson('/fa/_alfakit/schema/validate', ['token' => $token, 'component' => 'action:onboard', 'state' => ['name' => 'Ann', 'bio' => ''], 'step' => 'account'])
        ->assertNoContent();

    expect(FaPage::$stepHooks)->toBe(['account:before', 'account:after']);

    $this->postJson('/fa/_alfakit/schema/validate', ['token' => $token, 'component' => 'action:onboard', 'state' => ['name' => '', 'bio' => ''], 'step' => 'account'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['name'])
        ->assertJsonMissingValidationErrors(['bio']);

    $this->postJson('/fa/_alfakit/schema/validate', ['token' => $token, 'component' => 'action:onboard', 'state' => [], 'step' => 'missing'])
        ->assertNotFound();

    // Page wizards use the same endpoint.
    $this->postJson('/fa/_alfakit/schema/validate', ['token' => $token, 'component' => 'form', 'state' => ['name' => 'x', 'bio' => 'too long'], 'step' => 'details'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['bio']);

    $this->postJson('/fa/_alfakit/schema/validate', ['token' => $token, 'component' => 'form', 'state' => ['name' => 'x', 'bio' => 'ok'], 'step' => 'details'])
        ->assertNoContent();

    // Submitting still validates every step.
    $this->postJson('/fa/_alfakit/actions', ['token' => $token, 'action' => 'onboard', 'data' => ['name' => 'Ann']])->assertStatus(422);
    expect(FaPage::$runs)->toBe([]);
});

// ---------------------------------------------------------------- 5. Translatable Markdown and the LocaleSwitcher

it('renders a translatable MarkdownEditor per language and stores every language', function (): void {
    alfakit()->setCurrentPanel(alfakit()->getPanel('fa'));

    $editor = MarkdownEditor::make('content')->translatable();
    $form = schema([$editor], model: Post::class)->fill(['content' => ['en' => '# Hello', 'ar' => '']]);

    $schema = $form->toSchema()[0];

    expect($schema['translatable']['defaultLocale'])->toBe('en')
        ->and($schema['html'])->toBe(['en' => "<h1>Hello</h1>\n", 'ar' => null]);

    $form->applyClientState(['content' => ['en' => '**Bold**', 'ar' => 'مرحبا']]);

    // Validated and dehydrated: a plain text column stores the languages as JSON.
    expect(json_decode((string) $form->validate()['content'], true))->toBe(['en' => '**Bold**', 'ar' => 'مرحبا']);
});

it('switches the editing language of every translatable field with a LocaleSwitcher', function (): void {
    $props = inertiaProps(inertiaGet($this->actingAs(faUser()), '/fa/tools'));
    $token = $props['page']['token'];
    $switcher = collect($props['page']['headerActions'])->firstWhere('type', 'ActionGroup');
    $form = collect($props['page']['content'])->firstWhere('type', 'Form');
    $fields = collect($form['components'])->keyBy('name');

    expect($switcher['label'])->toBe('English')
        ->and(array_column($switcher['actions'], 'name'))->toBe([LocaleSwitcher::ACTION_PREFIX.'en', LocaleSwitcher::ACTION_PREFIX.'ar'])
        ->and($switcher['actions'][0]['icon'])->toBe('check')
        ->and($fields['title']['translatable']['defaultLocale'])->toBe('en')
        ->and($fields['content']['translatable']['defaultLocale'])->toBe('en');

    inertiaPost($this, '/fa/_alfakit/actions', ['token' => $token, 'action' => LocaleSwitcher::ACTION_PREFIX.'ar'])->assertRedirect();

    expect(TranslatableLocale::get(alfakit()->getPanel('fa')))->toBe('ar');

    $props = inertiaProps(inertiaGet($this, '/fa/tools'));
    $switcher = collect($props['page']['headerActions'])->firstWhere('type', 'ActionGroup');
    $fields = collect(collect($props['page']['content'])->firstWhere('type', 'Form')['components'])->keyBy('name');

    expect($switcher['label'])->toBe('العربية')
        ->and($switcher['actions'][1]['icon'])->toBe('check')
        ->and($fields['title']['translatable']['defaultLocale'])->toBe('ar')
        ->and($fields['content']['translatable']['defaultLocale'])->toBe('ar');
});

// ---------------------------------------------------------------- 7. Live dashboard filters

it('applies live dashboard filters during the refresh and keeps Apply for the other fields', function (): void {
    $this->actingAs(faUser());
    faPost(['status' => 'draft']);
    faPost(['status' => 'published']);

    $props = inertiaProps(inertiaGet($this, '/fa'));
    $form = collect($props['page']['content'])->firstWhere('type', 'Form');
    $stats = fn (array $props): array => array_column(collect($props['page']['content'])->firstWhere('type', 'Widgets')['widgets'][0]['stats'], 'value', 'label');

    expect($form['liveFilters'])->toBeTrue()
        ->and(array_column($form['actions'], 'name'))->toBe(['applyFilters'])
        ->and($stats($props))->toBe(['Posts' => 2, 'Filter' => 'all']);

    $this->postJson('/fa/_alfakit/schema/refresh', ['token' => $props['page']['token'], 'component' => 'form', 'state' => ['status' => 'published', 'search' => null], 'path' => 'status'])
        ->assertOk();

    $props = inertiaProps(inertiaGet($this, '/fa'));

    expect($stats($props))->toBe(['Posts' => 1, 'Filter' => 'published'])
        ->and(array_column(collect($props['page']['content'])->firstWhere('type', 'Form')['actions'], 'name'))->toBe(['applyFilters', 'resetFilters']);

    // Invalid values are not applied.
    $this->postJson('/fa/_alfakit/schema/refresh', ['token' => $props['page']['token'], 'component' => 'form', 'state' => ['status' => 'draft', 'search' => 'too long'], 'path' => 'status'])
        ->assertOk();

    expect($stats(inertiaProps(inertiaGet($this, '/fa'))))->toBe(['Posts' => 1, 'Filter' => 'published']);
});

it('hides the Apply button when every dashboard filter is live', function (): void {
    FaDashboard::$onlyLive = true;
    $this->actingAs(faUser());

    $form = collect(inertiaProps(inertiaGet($this, '/fa'))['page']['content'])->firstWhere('type', 'Form');

    expect(array_column($form['actions'], 'name'))->toBe([]);
});

// ---------------------------------------------------------------- 9. Standalone tenants

it('re-checks tenant membership on every internal request of a standalone component', function (): void {
    Route::middleware('web')->get('/teams/{team}/form', fn (Team $team) => Inertia::render('TeamForm', ['form' => TeamForm::make(['tenant' => $team])]));

    $team = Team::query()->create(['name' => 'Alpha', 'slug' => 'alpha']);
    $member = TenantUser::query()->create(['name' => 'Member', 'email' => 'member@example.com', 'password' => 'password']);
    $member->teams()->attach($team);
    $outsider = TenantUser::query()->create(['name' => 'Outsider', 'email' => 'outsider@example.com', 'password' => 'password']);

    $token = inertiaProps(inertiaGet($this->actingAs($member), "/teams/{$team->id}/form"))['form']['token'];
    $refresh = ['token' => $token, 'component' => 'form', 'state' => ['name' => 'x'], 'path' => 'name'];

    $this->postJson('/_alfakit/schema/refresh', $refresh)->assertOk();

    inertiaPost($this, '/_alfakit/actions', ['token' => $token, 'action' => 'submit', 'data' => ['name' => 'Alpha plan']])->assertRedirect();
    expect(TeamForm::$submitted)->toBe([['name' => 'Alpha plan', 'tenant' => $team->id]]);

    // Membership revoked after the page rendered: the next request is refused.
    $member->teams()->detach($team);
    $this->postJson('/_alfakit/schema/refresh', $refresh)->assertNotFound();

    // A token issued to another user never works, and an outsider's own token is refused too.
    $outsiderToken = inertiaProps(inertiaGet($this->actingAs($outsider), "/teams/{$team->id}/form"))['form']['token'];
    $this->postJson('/_alfakit/schema/refresh', [...$refresh, 'token' => $outsiderToken])->assertNotFound();

    // Guests fail closed.
    auth()->logout();
    $guestToken = inertiaProps(inertiaGet($this, "/teams/{$team->id}/form"))['form']['token'];
    $this->postJson('/_alfakit/schema/refresh', [...$refresh, 'token' => $guestToken])->assertNotFound();
});

it('keeps validating translations through ValidationException', function (): void {
    alfakit()->setCurrentPanel(alfakit()->getPanel('fa'));

    $form = schema([MarkdownEditor::make('content')->translatable()->required()], model: Post::class)->fill();
    $form->applyClientState(['content' => ['en' => '', 'ar' => 'x']]);

    expect(fn () => $form->validate())->toThrow(ValidationException::class);
});
