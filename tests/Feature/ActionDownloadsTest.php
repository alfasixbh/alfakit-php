<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Actions\Action;
use AlfaSix\Alfakit\Forms\Components\TextInput;
use AlfaSix\Alfakit\Support\ActionDownload;
use AlfaSix\Alfakit\Tables\Table;
use AlfaSix\Alfakit\Tests\Fixtures\FormsB\FormsBPage;
use AlfaSix\Alfakit\Tests\Fixtures\Models\User;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

/*
 * W-V5-02 / W-F-08: files returned by action handlers reach the browser. The action endpoint
 * is posted as an Inertia visit, which cannot carry a file, so the file is served from a
 * signed, one-time link that the frontend opens.
 */

/** Bytes that a text round trip would corrupt (NUL, high bytes, a zip header). */
const DOWNLOAD_BYTES = "PK\x03\x04\x00\xff\xfe\x80binary\r\n\x00end";

/**
 * @param  Closure(): mixed  $handler
 */
function downloadTable(Closure $handler): void
{
    Table::configureUsing(static function (Table $table) use ($handler): void {
        $table->headerActions([Action::make('export')->action($handler)]);
    });
}

function downloadToken(mixed $test): string
{
    $test->actingAs(User::query()->create(['name' => 'Dl', 'email' => 'dl'.uniqid().'@example.com', 'password' => 'password']));

    return inertiaProps(inertiaGet($test, '/admin/posts'))['page']['token'];
}

function runDownloadAction(mixed $test, string $token): Illuminate\Testing\TestResponse
{
    return inertiaPost($test, '/admin/_alfakit/actions', ['token' => $token, 'component' => 'table', 'action' => 'export']);
}

afterEach(function (): void {
    Table::clearConfigurations();
    FormsBPage::reset();
});

it('serves a streamed download from a one-time signed link after an Inertia visit', function (): void {
    downloadTable(fn () => response()->streamDownload(function (): void {
        echo DOWNLOAD_BYTES;
    }, 'orders.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']));

    $token = downloadToken($this);

    runDownloadAction($this, $token)->assertRedirect();

    $flash = Inertia::getFlashed()['alfakitAction'];

    expect($flash['status'])->toBe('success')
        ->and($flash['download'])->toContain('/admin/_alfakit/actions/download/')
        ->and($flash['download'])->toContain('signature=');

    $response = $this->get($flash['download'])->assertOk();

    expect($response->headers->get('Content-Disposition'))->toContain('attachment')
        ->and($response->headers->get('Content-Disposition'))->toContain('orders.xlsx')
        ->and($response->headers->get('Content-Type'))->toContain('spreadsheetml')
        ->and($response->streamedContent())->toBe(DOWNLOAD_BYTES);

    // One time only.
    $this->get($flash['download'])->assertNotFound();

    // Kept files never show up as changes in the app's repository.
    expect(file_get_contents(dirname(ActionDownload::path('x')).'/.gitignore'))->toBe("*\n!.gitignore\n");
});

it('serves response()->download() and Storage::download() files intact', function (Closure $handler, string $name): void {
    Storage::fake('local');
    Storage::disk('local')->put('reports/q1.bin', DOWNLOAD_BYTES);
    $source = tempnam(sys_get_temp_dir(), 'alfakit');
    file_put_contents((string) $source, DOWNLOAD_BYTES);
    downloadTable(fn () => $handler((string) $source));

    runDownloadAction($this, downloadToken($this))->assertRedirect();

    $response = $this->get(Inertia::getFlashed()['alfakitAction']['download'])->assertOk();

    expect($response->headers->get('Content-Disposition'))->toContain($name);

    ob_start();
    $response->baseResponse->sendContent();

    expect(ob_get_clean())->toBe(DOWNLOAD_BYTES);
})->with([
    'response()->download()' => [fn (string $path) => response()->download($path, 'report.bin'), 'report.bin'],
    'Storage::download()' => [fn () => Storage::disk('local')->download('reports/q1.bin', 'q1.bin'), 'q1.bin'],
]);

it('refuses the link to another user, a guest, and after it expires', function (): void {
    downloadTable(fn () => response()->streamDownload(fn () => print ('a,b'), 'a.csv'));

    runDownloadAction($this, downloadToken($this))->assertRedirect();
    $url = Inertia::getFlashed()['alfakitAction']['download'];

    $this->actingAs(User::query()->create(['name' => 'Other', 'email' => 'other'.uniqid().'@example.com', 'password' => 'password']));
    $this->get($url)->assertForbidden();

    app('auth')->forgetGuards();
    $this->get($url)->assertForbidden();

    $this->travel(10)->minutes();
    $this->get($url)->assertForbidden();
});

it('leaves redirects and non-Inertia callers as they were', function (): void {
    downloadTable(fn () => redirect()->to('/admin/posts/create'));
    $token = downloadToken($this);

    runDownloadAction($this, $token)->assertRedirect('/admin/posts/create');

    Table::clearConfigurations();
    downloadTable(fn () => response()->streamDownload(fn () => print ('raw'), 'raw.csv'));

    $response = $this->post('/admin/_alfakit/actions', ['token' => $token, 'component' => 'table', 'action' => 'export'])->assertOk();

    expect($response->streamedContent())->toBe('raw');
});

it('answers a field action that returns a file with its download link', function (): void {
    $user = User::query()->create(['name' => 'Fa', 'email' => 'fa'.uniqid().'@example.com', 'password' => 'password']);
    $this->actingAs($user);

    FormsBPage::$formUsing = fn (): array => [
        TextInput::make('code')->suffixAction(Action::make('fetch')->action(fn () => response()->streamDownload(fn () => print (DOWNLOAD_BYTES), 'code.bin'))),
    ];

    $response = $this->postJson('/admin/_alfakit/actions', [
        'token' => FormsBPage::token($user),
        'component' => 'field:form::code',
        'action' => 'fetch',
        'state' => ['code' => 'x'],
    ])->assertOk()->assertJsonPath('status', 'success');

    expect($this->get((string) $response->json('download'))->assertOk()->streamedContent())->toBe(DOWNLOAD_BYTES);
});

it('links to the download under the tenant the action ran for', function (): void {
    AlfaSix\Alfakit\Tests\Fixtures\AuthTenancy\Panels::register('downloads', fn (AlfaSix\Alfakit\Panel $panel): AlfaSix\Alfakit\Panel => $panel
        ->login()
        ->tenant(AlfaSix\Alfakit\Tests\Fixtures\Models\Team::class, slugAttribute: 'slug')
        ->resources([AlfaSix\Alfakit\Tests\Fixtures\Tenancy\ProjectResource::class]));

    $user = AlfaSix\Alfakit\Tests\Fixtures\Models\TenantUser::query()->create(['name' => 'Ten', 'email' => 'ten'.uniqid().'@example.com', 'password' => 'secret']);
    $team = AlfaSix\Alfakit\Tests\Fixtures\Models\Team::query()->create(['name' => 'Alpha', 'slug' => 'alpha']);
    $user->teams()->attach($team->id);
    $this->actingAs($user);

    downloadTable(fn () => response()->streamDownload(fn () => print (DOWNLOAD_BYTES), 'team.bin'));

    $token = inertiaProps(inertiaGet($this, '/downloads/alpha/projects'))['page']['token'];

    inertiaPost($this, '/downloads/alpha/_alfakit/actions', ['token' => $token, 'component' => 'table', 'action' => 'export'])->assertRedirect();

    $url = Inertia::getFlashed()['alfakitAction']['download'];

    expect($url)->toStartWith('http://localhost/downloads/alpha/_alfakit/actions/download/')
        ->and($this->get($url)->assertOk()->streamedContent())->toBe(DOWNLOAD_BYTES);
});

it('serialises download() on URL actions only', function (): void {
    expect(Action::make('file')->url('/files/report.pdf')->download()->toSchema()['download'] ?? null)->toBeTrue()
        ->and(Action::make('file')->url('/files/report.pdf')->toSchema())->not->toHaveKey('download')
        ->and(Action::make('file')->download()->action(fn () => null)->toSchema())->not->toHaveKey('download')
        ->and(Action::make('file')->url('/files/report.pdf')->postToUrl()->download()->toSchema())->not->toHaveKey('download');
});

it('still deletes a deleteFileAfterSend() file once it is kept for the link', function (): void {
    $source = (string) tempnam(sys_get_temp_dir(), 'alfakit');
    file_put_contents($source, DOWNLOAD_BYTES);
    downloadTable(fn () => response()->download($source, 'export.xlsx')->deleteFileAfterSend());

    runDownloadAction($this, downloadToken($this))->assertRedirect();

    expect(file_exists($source))->toBeFalse();

    $response = $this->get(Inertia::getFlashed()['alfakitAction']['download'])->assertOk();

    ob_start();
    $response->baseResponse->sendContent();

    expect(ob_get_clean())->toBe(DOWNLOAD_BYTES);
});
