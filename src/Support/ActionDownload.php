<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Support;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Files returned by action handlers (`response()->streamDownload()`, `response()->download()`,
 * `Storage::download()`, Laravel Excel …). The action endpoint is posted as an Inertia visit
 * (or as JSON for field actions), which cannot carry a file: the file is kept for a moment
 * and the browser fetches it from a signed, one-time link instead.
 */
class ActionDownload
{
    /** Minutes a kept file stays downloadable. */
    public const LIFETIME = 5;

    /** Whether a handler's response is a file to save rather than a page or a redirect. */
    public static function isDownload(Response $response): bool
    {
        if ($response instanceof RedirectResponse || $response->headers->has('X-Inertia')) {
            return false;
        }

        if ($response instanceof BinaryFileResponse || $response instanceof StreamedResponse) {
            return true;
        }

        return str_starts_with(strtolower(trim((string) $response->headers->get('Content-Disposition'))), 'attachment');
    }

    /**
     * Keep the response's body and return the signed link that serves it once, or null when
     * the request's route has no download route next to it.
     */
    public static function store(Response $response, Request $request): ?string
    {
        $route = $request->route();
        $routeName = $route instanceof \Illuminate\Routing\Route ? $route->getName() : null;

        if (! $route instanceof \Illuminate\Routing\Route || $routeName === null || ! str_ends_with($routeName, 'actions.execute')) {
            return null;
        }

        $downloadRoute = substr($routeName, 0, -strlen('actions.execute')).'actions.download';

        if (! app('router')->has($downloadRoute)) {
            return null;
        }

        static::prune();

        $id = Str::random(40);
        $path = static::path($id);
        @mkdir(dirname($path), 0755, true);

        // Laravel's storage/framework/.gitignore lists its own files only: keep these out of the app's repository.
        if (! is_file(dirname($path).'/.gitignore')) {
            @file_put_contents(dirname($path).'/.gitignore', "*\n!.gitignore\n");
        }

        static::writeBody($response, $path);

        return URL::temporarySignedRoute($downloadRoute, now()->addMinutes(static::LIFETIME), [
            ...$route->originalParameters(),
            'download' => $id,
            'name' => static::filename($response),
            'type' => (string) ($response->headers->get('Content-Type') ?: 'application/octet-stream'),
            'owner' => static::ownerKey(),
        ]);
    }

    /** Where a kept file lives. */
    public static function path(string $id): string
    {
        return storage_path('framework/alfakit-downloads/'.$id);
    }

    /** Binds a link to the user who ran the action (empty on guest pages). */
    public static function ownerKey(): string
    {
        $id = alfakit()->auth()?->getAuthIdentifier();

        return $id === null ? '' : substr(hash_hmac('sha256', (string) $id, (string) config('app.key')), 0, 16);
    }

    protected static function writeBody(Response $response, string $path): void
    {
        if (! $response instanceof StreamedResponse && ! $response instanceof BinaryFileResponse) {
            file_put_contents($path, (string) $response->getContent());

            return;
        }

        // Streamed to disk in chunks, so a large export never sits in memory. A file response
        // is sent the same way, so `deleteFileAfterSend()` still removes its file.
        $handle = fopen($path, 'wb');
        $level = ob_get_level();

        ob_start(static function (string $buffer) use ($handle): string {
            if ($handle !== false) {
                fwrite($handle, $buffer);
            }

            return '';
        }, 8192);

        try {
            $response->sendContent();
        } finally {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }

            if ($handle !== false) {
                fclose($handle);
            }
        }
    }

    protected static function filename(Response $response): string
    {
        $disposition = (string) $response->headers->get('Content-Disposition');

        if ($disposition !== '') {
            $parts = array_change_key_case(HeaderUtils::combine(HeaderUtils::split($disposition, ';=')));
            $name = $parts['filename*'] ?? $parts['filename'] ?? null;

            if (is_string($name) && $name !== '') {
                // RFC 5987 `filename*=utf-8''name`.
                if (preg_match("/^[\w-]+'[^']*'(.+)$/", $name, $matches) === 1) {
                    $name = rawurldecode($matches[1]);
                }

                return trim(basename($name), " \"'");
            }
        }

        if ($response instanceof BinaryFileResponse) {
            return $response->getFile()->getFilename();
        }

        return 'download';
    }

    /** Files that were never fetched. */
    protected static function prune(): void
    {
        $files = glob(storage_path('framework/alfakit-downloads/*')) ?: [];
        $expired = time() - (static::LIFETIME + 1) * 60;

        foreach ($files as $file) {
            if (is_file($file) && filemtime($file) < $expired) {
                @unlink($file);
            }
        }
    }
}
