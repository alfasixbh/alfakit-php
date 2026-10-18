<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Theming;

enum FontProvider: string
{
    /** fonts.bunny.net: GDPR-friendly drop-in for Google Fonts (the default). */
    case Bunny = 'bunny';

    case Google = 'google';

    /** Don't load anything: the font is self-hosted or a system font. */
    case None = 'none';

    /**
     * Load the stylesheet passed as `url:` (`->font('Inter', url: asset('fonts/inter.css'))`),
     * Filament's `LocalFontProvider`. Implied when `url:` is given.
     */
    case Local = 'local';
}
