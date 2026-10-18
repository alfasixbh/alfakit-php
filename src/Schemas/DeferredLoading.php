<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Schemas;

use Closure;

/**
 * Whether the schemas being serialised send `deferLoading()` components as placeholders.
 * Only a page's first render does (`Page::render()`); the deferred-schema endpoint, live
 * refreshes, partial reloads and action modals render every component in full.
 */
final class DeferredLoading
{
    private static bool $rendersPlaceholders = false;

    public static function rendersPlaceholders(): bool
    {
        return self::$rendersPlaceholders;
    }

    /**
     * Run `$callback` with placeholders on (or off), restoring the previous mode after.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public static function withPlaceholders(Closure $callback, bool $condition = true): mixed
    {
        $previous = self::$rendersPlaceholders;
        self::$rendersPlaceholders = $condition;

        try {
            return $callback();
        } finally {
            self::$rendersPlaceholders = $previous;
        }
    }
}
