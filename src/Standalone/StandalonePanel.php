<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Standalone;

use AlfaSix\Alfakit\Contracts\Plugin;
use AlfaSix\Alfakit\Http\Controllers\ActionController;
use AlfaSix\Alfakit\Http\Controllers\SchemaController;
use AlfaSix\Alfakit\Http\Controllers\StandaloneController;
use AlfaSix\Alfakit\Http\Controllers\TableController;
use AlfaSix\Alfakit\Http\Middleware\PreserveFlashData;
use AlfaSix\Alfakit\Http\Middleware\SetUpPanel;
use AlfaSix\Alfakit\Panel;
use Closure;
use Illuminate\Routing\Router;

/**
 * The hidden panel behind components used outside panels (`Standalone\Form`, `Table`,
 * `Infolist`, `Widgets`, `Component`). It has no pages, navigation, login or layout: it
 * only registers the internal JSON endpoints (actions, schema refresh, select options,
 * table cells, uploads, polling) under `/{alfakit.standalone.path}/_alfakit/*`, and is
 * configured from `config/alfakit.php` instead of a PanelProvider.
 */
class StandalonePanel extends Panel
{
    public const ID = 'standalone';

    public static function make(string $id = self::ID): static
    {
        $panel = new static(self::ID);
        $panel->configure();

        /** @var array<string, mixed> $config */
        $config = (array) config('alfakit.standalone', []);

        $panel
            ->path((string) ($config['path'] ?? ''))
            ->authGuard((string) ($config['guard'] ?? 'web'))
            ->middleware(array_values((array) ($config['middleware'] ?? ['web'])))
            ->authMiddleware(array_values((array) ($config['auth_middleware'] ?? [])))
            ->login(false)
            ->globalSearch(false)
            ->localeSwitcher(false)
            ->brandName((string) config('app.name', 'Laravel'));

        // Plugins whose widgets or fields are used outside panels (calendar, kanban…):
        // their options and frontend translations apply to standalone components.
        foreach ((array) ($config['plugins'] ?? []) as $plugin) {
            if (is_string($plugin) && is_subclass_of($plugin, Plugin::class) && method_exists($plugin, 'make')) {
                $plugin = $plugin::make();
            }

            if ($plugin instanceof Plugin) {
                $panel->plugin($plugin);
            }
        }

        return $panel;
    }

    public static function isEnabled(): bool
    {
        return (bool) config('alfakit.standalone.enabled', true);
    }

    /**
     * Guests may upload to standalone forms (each upload still belongs to the guest's
     * session) unless `alfakit.standalone.guest_uploads` is off.
     */
    public static function allowsGuestUploads(): bool
    {
        return (bool) config('alfakit.standalone.guest_uploads', true);
    }

    /**
     * The host application's own middleware already handles Inertia (shared props, flash,
     * redirects) and locale, so only the panel set-up runs here. `SetPanelLocale` is left
     * out on purpose: standalone components follow the application's locale.
     *
     * @return array<string|Closure>
     */
    public function getMiddleware(): array
    {
        return [SetUpPanel::class.':'.$this->getId(), ...$this->middleware, PreserveFlashData::class];
    }

    /**
     * Only the internal endpoints: no auth pages, no logout, no profile, no home route.
     */
    public function registerRoutes(Router $router): void
    {
        $prefix = $this->getInternalRoutePrefix();
        $guard = $this->getAuthGuard();

        $router->domain($this->getDomain())
            ->prefix($this->getPath())
            ->name($this->getRouteNamePrefix())
            ->middleware($this->getMiddleware())
            ->group(function (Router $router) use ($prefix, $guard): void {
                // Every endpoint authorises itself from the encrypted context token, which is
                // bound to the user it was issued to; the component's `requiresAuthentication()`
                // and `canAccess()` are re-checked on each request (ResolvesContext).
                $router->prefix($prefix)->name('internal.')->group(static function (Router $router) use ($guard): void {
                    $router->post('/actions', [ActionController::class, 'execute'])->name('actions.execute');
                    // Field actions send the form's state, which can be too large for a query string, so the
                    // modal endpoint also takes POST. One route (not a GET and a POST route on the same URI)
                    // keeps Laravel Wayfinder's generated actions valid in starter kit apps.
                    $router->match(['GET', 'HEAD', 'POST'], '/actions/modal', [ActionController::class, 'modal'])->name('actions.modal');
                    $router->post('/schema/refresh', [SchemaController::class, 'refresh'])->name('schema.refresh');
                    $router->get('/schema/options', [SchemaController::class, 'options'])->name('schema.options');
                    $router->post('/schema/validate', [SchemaController::class, 'validate'])->name('schema.validate');
                    $router->post('/table/cell', [TableController::class, 'updateCell'])->name('table.cell');
                    $router->post('/table/reorder', [TableController::class, 'reorder'])->name('table.reorder');
                    $router->post('/table/columns', [TableController::class, 'reorderColumns'])->name('table.columns');
                    $router->post('/table/totals', [TableController::class, 'totals'])->name('table.totals');
                    // Fresh schemas of lazy and polled components (the host page has no
                    // `alfakitPoll` prop to partially reload).
                    $router->get('/standalone/components', [StandaloneController::class, 'components'])->name('standalone.components');

                    $uploads = $router->middleware(static::allowsGuestUploads() ? ['throttle:60,1'] : ['auth:'.$guard]);
                    $uploads->post('/uploads', [StandaloneController::class, 'storeUpload'])->name('uploads.store');
                    $uploads->delete('/uploads', [StandaloneController::class, 'destroyUpload'])->name('uploads.destroy');
                    $uploads->post('/uploads/chunks', [StandaloneController::class, 'startChunked'])->name('uploads.chunks.start');
                    $uploads->get('/uploads/chunks/{upload}', [StandaloneController::class, 'showChunked'])->name('uploads.chunks.show');
                    $uploads->post('/uploads/chunks/{upload}', [StandaloneController::class, 'storeChunk'])->name('uploads.chunks.store');
                    $uploads->post('/uploads/chunks/{upload}/complete', [StandaloneController::class, 'completeChunked'])->name('uploads.chunks.complete');
                    $uploads->delete('/uploads/chunks/{upload}', [StandaloneController::class, 'destroyChunked'])->name('uploads.chunks.destroy');
                });
            });
    }

    /**
     * The path segment of the internal endpoints (`_alfakit`, under the panel path).
     */
    protected function getInternalRoutePrefix(): string
    {
        return trim((string) config('alfakit.system_route_prefix', '_alfakit'), '/');
    }

    /**
     * @return array<string, string>
     */
    public function getEndpoints(): array
    {
        $endpoint = fn (string $name): string => $this->route('internal.'.$name);

        return [
            'action' => $endpoint('actions.execute'),
            'modal' => $endpoint('actions.modal'),
            'schemaRefresh' => $endpoint('schema.refresh'),
            'schemaOptions' => $endpoint('schema.options'),
            'schemaValidate' => $endpoint('schema.validate'),
            'tableCell' => $endpoint('table.cell'),
            'tableReorder' => $endpoint('table.reorder'),
            'tableColumns' => $endpoint('table.columns'),
            'tableTotals' => $endpoint('table.totals'),
            'upload' => $endpoint('uploads.store'),
            'uploadDestroy' => $endpoint('uploads.destroy'),
            'uploadChunks' => $endpoint('uploads.chunks.start'),
            'components' => $endpoint('standalone.components'),
        ];
    }
}
