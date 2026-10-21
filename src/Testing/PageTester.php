<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Testing;

use AlfaSix\Alfakit\Http\Middleware\HandleInertiaRequests;
use AlfaSix\Alfakit\Notifications\Notification;
use AlfaSix\Alfakit\Pages\Page;
use AlfaSix\Alfakit\Panel;
use AlfaSix\Alfakit\Resources\Pages\Page as ResourcePage;
use AlfaSix\Alfakit\Support\Debug\SchemaDebugger;
use Illuminate\Support\Arr;
use Illuminate\Support\Traits\Macroable;
use Illuminate\Testing\TestResponse;
use Inertia\Inertia;
use Inertia\Support\SessionKey;
use InvalidArgumentException;
use PHPUnit\Framework\Assert;

/**
 * Fluent test helpers for a panel page, in the spirit of Filament's Livewire helpers but
 * driven through the real HTTP endpoints: the page is loaded as an Inertia response, and
 * forms, actions and tables talk to `/_alfakit/*` with the page's context token, exactly
 * as the renderer does. Validation, authorisation and tenancy therefore run as in the
 * browser.
 *
 * ```php
 * alfakit()->testPage(EditPost::class, ['record' => $post])
 *     ->fillForm(['title' => 'Hello'])
 *     ->call('save')
 *     ->assertHasNoFormErrors();
 * ```
 *
 * Authenticate first (`actingAs($user)`): the requests share the test's application.
 */
class PageTester
{
    use Concerns\TestsActions;
    use Concerns\TestsForms;
    use Concerns\TestsNotifications;
    use Concerns\TestsSchemas;
    use Concerns\TestsTables;
    use Macroable;

    protected PanelClient $client;

    /** The page URL without table state. */
    protected string $url;

    protected ?string $panelId;

    /** The latest response of the page itself. */
    protected TestResponse $pageResponse;

    /** The latest response of any request (page, action, refresh…). */
    protected TestResponse $lastResponse;

    /** @var array<string, mixed> Inertia props of the latest successful page load */
    protected array $props = [];

    /** @var array<string, array<int, string>> Validation errors of the latest form or action request */
    protected array $errors = [];

    /** Name of the action the latest request ran, for failure messages. */
    protected ?string $lastActionName = null;

    /** @var array<string, mixed>|null The `alfakitAction` flash of the latest action */
    protected ?array $actionResult = null;

    /** Where the latest action redirected to, if anywhere. */
    protected ?string $redirectUrl = null;

    /** @var array<int, array<string, mixed>> Notifications sent so far */
    protected array $notifications = [];

    /**
     * @param  class-string<Page>|string  $page  A page class or a URL
     * @param  array<string, mixed>  $parameters  Route parameters (`record`, `tenant`…)
     */
    public function __construct(string $page, array $parameters = [], ?string $panel = null)
    {
        $this->client = new PanelClient;
        $this->panelId = $panel;
        $this->url = static::resolveUrl($page, $parameters, $panel === null ? null : alfakit()->getPanel($panel));

        $this->load();
    }

    /**
     * @param  class-string<Page>|string  $page
     * @param  array<string, mixed>  $parameters
     */
    public static function make(string $page, array $parameters = [], ?string $panel = null): static
    {
        return new static($page, $parameters, $panel);
    }

    /**
     * The URL of a page class (resource pages by their registered name), or the given URL.
     *
     * @param  array<string, mixed>  $parameters
     */
    public static function resolveUrl(string $page, array $parameters = [], ?Panel $panel = null): string
    {
        if (str_starts_with($page, '/') || str_contains($page, '://')) {
            return $page;
        }

        if (is_subclass_of($page, ResourcePage::class)) {
            $name = $page::getResourcePageName() ?? throw new InvalidArgumentException("Page [{$page}] is not registered in [{$page::getResource()}::getPages()].");

            return $page::getResource()::getUrl($name, $parameters, panel: $panel);
        }

        if (is_subclass_of($page, Page::class)) {
            return $page::getUrl($parameters, panel: $panel);
        }

        throw new InvalidArgumentException("[{$page}] is neither an Alfakit page class nor a URL.");
    }

    // ---------------------------------------------------------------- Page

    /**
     * Load the page again (with the current table state), as a browser reload would.
     */
    public function refresh(): static
    {
        return $this->load();
    }

    protected function load(): static
    {
        $this->pageResponse = $this->lastResponse = $this->getPageResponseFor($this->getUrlWithTableState());

        if ($this->pageResponse->getStatusCode() === 200 && is_array($props = $this->pageResponse->json('props'))) {
            $this->props = $props;
            $this->syncFormFromPage();
            $this->loadDeferredTable();
            $this->loadRelationManagerTable();
        }

        return $this;
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public function getResponse(): TestResponse
    {
        return $this->lastResponse;
    }

    public function getPageResponse(): TestResponse
    {
        return $this->pageResponse;
    }

    /**
     * Inertia props of the latest page load, or one of them by dot path (`page.title`).
     */
    public function getProps(?string $key = null): mixed
    {
        return $key === null ? $this->props : Arr::get($this->props, $key);
    }

    /**
     * The `page` prop: title, header actions, content blocks, token…
     *
     * @return array<string, mixed>
     */
    public function getPage(): array
    {
        return (array) ($this->props['page'] ?? []);
    }

    public function getToken(): string
    {
        $token = $this->getPage()['token'] ?? null;

        Assert::assertIsString($token, "The page [{$this->url}] did not render (status {$this->pageResponse->getStatusCode()}), so it has no context token.");

        return $token;
    }

    /**
     * A content block of the page by its `component` (`form`, `table`) or `key`.
     *
     * @return array<string, mixed>|null
     */
    public function getContentBlock(string $component): ?array
    {
        foreach ((array) ($this->getPage()['content'] ?? []) as $block) {
            if (is_array($block) && (($block['component'] ?? null) === $component || ($block['key'] ?? null) === $component)) {
                return $block;
            }
        }

        return null;
    }

    public function assertSuccessful(): static
    {
        $this->pageResponse->assertOk();

        return $this;
    }

    public function assertOk(): static
    {
        return $this->assertSuccessful();
    }

    public function assertForbidden(): static
    {
        return $this->assertStatus(403);
    }

    public function assertNotFound(): static
    {
        return $this->assertStatus(404);
    }

    /**
     * Status of the latest request: the page load, or the latest action or refresh.
     */
    public function assertStatus(int $status): static
    {
        Assert::assertSame($status, $this->lastResponse->getStatusCode(), "Expected status {$status}, got {$this->lastResponse->getStatusCode()} from [{$this->describeLastRequest()}].");

        return $this;
    }

    public function assertTitle(string $title): static
    {
        Assert::assertSame($title, $this->getPage()['title'] ?? null);

        return $this;
    }

    public function assertHeading(string $heading): static
    {
        Assert::assertSame($heading, $this->getPage()['heading'] ?? null);

        return $this;
    }

    // ---------------------------------------------------------------- Debugging

    /**
     * The resolved schema tree of the page as the debug panel sees it: every component,
     * action, column, widget and relation manager with its visibility, authorisation and
     * the reason it is hidden, plus the closures that ran and server timings.
     *
     * @return array<string, mixed>
     */
    public function getDebugTree(): array
    {
        $debug = $this->props[Page::DEBUG_PROP] ?? null;

        if (! is_array($debug)) {
            // The debug prop is only in responses while the debug panel is active; a test may
            // ask for it explicitly (the header is ignored outside `runningUnitTests()`).
            $response = $this->getPageResponseFor($this->getUrlWithTableState(), [SchemaDebugger::TEST_HEADER => '1']);
            $debug = $response->json('props.'.Page::DEBUG_PROP);
        }

        Assert::assertIsArray($debug, "The page [{$this->url}] did not render a debug tree (status {$this->pageResponse->getStatusCode()}).");

        return $debug;
    }

    /**
     * Print the resolved schema tree (visibility and authorisation per component) and keep going.
     */
    public function dump(): static
    {
        dump(SchemaDebugger::render($this->getDebugTree()));

        return $this;
    }

    /**
     * Print the resolved schema tree and stop the test.
     */
    public function dd(): never
    {
        dd(SchemaDebugger::render($this->getDebugTree()));
    }

    // ---------------------------------------------------------------- Requests

    /**
     * POST an action to the action endpoint, as `ActionRunner::execute()` does.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function postAction(array $payload): TestResponse
    {
        $this->lastActionName = is_string($payload['action'] ?? null) ? $payload['action'] : null;
        $this->forgetFlash();

        $response = $this->lastResponse = $this->client->post($this->endpoint('action', '/_alfakit/actions'), [
            'token' => $this->getToken(),
            'tableQuery' => http_build_query($this->tableState),
            ...array_filter($payload, static fn (mixed $value): bool => $value !== null),
        ], [...$this->inertiaHeaders(), 'Accept' => 'application/json', 'Referer' => $this->getUrlWithTableState()]);

        $this->errors = $response->getStatusCode() === 422 ? $this->normalizeErrors((array) $response->json('errors')) : [];
        $this->captureFlash();
        $this->redirectUrl = $response->isRedirect() ? $response->headers->get('Location') : null;

        return $response;
    }

    /**
     * After an action that changed something, reload the page so forms, tables and
     * actions reflect the new data, as Inertia does after following the redirect.
     * A page that can no longer be opened (a deleted record) keeps its last props.
     */
    protected function reloadAfterAction(): void
    {
        $actionResponse = $this->lastResponse;
        $response = $this->getPageResponseFor($this->getUrlWithTableState());

        if ($response->getStatusCode() === 200 && is_array($props = $response->json('props'))) {
            $this->pageResponse = $response;
            $this->props = $props;
            $this->syncFormFromPage();
            $this->loadDeferredTable();
            $this->loadRelationManagerTable();
        }

        // Assertions about "the latest request" are about the action, not this reload.
        $this->lastResponse = $actionResponse;
    }

    /**
     * @param  array<string, mixed>  $query
     */
    protected function getJson(string $endpoint, string $fallback, array $query): TestResponse
    {
        $url = $this->endpoint($endpoint, $fallback);

        return $this->lastResponse = $this->client->get($url.'?'.http_build_query(array_filter($query, static fn (mixed $value): bool => $value !== null)), ['Accept' => 'application/json']);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function postJson(string $endpoint, string $fallback, array $data): TestResponse
    {
        return $this->lastResponse = $this->client->postJson($this->endpoint($endpoint, $fallback), $data);
    }

    /**
     * An internal endpoint of the panel, as shared with the renderer.
     */
    protected function endpoint(string $name, string $fallback): string
    {
        $url = Arr::get($this->props, "alfakit.endpoints.{$name}");

        if (is_string($url) && $url !== '') {
            return $url;
        }

        $panel = $this->panelId === null ? alfakit()->getCurrentOrDefaultPanel() : alfakit()->getPanel($this->panelId);

        return rtrim(url($panel->getPath()), '/').$fallback;
    }

    /**
     * An Inertia GET of a page. The asset version is only known once the panel's
     * middleware ran, so a version conflict (409) is retried once with the real version.
     *
     * @param  array<string, string>  $headers
     */
    protected function getPageResponseFor(string $url, array $headers = []): TestResponse
    {
        $response = $this->client->get($url, [...$this->inertiaHeaders(), ...$headers]);

        if ($response->getStatusCode() === 409) {
            $response = $this->client->get($url, [...$this->inertiaHeaders(), ...$headers]);
        }

        return $response;
    }

    /**
     * The headers of an Inertia visit with the app's asset version, for raw requests in tests
     * (`get('/admin/posts', PageTester::inertiaHeaders())`). Inertia only knows the version
     * once a request has run, and any other version answers 409 once the app has a Vite build.
     *
     * @return array<string, string>
     */
    public static function inertiaHeaders(): array
    {
        $version = (string) Inertia::getVersion();

        if ($version === '') {
            $version = (string) app(HandleInertiaRequests::class)->version(request());
        }

        return ['X-Inertia' => 'true', 'X-Inertia-Version' => $version];
    }

    protected function forgetFlash(): void
    {
        if (app()->bound('session')) {
            session()->forget(SessionKey::FLASH_DATA);
        }
    }

    protected function captureFlash(): void
    {
        $flash = app()->bound('session') ? (array) session()->get(SessionKey::FLASH_DATA, []) : [];

        $this->actionResult = is_array($flash['alfakitAction'] ?? null) ? $flash['alfakitAction'] : null;

        foreach ((array) ($flash[Notification::FLASH_KEY] ?? []) as $notification) {
            if (is_array($notification)) {
                $this->notifications[] = $notification;
            }
        }
    }

    /**
     * @param  array<mixed>  $errors
     * @return array<string, array<int, string>>
     */
    protected function normalizeErrors(array $errors): array
    {
        $normalized = [];

        foreach ($errors as $key => $messages) {
            $normalized[(string) $key] = array_values(array_map(strval(...), (array) $messages));
        }

        return $normalized;
    }

    protected function describeLastRequest(): string
    {
        return $this->lastActionName !== null ? "action {$this->lastActionName}" : $this->url;
    }

    protected function assertLastActionDidNotFail(): void
    {
        $status = $this->lastResponse->getStatusCode();

        if ($status >= 400 && $status !== 422) {
            $message = $this->lastResponse->json('message');

            Assert::fail("The request for [{$this->describeLastRequest()}] failed with status {$status}".(is_string($message) && $message !== '' ? ": {$message}" : '').'.');
        }
    }
}
