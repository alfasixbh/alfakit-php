<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Notifications\Notification;
use AlfaSix\Alfakit\Resources\Api\ApiRecord;
use AlfaSix\Alfakit\Tests\Fixtures\ApiResources\ApiPanelProvider;
use AlfaSix\Alfakit\Tests\Fixtures\ApiResources\CarrierPolicy;
use AlfaSix\Alfakit\Tests\Fixtures\ApiResources\ContactSource;
use AlfaSix\Alfakit\Tests\Fixtures\ApiResources\SupplierResource;
use AlfaSix\Alfakit\Tests\Fixtures\ApiResources\SupplierSource;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Team;
use AlfaSix\Alfakit\Tests\Fixtures\Models\TenantUser;
use AlfaSix\Alfakit\Tests\Fixtures\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    ApiPanelProvider::registerPanels();
    SupplierSource::reset();
    ContactSource::reset();
    SupplierResource::$canCreate = true;
    CarrierPolicy::$allowViewAny = true;
    $this->actingAs(User::query()->create(['name' => 'Api', 'email' => 'api@example.com', 'password' => 'password']));
});

/**
 * @return array<string, mixed>
 */
function apiPage(AlfaSix\Alfakit\Tests\TestCase $test, string $url): array
{
    return inertiaProps(inertiaGet($test, $url))['page'];
}

/**
 * @param  array<string, mixed>  $data
 */
function apiAction(AlfaSix\Alfakit\Tests\TestCase $test, string $token, string $action, array $data = [], array $extra = [], string $panel = 'api'): Illuminate\Testing\TestResponse
{
    return inertiaPost($test, "/{$panel}/_alfakit/actions", ['token' => $token, 'action' => $action, 'data' => $data, ...$extra]);
}

/**
 * @return array<int, array<string, mixed>>
 */
function flashedNotifications(): array
{
    return session()->get(Inertia\Support\SessionKey::FLASH_DATA, [])[Notification::FLASH_KEY] ?? [];
}

// ---------------------------------------------------------------- In-memory data source

it('derives labels, slug and routes from the resource name', function (): void {
    expect(SupplierResource::getSlug())->toBe('suppliers')
        ->and(SupplierResource::getModelLabel())->toBe('supplier')
        ->and(SupplierResource::getPluralModelLabel())->toBe('suppliers')
        ->and(SupplierResource::getModel())->toBe(ApiRecord::class)
        ->and(route('alfakit.api.resources.suppliers.edit', ['record' => 'acme']))->toBe('http://localhost/api/suppliers/acme/edit');
});

it('lists records with search, sort, filters and paging done on the server', function (): void {
    $table = apiPage($this, '/api/suppliers')['content'][0];

    expect(array_column($table['records'], 'key'))->toBe(['acme', 'bloom', 'cedar'])
        ->and($table['records'][0]['cells']['name']['state'])->toBe('Acme Oils')
        ->and($table['records'][0]['url'])->toBe('http://localhost/api/suppliers/acme/edit');

    expect(array_column(apiPage($this, '/api/suppliers?search=flor')['content'][0]['records'], 'key'))->toBe(['bloom'])
        ->and(array_column(apiPage($this, '/api/suppliers?sort=rating&direction=desc')['content'][0]['records'], 'key'))->toBe(['bloom', 'acme', 'cedar'])
        ->and(array_column(apiPage($this, '/api/suppliers?filters[country][value]=Lebanon')['content'][0]['records'], 'key'))->toBe(['cedar']);

    $paged = apiPage($this, '/api/suppliers?perPage=2&page=2')['content'][0];

    expect(array_column($paged['records'], 'key'))->toBe(['cedar']);
});

it('creates a record through the data source and maps its validation errors to fields', function (): void {
    $page = apiPage($this, '/api/suppliers/create');

    apiAction($this, $page['token'], 'create', ['name' => 'Dune Resins', 'country' => 'Oman', 'rating' => 4])
        ->assertRedirect('http://localhost/api/suppliers/dune');

    expect(SupplierSource::$rows['dune'])->toMatchArray(['name' => 'Dune Resins', 'country' => 'Oman']);

    // Form validation runs as usual…
    apiAction($this, $page['token'], 'create', ['name' => '', 'country' => 'Oman'])
        ->assertStatus(422)->assertJsonValidationErrors(['name']);

    // …and a ValidationException thrown by the source lands on the field.
    apiAction($this, $page['token'], 'create', ['name' => 'Acme Oils', 'country' => 'Bahrain'])
        ->assertStatus(422)->assertJsonValidationErrors(['name' => 'A supplier with this name exists.']);
});

it('fills the edit form from the record and saves through update()', function (): void {
    $page = apiPage($this, '/api/suppliers/acme/edit');
    $form = $page['content'][0];

    expect($page['title'])->toBe('Edit Acme Oils')
        ->and($form['state'])->toMatchArray(['name' => 'Acme Oils', 'country' => 'Bahrain', 'rating' => 4])
        ->and(array_column($page['breadcrumbs'], 'label'))->toBe(['Suppliers', 'Acme Oils', 'Edit'])
        ->and($page['breadcrumbs'][1]['url'])->toBe('http://localhost/api/suppliers/acme')
        ->and(array_column($page['subNavigation']['groups'][0]['items'], 'label'))->toBe(['View', 'Edit']);

    apiAction($this, $page['token'], 'save', ['name' => 'Acme Oils & Musk', 'country' => 'Bahrain', 'rating' => 5])
        ->assertRedirect('http://localhost/api/suppliers/acme/edit');

    expect(SupplierSource::$rows['acme'])->toMatchArray(['name' => 'Acme Oils & Musk', 'rating' => 5]);

    $this->get('/api/suppliers/nope/edit')->assertNotFound();
});

it('shows the record in the view page infolist', function (): void {
    $page = apiPage($this, '/api/suppliers/bloom');
    $infolist = json_encode($page['content'][0]);

    expect($page['title'])->toBe('Bloom Florals')
        ->and($infolist)->toContain('Bloom Florals')->toContain('France');
});

it('deletes records from the table, the edit page and in bulk, skipping refused ones', function (): void {
    $token = apiPage($this, '/api/suppliers')['token'];

    apiAction($this, $token, 'delete', [], ['component' => 'table', 'record' => 'acme'])->assertRedirect();
    expect(SupplierSource::$rows)->not->toHaveKey('acme');

    // Locked suppliers can't be deleted (`canDelete()`).
    apiAction($this, $token, 'delete', [], ['component' => 'table', 'record' => 'cedar'])->assertForbidden();

    SupplierSource::reset();
    apiAction($this, $token, 'delete', [], ['component' => 'table', 'records' => ['acme', 'bloom', 'cedar'], 'bulk' => true])->assertRedirect();
    expect(array_keys(SupplierSource::$rows))->toBe(['cedar']);

    SupplierSource::reset();
    $edit = apiPage($this, '/api/suppliers/bloom/edit');
    apiAction($this, $edit['token'], 'delete')->assertRedirect('http://localhost/api/suppliers');
    expect(SupplierSource::$rows)->not->toHaveKey('bloom');
});

it('authorises pages and actions through the can*() methods', function (): void {
    SupplierResource::$canCreate = false;

    $this->get('/api/suppliers/create')->assertForbidden();
    expect(json_encode(apiPage($this, '/api/suppliers')['headerActions'] ?? []))->not->toContain('"name":"create"');
});

it('searches records globally through search()', function (): void {
    $groups = $this->getJson('/api/_alfakit/global-search?search=bloom')->assertOk()->json('groups');
    $suppliers = collect($groups)->firstWhere('label', 'Suppliers');

    expect($suppliers['results'])->toHaveCount(1)
        ->and($suppliers['results'][0])->toMatchArray(['title' => 'Bloom Florals', 'url' => 'http://localhost/api/suppliers/bloom/edit']);
});

it('shows an error notification instead of failing when the source is down', function (): void {
    SupplierSource::$fail = true;

    $response = inertiaGet($this, '/api/suppliers')->assertOk();

    expect($response->json('props.page.content.0.records'))->toBe([])
        ->and(collect($response->json('flash.'.Notification::FLASH_KEY))->pluck('body')->all())->toContain('The supplier service is down.');

    // A record page redirects to the list.
    $this->get('/api/suppliers/acme/edit')->assertRedirect('http://localhost/api/suppliers');

    // Global search leaves the group out.
    expect($this->getJson('/api/_alfakit/global-search?search=acme')->json('groups'))->toBe([]);
});

it('passes the current tenant to every data source call', function (): void {
    $user = TenantUser::query()->create(['name' => 'Ada', 'email' => 'ada@example.com', 'password' => 'secret']);
    $alpha = Team::query()->create(['name' => 'Alpha', 'slug' => 'alpha']);
    $user->teams()->attach($alpha->id);
    $this->actingAs($user);

    $page = apiPage($this, '/apiteams/alpha/suppliers/acme/edit');
    apiAction($this, $page['token'], 'save', ['name' => 'Acme', 'country' => 'Bahrain', 'rating' => 4], [], 'apiteams/alpha')->assertRedirect();

    expect(SupplierSource::$calls)->not->toBeEmpty();

    foreach (SupplierSource::$calls as [$call, $tenant]) {
        expect($tenant?->is($alpha))->toBeTrue("{$call} did not receive the tenant");
    }
});

// ---------------------------------------------------------------- HTTP data source

function fakeCarriersApi(): void
{
    Http::fake([
        'carriers.test/v1/carriers/dhl' => fn (Request $request) => match ($request->method()) {
            'GET' => Http::response(['id' => 'dhl', 'carrier_name' => 'DHL Express', 'code' => 'DHL']),
            'PATCH' => Http::response(['id' => 'dhl', ...$request->data()]),
            'DELETE' => Http::response(null, 204),
            default => Http::response(null, 405),
        },
        'carriers.test/v1/carriers/lock' => Http::response(['id' => 'lock', 'carrier_name' => 'Locked', 'code' => 'LOCK']),
        'carriers.test/v1/carriers/broken' => Http::response(['message' => 'boom'], 500),
        'carriers.test/v1/carriers/*' => Http::response(['message' => 'Not found'], 404),
        'carriers.test/v1/carriers*' => fn (Request $request) => $request->method() === 'POST'
            ? ($request['carrier_name'] === 'Taken'
                ? Http::response(['message' => 'Invalid', 'errors' => ['carrier_name' => ['The name is taken.'], 'code' => ['Bad code.']]], 422)
                : Http::response(['id' => 'new', ...$request->data()], 201))
            : Http::response([
                'data' => [['id' => 'dhl', 'carrier_name' => 'DHL Express', 'code' => 'DHL'], ['id' => 'ups', 'carrier_name' => 'UPS', 'code' => 'UPS']],
                'meta' => ['total' => 12],
            ]),
    ]);
}

it('lists records from a REST API, passing search, sort and page as query parameters', function (): void {
    fakeCarriersApi();

    $table = apiPage($this, '/api/carriers?search=ex&sort=name&direction=desc&page=2')['content'][0];

    expect(array_column($table['records'], 'key'))->toBe(['dhl', 'ups'])
        ->and($table['records'][0]['cells']['name']['state'])->toBe('DHL Express')
        ->and($table['pagination']['total'] ?? null)->toBe(12);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && str_starts_with($request->url(), 'https://carriers.test/v1/carriers?')
        && $request['search'] === 'ex' && $request['sort'] === 'name' && $request['direction'] === 'desc' && (int) $request['page'] === 2
        && $request->hasHeader('Authorization', 'Bearer secret-token'));
});

it('creates through POST and maps a 422 response onto the form fields', function (): void {
    fakeCarriersApi();
    $page = apiPage($this, '/api/carriers/create');

    apiAction($this, $page['token'], 'create', ['name' => 'Aramex', 'code' => 'ARX'])->assertRedirect('http://localhost/api/carriers/new/edit');
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && $request['carrier_name'] === 'Aramex');

    apiAction($this, $page['token'], 'create', ['name' => 'Taken', 'code' => 'TK'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['name' => 'The name is taken.', 'code' => 'Bad code.']);
});

it('edits and deletes through PATCH and DELETE, and 404s unknown keys', function (): void {
    fakeCarriersApi();
    $page = apiPage($this, '/api/carriers/dhl/edit');

    expect($page['content'][0]['state'])->toMatchArray(['name' => 'DHL Express', 'code' => 'DHL']);

    apiAction($this, $page['token'], 'save', ['name' => 'DHL', 'code' => 'DHL'])->assertRedirect();
    Http::assertSent(fn (Request $request): bool => $request->method() === 'PATCH' && $request['carrier_name'] === 'DHL');

    $token = apiPage($this, '/api/carriers')['token'];
    apiAction($this, $token, 'delete', [], ['component' => 'table', 'record' => 'dhl'])->assertRedirect();
    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE' && $request->url() === 'https://carriers.test/v1/carriers/dhl');

    $this->get('/api/carriers/missing/edit')->assertNotFound();
});

it('turns other API errors into an error notification', function (): void {
    fakeCarriersApi();

    $this->get('/api/carriers/broken/edit')->assertRedirect('http://localhost/api/carriers');

    expect(collect(flashedNotifications())->pluck('body')->all())->toContain('The data source answered with an error (500). Please try again.');
});

it('authorises through the resource policy class', function (): void {
    fakeCarriersApi();

    $this->get('/api/carriers/lock/edit')->assertForbidden();

    CarrierPolicy::$allowViewAny = false;
    $this->get('/api/carriers')->assertForbidden();
});

it('sends the tenant with every API request', function (): void {
    fakeCarriersApi();
    $user = TenantUser::query()->create(['name' => 'Ada', 'email' => 'ada@example.com', 'password' => 'secret']);
    $alpha = Team::query()->create(['name' => 'Alpha', 'slug' => 'alpha']);
    $user->teams()->attach($alpha->id);
    $this->actingAs($user);

    apiPage($this, '/apiteams/alpha/carriers');
    apiPage($this, '/apiteams/alpha/carriers/dhl/edit');

    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request): bool => $request->hasHeader('X-Team', 'alpha'));
    Http::assertNotSent(fn (Request $request): bool => ! $request->hasHeader('X-Team', 'alpha'));
});

// ---------------------------------------------------------------- Failures, caching, relation sections

it('keeps the form and shows an error notification when saving fails', function (): void {
    $page = apiPage($this, '/api/suppliers/create');
    SupplierSource::$fail = true;

    apiAction($this, $page['token'], 'create', ['name' => 'Dune Resins', 'country' => 'Oman'])->assertRedirect();

    expect(session()->get(Inertia\Support\SessionKey::FLASH_DATA, [])['alfakitAction']['status'] ?? null)->toBe('halted')
        ->and(collect(flashedNotifications())->pluck('body')->all())->toContain('The supplier service is down.')
        ->and(SupplierSource::$rows)->not->toHaveKey('dune');
});

it('caches reads when asked and flushes the cache on writes', function (): void {
    $source = (new SupplierSource)->cacheFor(60);
    $query = new AlfaSix\Alfakit\Resources\Api\RecordsQuery;

    $source->getRecords($query);
    $source->getRecords($query);
    $source->getRecord('acme');
    $source->getRecord('acme');

    expect(array_column(SupplierSource::$calls, 0))->toBe(['records', 'find']);

    $source->updateRecord('acme', ['name' => 'Acme 2']);

    expect($source->getRecord('acme')?->getAttribute('name'))->toBe('Acme 2')
        ->and(array_column(SupplierSource::$calls, 0))->toBe(['records', 'find', 'update', 'find']);
});

it('shows relation-like sections from a data source scoped to the owner record', function (): void {
    $page = apiPage($this, '/api/suppliers/acme/edit');
    $manager = collect($page['content'])->firstWhere('type', 'RelationManagers')['managers'][0] ?? null;

    expect($manager)->not->toBeNull()
        ->and($manager['title'])->toBe('Contacts')
        ->and(array_column($manager['table']['records'], 'key'))->toBe(['c1', 'c2']);

    $component = $manager['component'];

    apiAction($this, $page['token'], 'create', ['name' => 'Layla'], ['component' => $component])->assertRedirect();
    expect(collect(ContactSource::$rows)->firstWhere('name', 'Layla'))->toMatchArray(['supplier' => 'acme']);

    apiAction($this, $page['token'], 'edit', ['name' => 'Huda A.'], ['component' => $component, 'record' => 'c1'])->assertRedirect();
    expect(ContactSource::$rows['c1']['name'])->toBe('Huda A.');

    // Another supplier's contact can't be reached through this owner.
    apiAction($this, $page['token'], 'delete', [], ['component' => $component, 'record' => 'c3'])->assertNotFound();
    apiAction($this, $page['token'], 'delete', [], ['component' => $component, 'record' => 'c2'])->assertRedirect();
    expect(ContactSource::$rows)->not->toHaveKey('c2');
});
