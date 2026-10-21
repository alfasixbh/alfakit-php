<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Caps the `Link: <…>; rel=modulepreload` header that Laravel's
 * `AddLinkHeadersForPreloadedAssets` (in the starter kits' `web` group) writes for every
 * chunk a page preloads: an AlfaKit page preloads dozens, and headers over 4-8 KB are refused
 * by nginx's default buffers (502) and by Node's HTTP parser. The page's
 * `<link rel="modulepreload">` tags still list every chunk, so nothing loads later.
 *
 * Runs before the panel's `web` group, so it sees the header on the way out. Only the header
 * Laravel built from all of `Vite::preloadedAssets()` is shortened; any other `Link` value,
 * or one the app already limited with `AddLinkHeadersForPreloadedAssets::using()`, is kept.
 * The limit is `alfakit.inertia.preload_link_limit` (`null` keeps every entry).
 */
class LimitPreloadLinkHeader
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $limit = config('alfakit.inertia.preload_link_limit', 20);

        if (! is_numeric($limit) || ! $response->headers->has('Link')) {
            return $response;
        }

        /** @var array<string, array<int, string>> $assets */
        $assets = Vite::preloadedAssets();
        $limit = max(0, (int) $limit);

        if (count($assets) <= $limit) {
            return $response;
        }

        $values = $response->headers->all('link');
        $index = array_search(static::header($assets), $values, true);

        if ($index === false) {
            return $response;
        }

        $values[$index] = static::header(array_slice($assets, 0, $limit, true));
        $response->headers->remove('Link');

        foreach ($values as $value) {
            if (is_string($value) && $value !== '') {
                $response->headers->set('Link', $value, false);
            }
        }

        return $response;
    }

    /**
     * The header value as `AddLinkHeadersForPreloadedAssets` writes it.
     *
     * @param  array<string, array<int, string>>  $assets
     */
    protected static function header(array $assets): string
    {
        $entries = [];

        foreach ($assets as $url => $attributes) {
            $entries[] = "<{$url}>; ".implode('; ', $attributes);
        }

        return implode(', ', $entries);
    }
}
