<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Forms\Components\RichEditor;

use AlfaSix\Alfakit\Support\TemporaryUpload;
use AlfaSix\Alfakit\Support\TemporaryUrls;
use Closure;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Private rich-editor attachments can't be embedded with a URL that works forever. The
 * saved content keeps the disk's plain `url()` (stable, but not readable on a private
 * bucket); this class swaps it for a temporary URL when the content is shown, and back
 * when it's saved. Only images stored by the editor itself (`{directory}/{ulid}.{ext}` on
 * the attachments disk) are touched; other images are left alone.
 */
final class FileAttachmentUrls
{
    /** @deprecated The lifetime is `config('alfakit.temporary_file_url_expiry_minutes')` (or the constructor's). */
    public const LIFETIME_MINUTES = 30;

    private readonly Filesystem $disk;

    private readonly string $directory;

    /**
     * @param  int|null  $expiryMinutes  Lifetime of the temporary URLs (rounded up to the end of
     *                                   the hour); null uses `config('alfakit.temporary_file_url_expiry_minutes')`.
     */
    public function __construct(string $disk, ?string $directory, private readonly ?int $expiryMinutes = null)
    {
        $this->disk = Storage::disk($disk);
        $this->directory = trim((string) $directory, '/');
    }

    /**
     * Replace stored attachment URLs with temporary ones.
     */
    public function sign(mixed $content): mixed
    {
        return $this->map($content, function (string $path): ?string {
            try {
                return $this->disk->temporaryUrl($path, TemporaryUrls::expiresAt($this->expiryMinutes));
            } catch (Throwable) {
                return null; // The driver can't sign URLs: keep what was stored.
            }
        });
    }

    /**
     * The temporary URL of one attachment URL, or null when it isn't one of this editor's
     * attachments (or the driver can't sign URLs).
     */
    public function signUrl(string $url): ?string
    {
        $path = $this->getAttachmentPath($url);

        if ($path === null) {
            return null;
        }

        try {
            return $this->disk->temporaryUrl($path, TemporaryUrls::expiresAt($this->expiryMinutes));
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Replace temporary attachment URLs with the stable URL that is stored.
     */
    public function unsign(mixed $content): mixed
    {
        return $this->map($content, function (string $path): ?string {
            try {
                return $this->disk->url($path);
            } catch (Throwable) {
                return null;
            }
        });
    }

    /**
     * The disk paths of the attachments the content references (HTML, Markdown, JSON
     * documents and translatable values).
     *
     * @return list<string>
     */
    public function paths(mixed $content): array
    {
        $paths = [];

        $this->map($content, function (string $path) use (&$paths): ?string {
            $paths[$path] = true;

            return null;
        });

        return array_keys($paths);
    }

    /**
     * Remove the images whose attachment path `$allowed` rejects. Images that aren't this
     * editor's attachments (other URLs, `tmp:` uploads) are kept.
     *
     * @param  Closure(string): bool  $allowed
     */
    public function filter(mixed $content, Closure $allowed): mixed
    {
        $rejects = function (string $src) use ($allowed): bool {
            $path = $this->getAttachmentPath($src);

            return $path !== null && ! $allowed($path);
        };

        if (is_string($content)) {
            $content = (string) preg_replace_callback('/<img\b[^>]*>/i', static function (array $tag) use ($rejects): string {
                if (preg_match('/\bsrc\s*=\s*(["\'])([^"\']*)\1/i', $tag[0], $src) !== 1) {
                    return $tag[0];
                }

                return $rejects(html_entity_decode($src[2], ENT_QUOTES | ENT_HTML5)) ? '' : $tag[0];
            }, $content);

            return (string) preg_replace_callback('/!\[([^\]]*)\]\(([^)\s]+)\)/', static fn (array $match): string => $rejects($match[2]) ? '' : $match[0], $content);
        }

        if (! is_array($content)) {
            return $content;
        }

        $isList = array_is_list($content);

        foreach ($content as $key => $value) {
            if ($key === 'attrs') {
                continue;
            }

            if (is_array($value) && ($value['type'] ?? null) === 'image' && is_string($value['attrs']['src'] ?? null) && $rejects($value['attrs']['src'])) {
                unset($content[$key]);

                continue;
            }

            if (is_array($value) || is_string($value)) {
                $content[$key] = $this->filter($value, $allowed);
            }
        }

        // Keep JSON `content` lists sequential after removing nodes.
        return $isList ? array_values($content) : $content;
    }

    /**
     * @param  Closure(string): ?string  $url  Builds the new URL for an attachment path
     */
    private function map(mixed $content, Closure $url): mixed
    {
        if (is_string($content)) {
            return $this->mapString($content, $url);
        }

        if (! is_array($content)) {
            return $content;
        }

        // A JSON (Tiptap) image node.
        if (($content['type'] ?? null) === 'image' && is_array($content['attrs'] ?? null) && is_string($content['attrs']['src'] ?? null)) {
            $content['attrs']['src'] = $this->replace($content['attrs']['src'], $url);
        }

        // JSON documents, and translatable fields (one value per locale).
        foreach ($content as $key => $value) {
            if ($key !== 'attrs' && (is_array($value) || is_string($value))) {
                $content[$key] = $this->map($value, $url);
            }
        }

        return $content;
    }

    /**
     * @param  Closure(string): ?string  $url
     */
    private function mapString(string $content, Closure $url): string
    {
        // HTML: <img src="…">
        $content = (string) preg_replace_callback('/<img\b[^>]*>/i', function (array $tag) use ($url): string {
            if (preg_match('/\bsrc\s*=\s*(["\'])([^"\']*)\1/i', $tag[0], $src, PREG_OFFSET_CAPTURE) !== 1) {
                return $tag[0];
            }

            $current = html_entity_decode($src[2][0], ENT_QUOTES | ENT_HTML5);
            $replaced = $this->replace($current, $url);

            if ($replaced === $current) {
                return $tag[0];
            }

            return substr_replace($tag[0], htmlspecialchars($replaced, ENT_QUOTES | ENT_HTML5), $src[2][1], strlen($src[2][0]));
        }, $content);

        // Markdown: ![alt](…)
        return (string) preg_replace_callback('/!\[([^\]]*)\]\(([^)\s]+)\)/', fn (array $match): string => '!['.$match[1].']('.$this->replace($match[2], $url).')', $content);
    }

    /**
     * @param  Closure(string): ?string  $url
     */
    private function replace(string $current, Closure $url): string
    {
        $path = $this->getAttachmentPath($current);

        return $path === null ? $current : ($url($path) ?? $current);
    }

    /**
     * The disk path of an attachment URL (stable or temporary), or null when the URL isn't
     * one of this editor's attachments.
     */
    public function getAttachmentPath(string $url): ?string
    {
        // Images uploaded while editing (`tmp:{ulid}.png`) aren't stored yet.
        if (str_starts_with($url, TemporaryUpload::PREFIX)) {
            return null;
        }

        $urlPath = parse_url($url, PHP_URL_PATH);

        if (! is_string($urlPath) || $urlPath === '') {
            return null;
        }

        $pattern = '#(?:^|/)('.($this->directory !== '' ? preg_quote($this->directory, '#').'/' : '').'[0-9A-HJKMNP-TV-Z]{26}(?:\.[a-z0-9]+)?)$#i';

        if (preg_match($pattern, rawurldecode($urlPath), $match) !== 1) {
            return null;
        }

        $path = $match[1];
        $host = parse_url($url, PHP_URL_HOST);

        // The URL must point at the attachments disk, not at a lookalike path elsewhere.
        foreach ([fn (): string => $this->disk->url($path), fn (): string => $this->disk->temporaryUrl($path, now()->addMinute())] as $candidate) {
            try {
                if (parse_url($candidate(), PHP_URL_HOST) === $host) {
                    return $path;
                }
            } catch (Throwable) {
                // The driver lacks this kind of URL.
            }
        }

        return null;
    }
}
