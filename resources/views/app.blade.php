@php
    $alfakitPanel = alfakit()->getCurrentOrDefaultPanel();
    $alfakitLocale = $alfakitPanel->getCurrentLocale();
    $alfakitCspNonce = \Illuminate\Support\Facades\Vite::cspNonce();
@endphp
<!DOCTYPE html>
<html
    lang="{{ $alfakitLocale->getTag() }}"
    dir="{{ $alfakitLocale->getDirection()->value }}"
    @foreach ($alfakitPanel->getHtmlThemeAttributes() as $attribute => $value) {{ $attribute }}="{{ $value }}" @endforeach
    style="{{ $alfakitPanel->getHtmlThemeStyle() }}"
    @class(['dark' => ($alfakitAppearance ?? 'system') === 'dark'])
>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        {!! $alfakitPanel->renderHeadSlot(\AlfaSix\Alfakit\Theming\Slot::HEAD_START, $alfakitCspNonce) !!}

        {{-- Apply the stored or system colour scheme before paint to avoid flashes. --}}
        <script @if ($alfakitCspNonce) nonce="{{ $alfakitCspNonce }}" @endif>
            (function () {
                const appearance = @json($alfakitAppearance ?? 'system');
                const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;

                if (appearance === 'dark' || (appearance === 'system' && prefersDark)) {
                    document.documentElement.classList.add('dark');
                }
            })();
        </script>

        <style @if ($alfakitCspNonce) nonce="{{ $alfakitCspNonce }}" @endif>
            html { background-color: oklch(1 0 0); }
            html.dark { background-color: oklch(0.145 0 0); }
        </style>

        @if ($alfakitColorCss = $alfakitPanel->getCustomColorCss())
            <style data-alfakit-colors @if ($alfakitCspNonce) nonce="{{ $alfakitCspNonce }}" @endif>{!! $alfakitColorCss !!}</style>
        @endif

        {{-- `font(preload: [...])`: font files fetched before the stylesheet asks for them. --}}
        {{ $alfakitPanel->getFontPreloadHtml() }}

        @foreach ($alfakitPanel->getFontUrls() as $fontUrl)
            @if ($loop->first)
                <link rel="preconnect" href="{{ parse_url($fontUrl, PHP_URL_SCHEME).'://'.parse_url($fontUrl, PHP_URL_HOST) }}" crossorigin>
            @endif
            <link rel="stylesheet" href="{{ $fontUrl }}">
        @endforeach

        {{-- `pwa()`: manifest, theme colours and the iOS home screen icon. --}}
        {{ $alfakitPanel->renderPwaHeadTags() }}

        {{-- `alfakitPanelFavicon` false: the page brings its own (a CMS site design's favicon). --}}
        @if (($alfakitPanelFavicon ?? true) && ($favicon = $alfakitPanel->getFavicon()))
            <link rel="icon" href="{{ $favicon }}" data-alfakit-favicon>
        @endif

        {{-- Plugin stylesheets and scripts (Assets::register()), before the app's own CSS so it can override them. --}}
        {!! \AlfaSix\Alfakit\Support\Assets\Assets::renderTags($alfakitPanel->getId(), $alfakitCspNonce) !!}

        {{ $alfakitPanel->renderViteTags() }}

        <x-inertia::head>
            <title>{{ $alfakitPanel->getBrandName() }}</title>
        </x-inertia::head>

        {!! $alfakitPanel->renderHeadSlot(\AlfaSix\Alfakit\Theming\Slot::HEAD_END, $alfakitCspNonce) !!}

        {{-- CMS public pages: the head snippet of Site design (`CmsPlugin::customCode()`). --}}
        {{ $alfakitCmsCode ?? '' }}
    </head>
    <body class="font-sans antialiased">
        <x-inertia::app />

        {{ $alfakitCmsBodyCode ?? '' }}
    </body>
</html>
