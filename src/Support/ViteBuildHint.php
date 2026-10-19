<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Support;

use Illuminate\Foundation\ViteException;

/**
 * Explains a missing AlfaKit Vite entry: the frontend was built before AlfaKit added its
 * entries (the panel entry, the starter kit page or its stylesheet), so it needs a new build.
 */
final class ViteBuildHint
{
    public const HINT = 'AlfaKit added this file to your frontend, so build it with `npm run build` (or keep `npm run dev` running while developing); `php artisan alfakit:doctor` checks the setup.';

    public static function explain(ViteException $exception): ViteException
    {
        $message = $exception->getMessage();

        if (str_contains($message, 'alfakit:doctor') || ! self::mentionsAlfakit($message)) {
            return $exception;
        }

        return new ViteException($message.' '.self::HINT, previous: $exception);
    }

    public static function mentionsAlfakit(string $message): bool
    {
        return preg_match('#resources/(js/alfakit/|js/pages/Alfakit/|css/alfakit\.css)#i', $message) === 1;
    }
}
