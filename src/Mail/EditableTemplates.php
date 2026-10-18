<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Mail;

use AlfaSix\Alfakit\Forms\Components\RichEditor\RichContentRenderer;
use AlfaSix\Alfakit\Mail\Contracts\MailTemplateRepository;
use AlfaSix\Alfakit\Support\Html;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Mail\Markdown;
use Illuminate\Support\HtmlString;
use Stringable;

/**
 * Edited mail templates: finds an admin's version of a mail (`MailTemplateRepository`),
 * fills its merge tags and renders it in the mail theme. Merge tags are `{{ id }}` in
 * subjects and RichEditor merge tags in bodies; values are escaped unless `Htmlable`
 * (`EditableTemplates::button()` builds a themed button).
 */
final class EditableTemplates
{
    /** `{{ id }}` placeholders (ids: letters, digits, `_`, `.`, `-`). */
    public const TAG_PATTERN = '/\{\{\s*([A-Za-z0-9_.\-]+)\s*\}\}/';

    public static function repository(): MailTemplateRepository
    {
        return app(MailTemplateRepository::class);
    }

    /**
     * The edited subject and body of `$key` in `$locale` (default: the current one), also
     * trying the language without its region (`pt_BR` → `pt`).
     *
     * @return array{subject: ?string, body: ?string}|null
     */
    public static function find(string $key, ?string $locale = null): ?array
    {
        $locale ??= app()->getLocale();
        $repository = self::repository();

        foreach (array_unique([$locale, strtok(str_replace('-', '_', $locale), '_') ?: $locale]) as $candidate) {
            $found = $repository->find($key, $candidate);

            if ($found !== null && (filled($found['subject'] ?? null) || filled($found['body'] ?? null))) {
                return ['subject' => filled($found['subject'] ?? null) ? $found['subject'] : null, 'body' => filled($found['body'] ?? null) ? $found['body'] : null];
            }
        }

        return null;
    }

    /**
     * Fill the `{{ id }}` placeholders of a subject (plain text; unknown ones are kept).
     *
     * @param  array<string, mixed>  $data
     */
    public static function renderSubject(string $subject, array $data): string
    {
        return trim((string) preg_replace_callback(self::TAG_PATTERN, static function (array $match) use ($data): string {
            if (! array_key_exists($match[1], $data)) {
                return $match[0];
            }

            return self::stringValue($data[$match[1]]);
        }, $subject));
    }

    /**
     * Sanitise a body, fill its merge tags and render it in the mail theme.
     *
     * @param  array<string, mixed>  $data
     */
    public static function render(string $key, ?string $subject, string $body, array $data, ?string $poweredBy = null, ?string $locale = null): RenderedMailTemplate
    {
        $values = [];

        foreach ($data as $id => $value) {
            $values[(string) $id] = $value instanceof Htmlable ? $value : self::stringValue($value);
        }

        $html = RichContentRenderer::make($body)->mergeTags($values)->toHtml();

        // Placeholders typed as text (`{{ name }}`) are filled too, escaped.
        $html = (string) preg_replace_callback(self::TAG_PATTERN, static function (array $match) use ($values): string {
            $value = $values[$match[1]] ?? null;

            return match (true) {
                $value instanceof Htmlable => $value->toHtml(),
                is_string($value) => e($value),
                default => $match[0],
            };
        }, $html);

        if ($poweredBy !== null && $poweredBy !== '') {
            $html .= '<p class="sub">'.Markdown::parse($poweredBy)->toHtml().'</p>';
        }

        // One line inside a <div>: CommonMark passes an HTML block through untouched.
        $html = '<div>'.preg_replace('/\s*\R\s*/u', ' ', $html).'</div>';

        return new RenderedMailTemplate(
            key: $key,
            locale: $locale ?? app()->getLocale(),
            subject: self::renderSubject((string) $subject, $data),
            html: MailTheme::render('alfakit::mail.template', ['alfakitBody' => $html]),
            text: (string) MailTheme::renderText('alfakit::mail.template', ['alfakitBody' => e(self::toText($html))]),
        );
    }

    /**
     * The edited version of `$key` with `$data` filled in, or null when its body isn't
     * edited (an edited subject alone is applied by `subject()`). Without an edited
     * subject, `$defaultSubject` (else the definition's default subject) is used.
     *
     * @param  array<string, mixed>  $data
     */
    public static function renderEdited(string $key, array $data, ?string $poweredBy = null, ?string $locale = null, ?string $defaultSubject = null): ?RenderedMailTemplate
    {
        $edited = self::find($key, $locale);

        if ($edited === null || $edited['body'] === null) {
            return null;
        }

        $subject = $edited['subject'] ?? $defaultSubject ?? Mails::get($key)?->getDefaultSubject() ?? '';

        return self::render($key, $subject, $edited['body'], $data, $poweredBy, $locale);
    }

    /**
     * The edited subject of `$key` with `$data` filled in, else `$default`.
     *
     * @param  array<string, mixed>  $data
     */
    public static function subject(string $key, array $data, string $default, ?string $locale = null): string
    {
        $subject = self::find($key, $locale)['subject'] ?? null;

        return $subject === null ? $default : self::renderSubject($subject, $data);
    }

    /**
     * The merge tag ids a subject and body use (`{{ id }}` text and editor markers).
     *
     * @return array<int, string>
     */
    public static function mergeTagsIn(?string $subject, ?string $body): array
    {
        $ids = [];

        foreach ([(string) $subject, (string) $body] as $text) {
            preg_match_all(self::TAG_PATTERN, html_entity_decode($text, ENT_QUOTES | ENT_HTML5), $matches);
            array_push($ids, ...$matches[1]);
        }

        preg_match_all('/<span\b[^>]*\bdata-type="mergeTag"[^>]*>/i', (string) $body, $markers);

        foreach ($markers[0] as $marker) {
            if (preg_match('/\bdata-id\s*=\s*"([^"]*)"/i', $marker, $id) === 1) {
                $ids[] = html_entity_decode($id[1], ENT_QUOTES | ENT_HTML5);
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * The merge tags of a subject and body that `$definition` doesn't offer.
     *
     * @return array<int, string>
     */
    public static function unknownMergeTags(MailDefinition $definition, ?string $subject, ?string $body): array
    {
        return array_values(array_diff(self::mergeTagsIn($subject, $body), array_keys($definition->getMergeTags())));
    }

    /**
     * A themed button for a merge tag value: `'button' => EditableTemplates::button($url, __('Open'))`.
     */
    public static function button(string $url, string $label, string $color = 'primary'): HtmlString
    {
        $color = in_array($color, ['primary', 'success', 'error'], true) ? $color : 'primary';

        return new HtmlString(
            '<table class="action" align="center" width="100%" cellpadding="0" cellspacing="0" role="presentation"><tr><td align="center">'
            .'<table border="0" cellpadding="0" cellspacing="0" role="presentation"><tr><td>'
            .'<a href="'.e($url).'" class="button button-'.$color.'" target="_blank" rel="noopener">'.e($label).'</a>'
            .'</td></tr></table></td></tr></table>',
        );
    }

    /**
     * A link for a merge tag value: `EditableTemplates::link($url)`.
     */
    public static function link(string $url, ?string $label = null): HtmlString
    {
        return new HtmlString('<a href="'.e($url).'" target="_blank" rel="noopener">'.e($label ?? $url).'</a>');
    }

    /**
     * Plain text of rendered HTML: block ends become line breaks, links keep their URL.
     */
    public static function toText(string $html): string
    {
        $html = (string) preg_replace('/<(head|style|script|title)\b[^>]*>.*?<\/\1>/is', '', $html);
        $html = (string) preg_replace_callback('/<a\b[^>]*\bhref="([^"]*)"[^>]*>(.*?)<\/a>/is', static function (array $match): string {
            $label = trim(strip_tags($match[2]));
            $url = html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5);

            return $label === '' || $label === $url ? $url : $label.' ('.$url.')';
        }, $html);
        $html = (string) preg_replace('/<br\s*\/?>/i', "\n", $html);
        $html = (string) preg_replace('/<\/(p|h[1-6]|li|tr|blockquote|div|table)>/i', "\n\n", $html);
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5);

        return trim((string) preg_replace("/\n{3,}/", "\n\n", (string) preg_replace('/[ \t]+/', ' ', $text)));
    }

    private static function stringValue(mixed $value): string
    {
        return match (true) {
            $value instanceof Htmlable => Html::toText($value),
            is_scalar($value), $value instanceof Stringable => (string) $value,
            default => '',
        };
    }
}
