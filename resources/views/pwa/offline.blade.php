@php($nonce = \Illuminate\Support\Facades\Vite::cspNonce())
<!DOCTYPE html>
<html lang="{{ $lang }}" dir="{{ $dir }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="theme-color" media="(prefers-color-scheme: light)" content="{{ $themeColor }}">
        <meta name="theme-color" media="(prefers-color-scheme: dark)" content="{{ $darkThemeColor }}">
        <title>{{ __('alfakit::panel.pwa.offline.title') }} · {{ $name }}</title>
        <style @if ($nonce) nonce="{{ $nonce }}" @endif>
            :root { color-scheme: light dark; --bg: #ffffff; --fg: #0a0a0a; --muted: #737373; --brand: {{ $themeColor }}; }
            @media (prefers-color-scheme: dark) { :root { --bg: #0a0a0a; --fg: #fafafa; --muted: #a3a3a3; } }
            * { box-sizing: border-box; }
            body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 1.5rem; background: var(--bg); color: var(--fg); font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; }
            main { max-width: 24rem; text-align: center; display: grid; gap: 1rem; justify-items: center; }
            img { width: 4rem; height: 4rem; border-radius: 1rem; }
            h1 { margin: 0; font-size: 1.25rem; font-weight: 600; }
            p { margin: 0; color: var(--muted); line-height: 1.5; }
            button { appearance: none; border: 0; border-radius: .5rem; padding: .625rem 1rem; font: inherit; font-weight: 500; background: var(--brand); color: #fff; cursor: pointer; }
            button:focus-visible { outline: 3px solid color-mix(in srgb, var(--brand) 50%, transparent); outline-offset: 2px; }
        </style>
    </head>
    <body data-slot="pwa-offline">
        <main>
            @if ($icon)
                <img src="{{ $icon }}" alt="" aria-hidden="true">
            @endif
            <p>{{ $name }}</p>
            <h1>{{ __('alfakit::panel.pwa.offline.heading') }}</h1>
            <p>{{ __('alfakit::panel.pwa.offline.body') }}</p>
            <button type="button" id="alfakit-retry">{{ __('alfakit::panel.pwa.offline.retry') }}</button>
        </main>
        <script @if ($nonce) nonce="{{ $nonce }}" @endif>
            (function () {
                var scope = @json($scope);
                function target() {
                    var url = new URLSearchParams(location.search).get('url');
                    if (url && url.charAt(0) === '/' && url.charAt(1) !== '/' && (scope === '/' || url === scope || url.indexOf(scope + '/') === 0)) return url;
                    return null;
                }
                function retry() {
                    var url = target();
                    // Served as the fallback of any page, the address is still that page's.
                    if (url) location.href = url; else if (location.pathname === @json($offline)) location.href = scope; else location.reload();
                }
                document.getElementById('alfakit-retry').addEventListener('click', retry);
                window.addEventListener('online', retry);
            })();
        </script>
    </body>
</html>
