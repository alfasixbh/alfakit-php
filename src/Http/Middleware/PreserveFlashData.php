<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Http\Middleware;

use AlfaSix\Alfakit\Notifications\Notification;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Session\Store;
use Illuminate\Support\Arr;
use Inertia\Support\SessionKey;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps one-time session flash data (e.g. the "Created" toast flashed before a redirect) for
 * the next real page visit when a background request arrives first.
 *
 * Internal endpoints (`/_alfakit/*`), JSON requests of plugins (select options, deferred
 * schemas, notifications, the command palette, polling…) and assets the browser fetches on its
 * own (`pwa()` icons, manifest, service worker and offline page) are not page visits. Without this,
 * Laravel ages the flash data at the end of such a request, so a request that slips in between
 * a redirect and the page it leads to swallows the toast; one that started before the redirect
 * even writes the session back without it.
 *
 * Flash data that was pending when the request started is hidden from the endpoint (so it is
 * neither consumed nor returned in its JSON) and, when the request ends, the pending flash
 * data of the stored session (re-read, so the page visit that may have shown it meanwhile
 * wins) is kept for the next request. Notifications the endpoint itself sends are flashed as
 * usual: endpoints return them in their JSON (and take them out of the flash data).
 */
class PreserveFlashData
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->isBackgroundRequest($request) || ! $request->hasSession()) {
            return $next($request);
        }

        $session = $request->session();

        if (! $session instanceof Store || ! $session->isStarted()) {
            return $next($request);
        }

        // Hide the flash data that is waiting for the next page from the endpoint.
        $pendingKeys = $this->stringKeys($session->get('_flash.old', []));
        $snapshot = ['_flash' => ['old' => $pendingKeys]];

        foreach ($pendingKeys as $key) {
            if ($session->has($key)) {
                $snapshot[$key] = $session->get($key);
            }
        }

        $session->forget($pendingKeys);
        $session->put('_flash.old', []);

        $response = $next($request);

        $this->restorePendingFlashData($session, $snapshot);

        return $response;
    }

    /**
     * Requests that are not page visits: internal endpoints, JSON (fetch) requests and assets.
     * Inertia visits (including polls and the redirect after an action) show flash data.
     */
    protected function isBackgroundRequest(Request $request): bool
    {
        if ($request->header('X-Inertia')) {
            return false;
        }

        // `pwa()` assets (manifest, service worker, offline page, icons) are fetched by the
        // browser right after a page loads, so they often overlap the next action's POST.
        if ($request->routeIs('*pwa.manifest', '*pwa.service-worker', '*pwa.offline', '*pwa.icon')) {
            return true;
        }

        // Subresources the browser loads on its own (images, scripts, the manifest, workers).
        if (in_array($request->header('Sec-Fetch-Dest'), ['image', 'script', 'style', 'font', 'manifest', 'serviceworker', 'sharedworker', 'worker'], true)) {
            return true;
        }

        $prefix = trim((string) config('alfakit.system_route_prefix', '_alfakit'), '/');

        if ($prefix !== '' && in_array($prefix, explode('/', trim($request->path(), '/')), true)) {
            return true;
        }

        return $request->expectsJson();
    }

    /**
     * Put the flash data that is still pending in the stored session back, so it survives
     * this request's save for the next one. Without a stored session (a new one, or tests
     * that keep the session in memory) the flash data pending when the request started is used.
     *
     * @param  array<string, mixed>  $snapshot
     */
    protected function restorePendingFlashData(Store $session, array $snapshot): void
    {
        $stored = $this->readStoredAttributes($session);

        // Only the CSRF token `start()` generates: nothing is stored under this session id.
        if (array_diff(array_keys($stored), ['_token']) === []) {
            $stored = $snapshot;
        }

        $pending = $this->stringKeys($stored['_flash']['old'] ?? []);

        if ($pending === []) {
            return;
        }

        $own = $this->stringKeys($session->get('_flash.new', []));

        foreach ($pending as $key) {
            // Session keys are dot paths (`inertia.flash_data`).
            if (! Arr::has($stored, $key)) {
                continue;
            }

            $value = Arr::get($stored, $key);

            if (in_array($key, $own, true) && $session->has($key)) {
                $value = $this->mergeFlashValues($key, $value, $session->get($key));
            }

            // Flashed again: survives this request's save, and ages on the next request.
            $session->flash($key, $value);
        }
    }

    /**
     * The pending value of a key the endpoint flashed too: Inertia flash data is merged
     * (pending notifications first), anything else takes the endpoint's value.
     */
    protected function mergeFlashValues(string $key, mixed $pending, mixed $own): mixed
    {
        if ($key !== SessionKey::FLASH_DATA || ! is_array($pending) || ! is_array($own)) {
            return $own;
        }

        $merged = [...$pending, ...$own];
        $notifications = [
            ...array_values((array) ($pending[Notification::FLASH_KEY] ?? [])),
            ...array_values((array) ($own[Notification::FLASH_KEY] ?? [])),
        ];

        if ($notifications !== []) {
            $merged[Notification::FLASH_KEY] = $notifications;
        }

        return $merged;
    }

    /**
     * The session as currently stored by the handler (other requests may have written it
     * since this one started).
     *
     * @return array<string, mixed>
     */
    protected function readStoredAttributes(Store $session): array
    {
        $fresh = clone $session;
        $fresh->flush();
        $fresh->start();

        return $fresh->all();
    }

    /**
     * @return list<string>
     */
    protected function stringKeys(mixed $keys): array
    {
        return array_values(array_filter((array) $keys, 'is_string'));
    }
}
