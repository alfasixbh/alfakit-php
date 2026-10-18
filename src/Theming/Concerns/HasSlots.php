<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Theming\Concerns;

use AlfaSix\Alfakit\Pages\Page;
use AlfaSix\Alfakit\Resources\Pages\Page as ResourcePage;
use AlfaSix\Alfakit\Resources\RelationManagers\RelationManager;
use AlfaSix\Alfakit\Schemas\Components\Component;
use AlfaSix\Alfakit\Theming\Slot;
use AlfaSix\Alfakit\Theming\Slots;
use AlfaSix\Alfakit\Widgets\Widget;
use Closure;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Throwable;

/**
 * Insert content into named places of the layout ({@see Slot}).
 *
 * ```php
 * $panel->slot(Slot::TOPBAR_END, fn () => view('admin.status-badge'));
 * $panel->slot(Slot::SIDEBAR_FOOTER, Text::make('v2.4.1')->color('gray'));
 * $panel->slot(Slot::PAGE_HEADER_AFTER, View::make('sales-chart'), scopes: [ListOrders::class]);
 * ```
 *
 * Content can be HTML (a string, `Htmlable` or Blade view), any schema component such as
 * `Text`, `Html` or `View` (a component registered in your frontend), an array of those,
 * or a closure returning them. Closures may ask for `$scopes` (the current page class and
 * its resource) and `$data` (Filament's render hook `$data`: `panel`, `page`, `resource`,
 * `record` on record pages and `tenant`, plus whatever `renderSlot()` passes). `scopes`
 * limits it to pages or whole resources; relation manager and widget classes limit
 * component slots (tables, relation managers, table widgets) to them. `when` limits it to
 * renders whose slot data matches, for slots rendered once per item: `when: ['column' =>
 * 'title']` on `Slot::TABLE_HEADER_CELL`, `when: ['action' => 'delete']` on the modal
 * slots. `Slots::register()` adds content to every panel.
 */
trait HasSlots
{
    /** @var array<string, array<int, array{content: mixed, scopes: array<int, string>, when?: array<string, mixed>}>> */
    protected array $slots = [];

    /**
     * @param  class-string|array<int, class-string>  $scopes  page, resource, relation manager or widget classes
     * @param  array<string, scalar|null|array<int, scalar|null>>  $when  slot data the render must match (`['column' => 'title']`)
     */
    public function slot(string $name, mixed $content, string|array $scopes = [], array $when = []): static
    {
        $this->slots[$name][] = ['content' => $content, 'scopes' => array_values((array) $scopes), 'when' => $when];

        return $this;
    }

    /**
     * This panel's slot entries followed by those of `Slots::register()`.
     *
     * @return array<string, array<int, array{content: mixed, scopes: array<int, string>, when?: array<string, mixed>}>>
     */
    public function getSlotEntries(): array
    {
        $entries = $this->slots;

        foreach (Slots::forPanel($this->getId()) as $name => $global) {
            $entries[$name] = [...($entries[$name] ?? []), ...$global];
        }

        return $entries;
    }

    /**
     * Rendered slot content for the current request, keyed by slot name.
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    public function getSlotsSchema(?Request $request = null): array
    {
        $request ??= request();
        $page = $this->resolveSlotPage($request);
        $pageTypes = Slot::pageTypes();
        $injections = $this->getSlotInjections($request, $page);
        $rendered = [];

        foreach ($this->getSlotEntries() as $name => $entries) {
            // `<head>` slots are rendered by the root view.
            if (in_array($name, Slot::head(), true)) {
                continue;
            }

            // Page-type slots (List page table, sign-in form…) only exist on those pages.
            if (isset($pageTypes[$name]) && ($page === null || ! is_a($page, $pageTypes[$name], true))) {
                continue;
            }

            foreach ($entries as $entry) {
                $components = null;

                if (! $this->slotMatchesScopes($entry['scopes'], $page)) {
                    // Scoped to relation managers or widgets: the renderer shows it in
                    // the slots of those components only (`for`).
                    $components = $this->getSlotComponentScopes($entry['scopes']);

                    if ($components === []) {
                        continue;
                    }
                }

                $when = $entry['when'] ?? [];

                foreach ($this->renderSlotContent($entry['content'], $injections) as $item) {
                    if ($components !== null) {
                        $item['for'] = $components;
                    }

                    if ($when !== []) {
                        $item['when'] = $when;
                    }

                    $rendered[$name][] = $item;
                }
            }
        }

        if ($this->hasCredit()) {
            $rendered[Slot::FOOTER][] = ['type' => 'html', 'html' => $this->renderCredit()];
        }

        return $rendered;
    }

    /**
     * The HTML of a `<head>` slot (`Slot::HEAD_START` / `Slot::HEAD_END`) for the root view.
     * Closures receive the CSP nonce as `$nonce`, and `<script>` / `<style>` tags without a
     * nonce get it added, so inline tags pass a nonce-based Content Security Policy.
     */
    public function renderHeadSlot(string $name, ?string $nonce = null, ?Request $request = null): string
    {
        $request ??= request();
        $page = $this->resolveSlotPage($request);
        $injections = $this->getSlotInjections($request, $page, ['nonce' => $nonce]);
        $html = '';

        foreach ($this->getSlotEntries()[$name] ?? [] as $entry) {
            if (! $this->slotMatchesScopes($entry['scopes'], $page)) {
                continue;
            }

            foreach ($this->renderSlotContent($entry['content'], ['nonce' => $nonce, ...$injections]) as $item) {
                // Schema components need the renderer; they can't live in `<head>`.
                if (($item['type'] ?? null) === 'html') {
                    $html .= (string) $item['html'];
                }
            }
        }

        if ($nonce !== null && $nonce !== '') {
            $html = (string) preg_replace_callback(
                '/<(script|style)\b(?![^>]*\bnonce=)([^>]*)>/i',
                static fn (array $match): string => '<'.$match[1].' nonce="'.e($nonce).'"'.$match[2].'>',
                $html,
            );
        }

        return $html;
    }

    /**
     * Render one slot with extra `$data` for its closures, for content rendered once per
     * item on the server (a plugin's per-record view, a custom component's serialiser).
     * Entries scoped to other pages, and entries whose `when` doesn't match `$data`, are
     * skipped. Returns the same items as the `slots` schema (`html` or `component`).
     *
     * ```php
     * $panel->renderSlot(Slot::TABLE_HEADER_CELL, ['column' => 'title']);
     * ```
     *
     * @param  array<string, mixed>  $data
     * @return array<int, array<string, mixed>>
     */
    public function renderSlot(string $name, array $data = [], ?Request $request = null): array
    {
        $request ??= request();
        $page = $this->resolveSlotPage($request);
        $injections = $this->getSlotInjections($request, $page, $data);
        $items = [];

        foreach ($this->getSlotEntries()[$name] ?? [] as $entry) {
            if (! $this->slotMatchesScopes($entry['scopes'], $page) || ! self::slotDataMatches($entry['when'] ?? [], $data)) {
                continue;
            }

            array_push($items, ...$this->renderSlotContent($entry['content'], $injections));
        }

        return $items;
    }

    /**
     * Whether render data satisfies `when` conditions (a list value matches any entry).
     *
     * @param  array<string, mixed>  $when
     * @param  array<string, mixed>  $data
     */
    public static function slotDataMatches(array $when, array $data): bool
    {
        foreach ($when as $key => $expected) {
            $actual = $data[$key] ?? null;

            if (is_array($expected) ? ! in_array($actual, $expected, true) : $expected !== $actual) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $injections  named closure parameters (`scopes`, `data`, `nonce`)
     * @return array<int, array<string, mixed>>
     */
    protected function renderSlotContent(mixed $content, array $injections = []): array
    {
        if ($content instanceof Closure) {
            $content = $this->evaluate($content, $injections);
        }

        if ($content === null || $content === '' || $content === false) {
            return [];
        }

        if (is_array($content)) {
            return array_merge(...array_map(fn (mixed $item): array => $this->renderSlotContent($item, $injections), array_values($content)) ?: [[]]);
        }

        if ($content instanceof Component) {
            return $content->isHidden() ? [] : [['type' => 'component', 'component' => $content->toSchema()]];
        }

        if ($content instanceof View) {
            return [['type' => 'html', 'html' => $content->render()]];
        }

        if ($content instanceof Htmlable) {
            return [['type' => 'html', 'html' => $content->toHtml()]];
        }

        return [['type' => 'html', 'html' => (string) $content]];
    }

    /**
     * @param  array<int, string>  $scopes
     */
    protected function slotMatchesScopes(array $scopes, ?string $page): bool
    {
        if ($scopes === []) {
            return true;
        }

        if ($page === null) {
            return false;
        }

        foreach ($scopes as $scope) {
            if ($page === $scope || is_subclass_of($page, $scope)) {
                return true;
            }

            if (is_subclass_of($page, ResourcePage::class) && $page::getResource() === $scope) {
                return true;
            }
        }

        return false;
    }

    /**
     * The `for` keys of scopes that name relation managers or widgets: the `component`
     * of their tables and slots (`relationManager:<class>`, `widget:<id>`).
     *
     * @param  array<int, string>  $scopes
     * @return array<int, string>
     */
    protected function getSlotComponentScopes(array $scopes): array
    {
        $keys = [];

        foreach ($scopes as $scope) {
            if (is_subclass_of($scope, RelationManager::class)) {
                $keys[] = 'relationManager:'.$scope;
            } elseif (is_subclass_of($scope, Widget::class)) {
                $keys[] = 'widget:'.Widget::idFromClass($scope);
            }
        }

        return $keys;
    }

    /**
     * `$scopes` for slot closures (Filament's render hook `$scopes`): the current page
     * class and, on resource pages, its resource.
     *
     * @return array{scopes: array<int, string>}
     */
    protected function getSlotScopeInjection(?string $page): array
    {
        if ($page === null) {
            return ['scopes' => []];
        }

        return ['scopes' => is_subclass_of($page, ResourcePage::class) ? [$page, $page::getResource()] : [$page]];
    }

    /**
     * Named closure parameters for slot content: `$scopes` and `$data` (the panel, page,
     * resource, record of record pages and tenant, plus `$extra`).
     *
     * @param  array<string, mixed>  $extra
     * @return array{scopes: array<int, string>, data: array<string, mixed>}
     */
    protected function getSlotInjections(Request $request, ?string $page, array $extra = []): array
    {
        $scopes = $this->getSlotScopeInjection($page);
        $resource = $page !== null && is_subclass_of($page, ResourcePage::class) ? $page::getResource() : null;

        return [
            ...$scopes,
            'data' => [
                'panel' => $this,
                'page' => $page,
                'resource' => $resource,
                'record' => $this->resolveSlotRecord($request),
                'tenant' => $this->getTenant(),
                ...$extra,
            ],
        ];
    }

    /**
     * The record of the current record page (edit, view, manage related records), if
     * the page has already run. Doesn't instantiate the controller.
     */
    protected function resolveSlotRecord(Request $request): ?Model
    {
        $controller = $request->route()->controller ?? null;

        if (! $controller instanceof ResourcePage) {
            return null;
        }

        try {
            return $controller->getRecord();
        } catch (Throwable) {
            return null;
        }
    }

    protected function resolveSlotPage(Request $request): ?string
    {
        $controller = $request->route()?->getControllerClass();

        return is_string($controller) && is_subclass_of($controller, Page::class) ? $controller : null;
    }
}
