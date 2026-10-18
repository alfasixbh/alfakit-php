<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Mail;

use AlfaSix\Alfakit\Localization\TextDirection;
use AlfaSix\Alfakit\Panel;
use Closure;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Mail\Markdown;
use Illuminate\Support\HtmlString;

/**
 * The AlfaKit email theme: Laravel mail Markdown (`<x-mail::message>`, `<x-mail::button>`,
 * `<x-mail::panel>`, `<x-mail::table>`, `<x-mail::subcopy>`) rendered with AlfaKit's
 * own components and CSS, inlined by Laravel's CSS inliner.
 *
 * - branded from the panel (`MailBrand`): logo (light and dark), name, primary colour, font;
 * - `lang` and `dir` from the locale the mail is rendered in (the notifiable's
 *   `preferredLocale()`, a mailable's `locale()`), right to left for Arabic, Persian…;
 * - a dark variant through `prefers-color-scheme` (Apple Mail, Outlook apps, Gmail apps…);
 * - table layout with `role="presentation"`, a hidden preheader and accessible contrast.
 *
 * Only AlfaKit's mails use it (`Mail\MailMessage`, the `UsesAlfakitMailTheme` trait):
 * your application's own Markdown mails keep Laravel's theme. Turn it off with
 * `config('alfakit.mail.theme')`; publish the views with the `alfakit-mail` tag.
 */
final class MailTheme
{
    /** The CSS view inlined into every themed mail. */
    public const THEME = 'alfakit::mail.theme';

    /** The brand of the render in progress. */
    private static ?MailBrand $brand = null;

    private static int $renderDepth = 0;

    private static ?Closure $brandUsing = null;

    private static bool $forceDark = false;

    public static function enabled(): bool
    {
        return (bool) config('alfakit.mail.theme', true);
    }

    /**
     * Replace the brand resolution: `fn (?Panel $panel): MailBrand => new MailBrand(...)`.
     */
    public static function brandUsing(?Closure $callback): void
    {
        self::$brandUsing = $callback;
        self::$brand = null;
    }

    /**
     * The brand of the mail being rendered (resolved once per render), or of the
     * current panel outside a render.
     */
    public static function brand(): MailBrand
    {
        if (self::$brand !== null) {
            return self::$brand;
        }

        $brand = self::brandFor(null);

        if (self::$renderDepth > 0) {
            self::$brand = $brand;
        }

        return $brand;
    }

    public static function brandFor(?Panel $panel): MailBrand
    {
        $brand = self::$brandUsing !== null ? (self::$brandUsing)($panel) : null;

        return $brand instanceof MailBrand ? $brand : MailBrand::resolve($panel);
    }

    public static function direction(?string $locale = null): TextDirection
    {
        return TextDirection::detect($locale ?? app()->getLocale());
    }

    /**
     * The `lang` attribute value of the current locale (`pt_BR` → `pt-BR`).
     */
    public static function lang(?string $locale = null): string
    {
        return str_replace('_', '-', $locale ?? app()->getLocale());
    }

    /**
     * Whether the dark styles apply unconditionally (the mail preview's dark variant);
     * normally they follow the reader's `prefers-color-scheme`.
     */
    public static function isDarkForced(): bool
    {
        return self::$forceDark;
    }

    /**
     * A Laravel Markdown renderer with AlfaKit's components and theme. Components you
     * publish to `resources/views/vendor/alfakit/mail/html` win; components AlfaKit
     * doesn't provide (`promotion`, the plain-text ones) fall back to Laravel's.
     */
    public static function renderer(): ThemedMarkdown
    {
        $paths = [dirname(__DIR__, 2).'/resources/views/mail'];
        $published = resource_path('views/vendor/alfakit/mail');

        if (is_dir($published.'/html')) {
            array_unshift($paths, $published);
        }

        return new ThemedMarkdown(app(ViewFactory::class), [
            'theme' => self::THEME,
            'paths' => [...$paths, ...array_values(array_filter((array) config('mail.markdown.paths', []), is_string(...)))],
            'extensions' => (array) config('mail.markdown.extensions', []),
        ]);
    }

    /**
     * Render a Markdown mail view as themed HTML (with the application's mail theme when
     * the AlfaKit theme is off).
     *
     * @param  array<string, mixed>  $data
     */
    public static function render(string $view, array $data = []): HtmlString
    {
        return self::enabled() ? self::renderer()->render($view, $data) : self::rendering(static fn (): HtmlString => app(Markdown::class)->render($view, $data));
    }

    /**
     * Render a Markdown mail view as plain text.
     *
     * @param  array<string, mixed>  $data
     */
    public static function renderText(string $view, array $data = []): HtmlString
    {
        return self::enabled() ? self::renderer()->renderText($view, $data) : self::rendering(static fn (): HtmlString => app(Markdown::class)->renderText($view, $data));
    }

    /**
     * Run `$callback` in `$locale` (as Laravel sends a mail in its recipient's locale).
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public static function withLocale(string $locale, Closure $callback): mixed
    {
        $original = app()->getLocale();

        if ($locale === $original) {
            return $callback();
        }

        app()->setLocale($locale);

        try {
            return $callback();
        } finally {
            app()->setLocale($original);
        }
    }

    /**
     * Run `$callback` with the dark styles applied unconditionally.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public static function withDarkMode(Closure $callback): mixed
    {
        $previous = self::$forceDark;
        self::$forceDark = true;

        try {
            return $callback();
        } finally {
            self::$forceDark = $previous;
        }
    }

    /**
     * Resolve the brand afresh for one render (panel colours may differ per tenant).
     *
     * @internal
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public static function rendering(Closure $callback): mixed
    {
        $previous = self::$brand;
        self::$brand = null;
        self::$renderDepth++;

        try {
            return $callback();
        } finally {
            self::$renderDepth--;
            self::$brand = $previous;
        }
    }

    /**
     * Forget the resolved brand and the `brandUsing()` callback (tests).
     */
    public static function flush(): void
    {
        self::$brand = null;
        self::$brandUsing = null;
        self::$forceDark = false;
        self::$renderDepth = 0;
    }
}
