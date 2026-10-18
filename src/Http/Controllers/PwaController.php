<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Http\Controllers;

use AlfaSix\Alfakit\Panel;
use AlfaSix\Alfakit\Pwa\PwaIcons;
use AlfaSix\Alfakit\Pwa\ServiceWorker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * `Panel::pwa()`: the web app manifest, the service worker, the offline page and the
 * generated icons. None needs a session user.
 */
class PwaController
{
    public function manifest(): JsonResponse
    {
        $panel = $this->panel();

        return response()->json($panel->getPwaManifest(), 200, [
            'Content-Type' => 'application/manifest+json',
            'Cache-Control' => 'public, max-age=3600',
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public function serviceWorker(): Response
    {
        $panel = $this->panel();

        return response(ServiceWorker::script($panel), 200, [
            'Content-Type' => 'application/javascript; charset=utf-8',
            // Served from `/admin/sw.js`, the worker controls `/admin` as well as `/admin/…`.
            'Service-Worker-Allowed' => $panel->getPwaScope(),
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
        ]);
    }

    public function offline(): Response
    {
        $panel = $this->panel();
        $locale = $panel->getCurrentLocale();

        return response()->view('alfakit::pwa.offline', [
            'panel' => $panel,
            'lang' => $locale->getTag(),
            'dir' => $locale->getDirection()->value,
            'name' => $panel->getPwaName(),
            'themeColor' => $panel->getPwaThemeColor(),
            'darkThemeColor' => $panel->getPwaDarkThemeColor(),
            'icon' => $panel->getPwaAppleTouchIcon(),
            'scope' => $panel->getPwaScope(),
            'offline' => (string) parse_url($panel->getPwaOfflineUrl(), PHP_URL_PATH),
        ], 200, ['Cache-Control' => 'no-cache']);
    }

    public function icon(string $icon): Response
    {
        $panel = $this->panel();
        $rendered = PwaIcons::render($panel, $icon);

        abort_if($rendered === null, 404);

        [$body, $type] = $rendered;

        return response($body, 200, [
            'Content-Type' => $type,
            'Cache-Control' => 'public, max-age=86400',
            'ETag' => '"'.md5($body).'"',
        ]);
    }

    protected function panel(): Panel
    {
        $panel = alfakit()->getCurrentOrDefaultPanel();

        abort_unless($panel->hasPwa(), 404);

        return $panel;
    }
}
