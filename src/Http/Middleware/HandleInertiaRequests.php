<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Http\Middleware;

use AlfaSix\Alfakit\Panel;
use AlfaSix\Alfakit\Support\Assets\ViteManifest;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Inertia\Middleware;
use Symfony\Component\HttpFoundation\Response;

/**
 * Shares the panel description (`alfakit` prop) with every Inertia page of the panel.
 */
class HandleInertiaRequests extends Middleware
{
    /**
     * While the debug panel or the dev tools are active, every panel response (pages and
     * internal endpoints) carries a `Server-Timing` header with the server time, read by
     * the debug drawer and the schema inspector.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Full page loads render `@vite`: use the manifest cached by `alfakit:optimize`.
        if (! $request->hasHeader('X-Inertia')) {
            ViteManifest::prime();
        }

        $response = parent::handle($request, $next);

        if (($panel = alfakit()->getCurrentPanel()) !== null && ($panel->hasDebugPanel() || $panel->hasDevTools())) {
            $startedAt = (float) $request->server('REQUEST_TIME_FLOAT', microtime(true));

            $response->headers->set('Server-Timing', sprintf('alfakit;dur=%.1f', (microtime(true) - $startedAt) * 1000));
        }

        return $response;
    }

    public function rootView(Request $request): string
    {
        // `starterKit()`: the host application's root view (its Vite entry, fonts, appearance).
        $panel = alfakit()->getCurrentPanel();

        if ($panel?->isStarterKit() === true) {
            return $panel->getStarterKitRootView();
        }

        return (string) config('alfakit.inertia.root_view', 'alfakit::app');
    }

    public function version(Request $request): ?string
    {
        // Inertia hashes `build/manifest.json` on every request; `alfakit:optimize` stores
        // the same hash next to its copy of the manifest.
        if (! config('app.asset_url') && ($hash = ViteManifest::hash(public_path('build/manifest.json'))) !== null) {
            return $hash;
        }

        return parent::version($request);
    }

    /**
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $panel = alfakit()->getCurrentOrDefaultPanel();
        $appearance = $this->resolveAppearance($request, $panel);

        // The root Blade view renders before any Inertia prop is available to it, so the
        // server-side `dark` class needs the value as view data too.
        View::share('alfakitAppearance', $appearance);

        return [
            ...parent::share($request),
            // The navigation is split into structure and state, and the static parts are
            // once props (sent again only when their content changes; see `HasInertiaProps`).
            'alfakit' => fn (): array => $panel->getSharedInertiaSchema(),
            'alfakitAppearance' => $appearance,
            'alfakitSidebarOpen' => $this->resolveSidebarState($request),
            // `deferNavigationBadges()`: `alfakitNavigationBadges`, loaded after the page.
            ...$panel->getDeferredSharedProps(),
        ];
    }

    /**
     * The colour scheme stored by the renderer (`alfakit_appearance` cookie), constrained
     * by the panel's `darkMode()` configuration.
     *
     * @return 'light'|'dark'|'system'
     */
    protected function resolveAppearance(Request $request, Panel $panel): string
    {
        // Forced dark mode, no dark mode, `themeSwitcher(false)` and `defaultThemeMode()`
        // are resolved on the server so the first paint already has the right scheme.
        $appearance = $request->cookie('alfakit_appearance');

        return $panel->resolveAppearance(is_string($appearance) ? $appearance : null);
    }

    /**
     * Whether the user last left the sidebar expanded (`alfakit_sidebar_state` cookie written
     * by the renderer), or null when they never toggled it. Read here so a server-rendered
     * page starts with the same sidebar as the browser.
     */
    protected function resolveSidebarState(Request $request): ?bool
    {
        return match ($request->cookie('alfakit_sidebar_state')) {
            'true' => true,
            'false' => false,
            default => null,
        };
    }
}
