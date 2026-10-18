<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Support;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\HtmlString;

/**
 * The AlfaKit and Alfa Six credits.
 *
 * - `html()`: the "Developed by Alfa Six" line in the panel footer (`Panel::credit()`), on
 *   by default. Styled by `[data-slot='credit']` in the shared theme CSS.
 * - `poweredBy()`: the "Powered by AlfaKit · Alfa Six" line for the pages and emails an
 *   application shows to its own customers (CMS site, public forms, helpdesk portal,
 *   shared dashboards, invoice links, emails). Off by default: turn it on with
 *   `config('alfakit.credit.public_pages')` / `config('alfakit.credit.emails')` or per
 *   plugin with `->poweredBy()`. Styled by `[data-slot='powered-by']`.
 */
final class Credit
{
    public const URL = 'https://alfasixbh.com';

    public const ALFAKIT_URL = 'https://alfakit.dev';

    /** The Alfa Six micro emblem (brand kit v3, no dots), coloured by CSS. */
    public const LOGO = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 608 453" aria-hidden="true" focusable="false"><g fill="currentColor" ><g id="simplified-emblem" transform="translate(64 64) scale(1)"><path d="M402 0 H460 L358 105 C429 101 478 145 478 208 C478 274 431 321 377 323 C357 325 343 322 335 314 C328 306 323 294 316 281 L259 174 C244 145 226 110 204 94 C185 80 162 75 140 78 C118 80 99 89 83 103 C59 122 46 148 44 176 C40 211 56 246 90 265 C126 286 171 283 205 257 C225 240 245 217 260 196 L284 239 C265 259 244 283 223 298 C180 329 113 331 65 305 C24 282 2 239 0 199 C-2 155 17 116 47 85 C87 43 132 25 174 34 C233 45 268 85 294 135 L371 277 C409 272 433 245 431 214 C430 177 405 149 369 150 C351 149 338 155 325 166 L294 111 L381 12 C387 4 392 0 402 0 Z"/></g></g></svg>';

    public static function html(string $url = self::URL): string
    {
        $label = __('alfakit::ui.developed_by', ['company' => '<strong>Alfa Six</strong>']);

        return '<footer data-slot="credit"><a href="'.e($url).'" target="_blank" rel="noopener noreferrer">'.self::LOGO.'<span>'.$label.'</span></a></footer>';
    }

    /**
     * Whether customer-facing pages show the "Powered by" line (`alfakit.credit.public_pages`).
     */
    public static function onPublicPages(): bool
    {
        return (bool) config('alfakit.credit.public_pages', false);
    }

    /**
     * Whether AlfaKit's emails end with the "Powered by" line (`alfakit.credit.emails`).
     */
    public static function inEmails(): bool
    {
        return (bool) config('alfakit.credit.emails', false);
    }

    /**
     * The "Powered by AlfaKit · Alfa Six" line with the Alfa Six emblem, as HTML. It only
     * needs the inline styles to look right, so Blade pages without the theme CSS can use it;
     * the emblem follows `--alfakit-credit-emblem` (Alfa Navy, white in dark mode).
     *
     * `$embedded` draws the emblem as an `<img>` data URI in Alfa Navy and avoids flexbox,
     * for documents rendered by PDF engines (invoice documents).
     */
    public static function poweredBy(bool $embedded = false): HtmlString
    {
        $link = 'color:inherit;font-weight:600;text-decoration:none';

        $label = __('alfakit::ui.powered_by', [
            'product' => '<a href="'.self::ALFAKIT_URL.'" target="_blank" rel="noopener" style="'.$link.'">AlfaKit</a>',
            'company' => '<a href="'.self::URL.'" target="_blank" rel="noopener" style="'.$link.'">Alfa Six</a>',
        ]);

        if ($embedded) {
            $logo = '<img src="'.self::emblemDataUri().'" alt="" width="16" height="12" style="height:1.1em;width:auto;vertical-align:middle;margin-inline-end:0.3em">';

            return new HtmlString('<p data-slot="powered-by" style="margin:0;padding:0.5em 0;text-align:center;font-size:0.75em;line-height:1.4">'.$logo.'<span style="vertical-align:middle">'.$label.'</span></p>');
        }

        $logo = str_replace('<svg ', '<svg style="height:1.125em;width:auto;flex-shrink:0;color:var(--alfakit-credit-emblem,#073b78)" ', self::LOGO);

        return new HtmlString(
            '<p data-slot="powered-by" style="display:flex;align-items:center;justify-content:center;gap:0.375rem;margin:0;padding:0.75rem 1rem;font-size:0.75rem;line-height:1rem">'
            .$logo.'<span>'.$label.'</span></p>',
        );
    }

    /**
     * `poweredBy()` when `$condition` (default: the `public_pages` option) is on, else null.
     */
    public static function poweredByWhen(?bool $condition = null, bool $embedded = false): ?string
    {
        return ($condition ?? self::onPublicPages()) ? self::poweredBy($embedded)->toHtml() : null;
    }

    /**
     * The Alfa Six emblem as an SVG data URI, in Alfa Navy by default.
     */
    public static function emblemDataUri(string $color = '#073b78'): string
    {
        $svg = str_replace(['fill="currentColor" ', ' aria-hidden="true" focusable="false"'], ['fill="'.$color.'"', ''], self::LOGO);

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }

    /**
     * The "Powered by" line as Markdown links, for Markdown emails and `MailMessage` lines.
     * Email clients drop inline SVG, so the email version is text only.
     */
    public static function poweredByMarkdown(): string
    {
        return __('alfakit::ui.powered_by', [
            'product' => '[AlfaKit]('.self::ALFAKIT_URL.')',
            'company' => '[Alfa Six]('.self::URL.')',
        ]);
    }

    /**
     * `poweredByMarkdown()` when `$condition` (default: the `emails` option) is on, else null.
     */
    public static function emailLine(?bool $condition = null): ?string
    {
        return ($condition ?? self::inEmails()) ? self::poweredByMarkdown() : null;
    }

    /**
     * Appends the "Powered by" line to a notification email when `$condition` (default: the
     * `emails` option) is on.
     */
    public static function addToMail(MailMessage $message, ?bool $condition = null): MailMessage
    {
        $line = self::emailLine($condition);

        return $line === null ? $message : $message->line($line);
    }
}
