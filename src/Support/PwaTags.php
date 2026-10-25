<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Support;

use AlfaSix\Alfakit\Panel;
use Illuminate\Support\HtmlString;
use Throwable;

/**
 * The `@alfakitPwa` Blade directive: a `pwa()` panel's head tags in a root view AlfaKit
 * doesn't own (a starter kit's `app.blade.php`). Without a panel id it uses the current
 * panel, else the first starter kit panel, else the default one.
 */
final class PwaTags
{
    public static function render(?string $panelId = null): HtmlString
    {
        try {
            $panel = $panelId !== null ? alfakit()->getPanel($panelId) : self::guess();
        } catch (Throwable) {
            return new HtmlString('');
        }

        return $panel?->renderPwaHeadTags() ?? new HtmlString('');
    }

    private static function guess(): ?Panel
    {
        $current = alfakit()->getCurrentPanel();

        if ($current !== null) {
            return $current;
        }

        foreach (alfakit()->getPanels() as $panel) {
            if ($panel->isStarterKit()) {
                return $panel;
            }
        }

        return alfakit()->getDefaultPanel();
    }
}
