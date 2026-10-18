<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Notifications\Notification;
use AlfaSix\Alfakit\Pages\Page;
use AlfaSix\Alfakit\Tests\Fixtures\AppPages\AppPagesPanelProvider;
use AlfaSix\Alfakit\Tests\Fixtures\AppPages\BoardPage;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Project;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Team;
use AlfaSix\Alfakit\Tests\Fixtures\Models\TenantUser;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Support\SessionKey;

/*
 * Custom app pages: `#[Expose]` methods called through `/_alfakit/pages/call`, named schema
 * pieces (`{name}Form`, `{name}Table`, `{name}Infolist`) and the fullscreen layout.
 */

beforeEach(function (): void {
    AppPagesPanelProvider::registerPanel();
    BoardPage::$calls = [];
    RateLimiter::clear('x');
});

/**
 * @return array{0: TenantUser, 1: Team, 2: Team, 3: Project, 4: Project}
 */
function appPagesSetup(string $email = 'ada@example.com'): array
{
    $user = TenantUser::query()->create(['name' => 'Ada', 'email' => $email, 'password' => 'secret']);
    $alpha = Team::query()->firstOrCreate(['slug' => 'alpha'], ['name' => 'Alpha']);
    $beta = Team::query()->firstOrCreate(['slug' => 'beta'], ['name' => 'Beta']);
    $user->teams()->attach([$alpha->id, $beta->id]);

    $alphaProject = Project::query()->create(['team_id' => $alpha->id, 'name' => 'Alpha project']);
    $betaProject = Project::query()->create(['team_id' => $beta->id, 'name' => 'Beta project']);

    return [$user, $alpha, $beta, $alphaProject, $betaProject];
}

function boardToken(mixed $test, string $tenant = 'alpha'): string
{
    return inertiaProps(inertiaGet($test, "/apps/{$tenant}/board"))['page']['token'];
}

/**
 * @param  array<mixed>  $arguments
 * @param  array<string, mixed>  $extra
 */
function callBoard(mixed $test, string $token, string $method, array $arguments = [], array $extra = [], string $tenant = 'alpha'): Illuminate\Testing\TestResponse
{
    return $test->postJson("/apps/{$tenant}/_alfakit/pages/call", ['token' => $token, 'method' => $method, 'arguments' => $arguments, ...$extra]);
}

it('renders the custom component with view data, named pieces and the fullscreen layout', function (): void {
    [$user] = appPagesSetup();
    $response = inertiaGet($this->actingAs($user), '/apps/alpha/board');
    $props = inertiaProps($response);

    expect($response->json('component'))->toBe('Apps/Board')
        ->and($props['projects'])->toBe([1 => 'Alpha project'])
        ->and($props['page']['layout'])->toBe('fullscreen')
        ->and($props['page']['fullscreen'])->toBe(['header' => true, 'exitUrl' => alfakit()->getPanel('apps')->getUrl(Team::query()->where('slug', 'alpha')->first())])
        ->and($props['alfakit']['endpoints']['pageCall'])->toBe('http://localhost/apps/alpha/_alfakit/pages/call')
        ->and(array_column($props['page']['content'], 'key'))->toBe(['form:details', 'table:projects', 'infolist:summary']);

    $form = $props['page']['content'][0];
    $table = $props['page']['content'][1];
    $infolist = $props['page']['content'][2];

    expect($form)->toMatchArray(['type' => 'Form', 'component' => 'form:details', 'actions' => []])
        ->and(array_column($form['components'], 'statePath'))->toBe(['email', 'project_id'])
        ->and($table['component'])->toBe('table:projects')
        ->and($table['identifier'])->toBe('projects')
        ->and(array_column($table['records'], 'key'))->toBe([1])
        ->and($infolist)->toMatchArray(['type' => 'Infolist', 'key' => 'infolist:summary'])
        ->and($infolist['components'][0]['statePath'] ?? $infolist['components'][0]['name'] ?? null)->toBe('count')
        ->and(BoardPage::getSchemaBlockMethods())->toHaveKeys(['form:details', 'table:projects', 'infolist:summary'])
        ->and(BoardPage::getExposedMethodNames())->not->toContain('secret', 'hidden', 'staticMethod', 'detailsForm');
});

it('calls an exposed method and answers with the result, notifications and props to reload', function (): void {
    [$user, , , $project] = appPagesSetup();
    $this->actingAs($user);

    $response = callBoard($this, boardToken($this), 'rename', ['project' => (string) $project->id, 'name' => 'Renamed project'])
        ->assertOk();

    expect($response->json('result'))->toBe(['id' => $project->id, 'name' => 'Renamed project'])
        ->and($response->json('notifications.0.title'))->toBe('Renamed')
        ->and($response->json('errors'))->toBeNull()
        ->and($response->json('reload'))->toBe(['projects'])
        ->and($project->fresh()->name)->toBe('Renamed project')
        // The toast came back with the call: it is not shown again on the next page.
        ->and(session(SessionKey::FLASH_DATA)[Notification::FLASH_KEY] ?? null)->toBeNull();
});

it('names positional arguments after the parameters', function (): void {
    [$user, , , $project] = appPagesSetup();
    $this->actingAs($user);

    callBoard($this, boardToken($this), 'rename', [$project->id, 'By position'])->assertOk();

    expect($project->fresh()->name)->toBe('By position');
});

it('refuses methods without #[Expose], static or protected ones and unknown names', function (string $method): void {
    [$user] = appPagesSetup();
    $this->actingAs($user);

    callBoard($this, boardToken($this), $method)->assertNotFound();
})->with(['secret', 'hidden', 'staticMethod', 'detailsForm', 'mount', 'render', 'missing', '__construct', '../etc']);

it('requires a method name and an array of arguments', function (): void {
    [$user] = appPagesSetup();
    $this->actingAs($user);
    $token = boardToken($this);

    $this->postJson('/apps/alpha/_alfakit/pages/call', ['token' => $token])->assertStatus(400);
    $this->postJson('/apps/alpha/_alfakit/pages/call', ['token' => $token, 'method' => 'ping', 'arguments' => 'nope'])->assertStatus(422);
});

it('validates arguments with the attribute rules and passes only validated arguments', function (): void {
    [$user] = appPagesSetup();
    $this->actingAs($user);
    $token = boardToken($this);

    callBoard($this, $token, 'create', ['name' => 'ab', 'priority' => 'urgent'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['name', 'priority'])
        ->assertJsonPath('result', null);

    $response = callBoard($this, $token, 'create', ['name' => 'New one', 'priority' => 'high', 'unvalidated' => 'sneaky'])->assertOk();

    expect($response->json('result.priority'))->toBe('high')
        ->and($response->json('result.unvalidated'))->toBeNull()
        ->and(Project::query()->where('name', 'New one')->value('team_id'))->toBe(Team::query()->where('slug', 'alpha')->value('id'));
});

it('takes rules from a page method, which receives the arguments', function (): void {
    [$user] = appPagesSetup();
    $this->actingAs($user);
    $token = boardToken($this);

    callBoard($this, $token, 'renameAll', ['prefix' => 'abc', 'forbidden' => 'abc'])->assertStatus(422)->assertJsonValidationErrors('prefix');
    callBoard($this, $token, 'renameAll', ['prefix' => 'abc'])->assertOk()->assertJsonPath('result', 1);
});

it('casts scalar, enum and upload parameters and injects the rest', function (): void {
    [$user] = appPagesSetup();
    $this->actingAs($user);
    $token = boardToken($this);

    $key = $this->post('/apps/alpha/_alfakit/uploads', ['file' => UploadedFile::fake()->create('receipt.pdf', 4, 'application/pdf')], ['Accept' => 'application/json'])->json('key');

    callBoard($this, $token, 'inject', ['priority' => 'low', 'flag' => 'true', 'file' => $key, 'amount' => '12.5', 'request' => 'ignored'])
        ->assertOk()
        ->assertJsonPath('result', ['method' => 'POST', 'priority' => 'low', 'flag' => true, 'file' => 'receipt.pdf', 'amount' => 12.5]);

    callBoard($this, $token, 'inject', ['priority' => 'urgent', 'flag' => 'maybe', 'file' => 'tmp:01ARZ3NDEKTSV4RRFFQ69G5FAV.pdf', 'amount' => 'lots'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['priority', 'flag', 'file', 'amount']);

    callBoard($this, $token, 'inject', [])->assertStatus(422)->assertJsonValidationErrors('priority');
    callBoard($this, $token, 'rename', ['project' => 'one', 'name' => ['array']])->assertStatus(422)->assertJsonValidationErrors(['project', 'name']);
});

it('checks the attribute ability with the panel user', function (): void {
    Gate::define('manage-projects', fn (TenantUser $user): bool => $user->email === 'boss@example.com');

    [$user] = appPagesSetup();
    $this->actingAs($user);
    callBoard($this, boardToken($this), 'purge')->assertForbidden();

    $boss = TenantUser::query()->create(['name' => 'Boss', 'email' => 'boss@example.com', 'password' => 'secret']);
    $boss->teams()->attach(Team::query()->where('slug', 'alpha')->value('id'));
    $this->actingAs($boss);
    callBoard($this, boardToken($this), 'purge')->assertOk()->assertJsonPath('result', true);
});

it('rate limits per user and method with a notification', function (): void {
    [$user] = appPagesSetup();
    $this->actingAs($user);
    $token = boardToken($this);

    callBoard($this, $token, 'ping')->assertOk()->assertJsonPath('result', 'pong');
    callBoard($this, $token, 'ping')->assertOk();

    $limited = callBoard($this, $token, 'ping')->assertStatus(429);

    expect($limited->json('notifications.0.status'))->toBe('danger')
        ->and($limited->json('retryAfter'))->toBeGreaterThan(0);

    // Other methods have their own bucket.
    callBoard($this, $token, 'rename', [1, 'Still fine'])->assertOk();
});

it('scopes calls to the tenant of the token and binds the token to its user and page', function (): void {
    [$user, , , , $betaProject] = appPagesSetup();
    $this->actingAs($user);
    $alphaToken = boardToken($this);

    // The alpha page can't reach beta's records, whatever the endpoint URL says.
    callBoard($this, $alphaToken, 'rename', [$betaProject->id, 'Hijacked'])->assertNotFound();
    callBoard($this, $alphaToken, 'rename', [$betaProject->id, 'Hijacked'], tenant: 'beta')->assertNotFound();
    expect($betaProject->fresh()->name)->toBe('Beta project');

    // A tenant the user has left.
    $user->teams()->detach(Team::query()->where('slug', 'alpha')->value('id'));
    callBoard($this, $alphaToken, 'ping', tenant: 'beta')->assertNotFound();

    // Another user can't replay the token.
    $other = TenantUser::query()->create(['name' => 'Eve', 'email' => 'eve@example.com', 'password' => 'secret']);
    $other->teams()->attach(Team::query()->where('slug', 'alpha')->value('id'));
    $this->actingAs($other);
    callBoard($this, $alphaToken, 'ping')->assertForbidden();

    // Tampered tokens and guests.
    callBoard($this, 'forged', 'ping')->assertStatus(400);
    auth()->logout();
    callBoard($this, $alphaToken, 'ping')->assertUnauthorized();
});

it('answers a redirect for the browser to visit, keeping its notification flashed', function (): void {
    [$user] = appPagesSetup();
    $this->actingAs($user);

    $response = callBoard($this, boardToken($this), 'leave')->assertOk();

    expect($response->json('redirect'))->toBe('http://localhost/apps/alpha')
        ->and(session(SessionKey::FLASH_DATA)[Notification::FLASH_KEY][0]['title'] ?? null)->toBe('Bye');
});

it('reports halted calls without an error', function (): void {
    [$user] = appPagesSetup();
    $this->actingAs($user);

    callBoard($this, boardToken($this), 'stop')->assertOk()->assertJsonPath('halted', true)->assertJsonPath('notifications.0.title', 'Halted');
});

it('rolls the call back in a transaction', function (): void {
    [$user] = appPagesSetup();
    $this->actingAs($user);
    $token = boardToken($this);

    $this->withoutExceptionHandling();

    expect(fn () => callBoard($this, $token, 'failing'))->toThrow(RuntimeException::class, 'Boom')
        ->and(Project::query()->where('name', 'Rolled back')->exists())->toBeFalse();
});

it('validates a named form sent with the call and reports its errors under forms.{name}', function (): void {
    [$user] = appPagesSetup();
    $this->actingAs($user);
    $token = boardToken($this);

    callBoard($this, $token, 'details', extra: ['forms' => ['details' => ['email' => 'nope']]])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['forms.details.email']);

    callBoard($this, $token, 'details', extra: ['forms' => ['details' => ['email' => 'ada@example.com', 'project_id' => '1']]])
        ->assertOk()
        ->assertJsonPath('result', ['email' => 'ada@example.com', 'project_id' => '1']);
});

it('serves named forms and tables to the schema, table, action and poll endpoints', function (): void {
    [$user, , , $project] = appPagesSetup();
    $this->actingAs($user);
    $token = boardToken($this);

    // Live field of a named form.
    $refresh = $this->postJson('/apps/alpha/_alfakit/schema/refresh', ['token' => $token, 'component' => 'form:details', 'state' => ['project_id' => (string) $project->id], 'path' => 'project_id'])->assertOk();
    expect($refresh->json('state.email'))->toBe("project{$project->id}@example.com");

    // A record action of a named table.
    inertiaPost($this, '/apps/alpha/_alfakit/actions', ['token' => $token, 'component' => 'table:projects', 'action' => 'touch', 'record' => $project->id])->assertRedirect();
    expect(BoardPage::$calls)->toBe([['touch' => 'Alpha project']]);

    // Its own query string, and polling by its key.
    $searched = inertiaProps(inertiaGet($this, '/apps/alpha/board?projects_search=nothing'));
    expect(collect($searched['page']['content'])->firstWhere('key', 'table:projects')['records'])->toBe([]);

    $component = inertiaGet($this, '/apps/alpha/board')->json('component');
    $polled = $this->get('/apps/alpha/board', [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => '',
        'X-Inertia-Partial-Component' => $component,
        'X-Inertia-Partial-Data' => Page::POLL_PROP,
        Page::POLL_HEADER => 'table:projects',
    ])->json('props.'.Page::POLL_PROP);
    expect(array_keys($polled))->toBe(['table:projects']);
});
