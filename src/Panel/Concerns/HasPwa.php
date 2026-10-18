<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Panel\Concerns;

use AlfaSix\Alfakit\Http\Controllers\PushSubscriptionController;
use AlfaSix\Alfakit\Http\Controllers\PwaController;
use AlfaSix\Alfakit\Pwa\PwaIcons;
use AlfaSix\Alfakit\Pwa\Vapid;
use AlfaSix\Alfakit\Support\Colors\Color;
use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Routing\Router;
use Illuminate\Support\HtmlString;
use Throwable;

/**
 * `Panel::pwa()`: the panel as an installable app with a web app manifest, a service
 * worker (app shell cache, offline page, update prompt) and optional Web Push.
 */
trait HasPwa
{
    protected bool|Closure $hasPwa = false;

    /** @var array<int|string, mixed>|Closure|null */
    protected array|Closure|null $pwaIcons = null;

    protected bool|Closure $hasPwaPush = false;

    protected string|Closure|null $pwaName = null;

    protected string|Closure|null $pwaShortName = null;

    protected string|Closure|null $pwaDescription = null;

    protected string|Closure|null $pwaThemeColor = null;

    protected string|Closure|null $pwaDarkThemeColor = null;

    protected string|Closure|null $pwaBackgroundColor = null;

    /** @var array<string, mixed>|Closure */
    protected array|Closure $pwaManifest = [];

    /**
     * Make the panel an installable app. Off by default.
     *
     * ```php
     * $panel->pwa(
     *     icons: ['192x192' => '/icons/app-192.png', '512x512' => '/icons/app-512.png'],
     *     push: true,
     * );
     * ```
     *
     * @param  array<int|string, mixed>|Closure|null  $icons  `['192x192' => '/icon.png']` or manifest icon arrays (`src`, `sizes`, `type`, `purpose`); generated from the favicon or brand initial when omitted
     * @param  bool|Closure  $push  Web Push: database notifications also reach subscribed devices (needs VAPID keys, `php artisan alfakit:pwa-keys`)
     * @param  array<string, mixed>|Closure  $manifest  Extra manifest members, merged last (`categories`, `shortcuts`, `screenshots`, …)
     */
    public function pwa(
        bool|Closure $condition = true,
        array|Closure|null $icons = null,
        bool|Closure $push = false,
        string|Closure|null $name = null,
        string|Closure|null $shortName = null,
        string|Closure|null $description = null,
        string|Closure|null $themeColor = null,
        string|Closure|null $darkThemeColor = null,
        string|Closure|null $backgroundColor = null,
        array|Closure $manifest = [],
    ): static {
        $this->hasPwa = $condition;
        $this->pwaIcons = $icons;
        $this->hasPwaPush = $push;
        $this->pwaName = $name;
        $this->pwaShortName = $shortName;
        $this->pwaDescription = $description;
        $this->pwaThemeColor = $themeColor;
        $this->pwaDarkThemeColor = $darkThemeColor;
        $this->pwaBackgroundColor = $backgroundColor;
        $this->pwaManifest = $manifest;

        return $this;
    }

    public function hasPwa(): bool
    {
        return (bool) $this->evaluate($this->hasPwa);
    }

    /** Whether Web Push is on (`pwa(push: true)`), whatever the VAPID configuration. */
    public function hasPwaPush(): bool
    {
        return $this->hasPwa() && (bool) $this->evaluate($this->hasPwaPush);
    }

    /** Whether Web Push is on and VAPID keys are configured. */
    public function isPwaPushConfigured(): bool
    {
        return $this->hasPwaPush() && Vapid::isConfigured();
    }

    /** Routes exist whenever `pwa()` was called with anything but `false` (closures decide per request). */
    protected function registersPwaRoutes(): bool
    {
        return $this->hasPwa !== false;
    }

    public function getPwaName(): string
    {
        return (string) ($this->evaluate($this->pwaName) ?? $this->getBrandName());
    }

    public function getPwaShortName(): string
    {
        $shortName = $this->evaluate($this->pwaShortName);

        if (is_string($shortName) && $shortName !== '') {
            return $shortName;
        }

        $name = $this->getPwaName();

        // Home screens truncate around 12 characters.
        return mb_strlen($name) <= 12 ? $name : trim(mb_substr($name, 0, 12));
    }

    /** The manifest `theme_color` and the light `<meta name="theme-color">`: the primary colour by default. */
    public function getPwaThemeColor(): string
    {
        $color = $this->evaluate($this->pwaThemeColor);

        if (is_string($color) && $color !== '') {
            return self::pwaHexColor($color) ?? $color;
        }

        $primary = $this->getThemeColors()['primary'] ?? null;

        return (is_string($primary) ? self::pwaHexColor($primary) : null) ?? '#171717';
    }

    /** The dark `<meta name="theme-color">`: the dark page background by default. */
    public function getPwaDarkThemeColor(): string
    {
        $color = $this->evaluate($this->pwaDarkThemeColor);

        return is_string($color) && $color !== '' ? (self::pwaHexColor($color) ?? $color) : '#0a0a0a';
    }

    public function getPwaBackgroundColor(): string
    {
        $color = $this->evaluate($this->pwaBackgroundColor);

        return is_string($color) && $color !== '' ? (self::pwaHexColor($color) ?? $color) : '#ffffff';
    }

    protected static function pwaHexColor(string $color): ?string
    {
        try {
            return Color::convertToHex($color);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Where the app lives: the panel path without a trailing slash (`/admin`), so both
     * `/admin` and `/admin/…` are inside it. `/` for a panel at the site root.
     */
    public function getPwaScope(): string
    {
        $path = (string) parse_url(url($this->getPath()), PHP_URL_PATH);
        $path = rtrim($path, '/');

        return $path === '' ? '/' : $path;
    }

    public function getPwaManifestUrl(): string
    {
        return $this->route('pwa.manifest', isAbsolute: false);
    }

    public function getPwaServiceWorkerUrl(): string
    {
        return $this->route('pwa.service-worker', isAbsolute: false);
    }

    public function getPwaOfflineUrl(): string
    {
        return $this->route('pwa.offline', isAbsolute: false);
    }

    /**
     * Manifest icons: `pwa(icons: …)`, or the favicon / brand logo (SVG as-is, raster
     * images padded onto the theme colour) with an initial-letter fallback.
     *
     * @return array<int, array<string, string>>
     */
    public function getPwaIcons(): array
    {
        $icons = $this->evaluate($this->pwaIcons);

        if (is_array($icons) && $icons !== []) {
            return PwaIcons::normalize($icons);
        }

        return PwaIcons::defaults($this);
    }

    /** The `apple-touch-icon` (iOS ignores manifest icons): the largest PNG icon. */
    public function getPwaAppleTouchIcon(): ?string
    {
        $best = null;
        $bestSize = 0;

        foreach ($this->getPwaIcons() as $icon) {
            if (($icon['purpose'] ?? 'any') === 'maskable' || ! str_contains($icon['type'] ?? '', 'png')) {
                continue;
            }

            $size = (int) explode('x', $icon['sizes'] ?? '0')[0];

            if ($size >= $bestSize) {
                $best = $icon['src'];
                $bestSize = $size;
            }
        }

        return $best;
    }

    /**
     * The web app manifest.
     *
     * @return array<string, mixed>
     */
    public function getPwaManifest(): array
    {
        $locale = $this->getCurrentLocale();
        $scope = $this->getPwaScope();
        $extra = $this->evaluate($this->pwaManifest);

        return array_filter([
            'id' => $scope,
            'name' => $this->getPwaName(),
            'short_name' => $this->getPwaShortName(),
            'description' => $this->evaluate($this->pwaDescription),
            'start_url' => $scope,
            'scope' => $scope,
            'display' => 'standalone',
            'theme_color' => $this->getPwaThemeColor(),
            'background_color' => $this->getPwaBackgroundColor(),
            'lang' => $locale->getTag(),
            'dir' => $locale->getDirection()->value,
            'icons' => $this->getPwaIcons(),
            ...(is_array($extra) ? $extra : []),
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /**
     * `<head>` tags: the manifest, light and dark theme colours, the iOS icon and title.
     * Empty without `pwa()`.
     */
    public function renderPwaHeadTags(): HtmlString
    {
        if (! $this->hasPwa()) {
            return new HtmlString('');
        }

        $e = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES);
        $tags = [
            '<link rel="manifest" href="'.$e($this->getPwaManifestUrl()).'">',
            '<meta name="theme-color" media="(prefers-color-scheme: light)" content="'.$e($this->getPwaThemeColor()).'">',
            '<meta name="theme-color" media="(prefers-color-scheme: dark)" content="'.$e($this->getPwaDarkThemeColor()).'">',
            '<meta name="mobile-web-app-capable" content="yes">',
            '<meta name="apple-mobile-web-app-capable" content="yes">',
            '<meta name="apple-mobile-web-app-title" content="'.$e($this->getPwaShortName()).'">',
        ];

        if (($touchIcon = $this->getPwaAppleTouchIcon()) !== null) {
            $tags[] = '<link rel="apple-touch-icon" href="'.$e($touchIcon).'">';
        }

        return new HtmlString(implode("\n        ", $tags));
    }

    /**
     * What the renderers need: where the service worker lives, and (signed in, with push
     * configured) the VAPID public key and the subscription endpoint.
     *
     * @return array<string, mixed>|null
     */
    protected function getPwaSchema(?Authenticatable $user): ?array
    {
        if (! $this->hasPwa()) {
            return null;
        }

        try {
            $serviceWorker = $this->getPwaServiceWorkerUrl();
        } catch (Throwable) {
            return null;
        }

        return [
            'serviceWorker' => $serviceWorker,
            'scope' => $this->getPwaScope(),
            ...($user !== null && $this->isPwaPushConfigured() ? ['push' => [
                'publicKey' => Vapid::publicKey(),
                'endpoint' => $this->route(($this->hasTenancy() && $this->getTenant() !== null ? 'internal.tenant.' : 'internal.').'pwa.push'),
            ]] : []),
        ];
    }

    /** Manifest, service worker, offline page and generated icons (no authentication). */
    protected function registerPwaRoutes(Router $router): void
    {
        if (! $this->registersPwaRoutes()) {
            return;
        }

        $router->get('/manifest.webmanifest', [PwaController::class, 'manifest'])->name('pwa.manifest');
        $router->get('/sw.js', [PwaController::class, 'serviceWorker'])->name('pwa.service-worker');
        $router->get('/offline', [PwaController::class, 'offline'])->name('pwa.offline');
        $router->get('/pwa/icon-{icon}', [PwaController::class, 'icon'])->name('pwa.icon')->where('icon', '(192|512|maskable-512)\.png|monogram\.svg');
    }

    /** Whether push routes exist: `pwa(push: …)` with anything but `false`. */
    public function hasPwaPushRoutes(): bool
    {
        return $this->registersPwaRoutes() && $this->hasPwaPush !== false;
    }

    /** `pwa(push: true)`: the signed-in user's subscriptions (inside the authenticated internal group). */
    protected function registerPwaPushRoutes(Router $router): void
    {
        if (! $this->hasPwaPushRoutes()) {
            return;
        }

        $router->post('/pwa/push', [PushSubscriptionController::class, 'store'])->name('pwa.push');
        $router->delete('/pwa/push', [PushSubscriptionController::class, 'destroy'])->name('pwa.push.destroy');
    }
}
