<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Support\Enums;

/**
 * Where the panel's language menu is offered (`Panel::languageSwitcher()`). Auth pages
 * keep their own switcher whatever the position.
 */
enum LanguageSwitcherPosition: string
{
    /** A "Language" submenu in the user menu (the default). */
    case UserMenu = 'user-menu';

    /** A languages icon button in the top bar, next to the theme switcher. */
    case Topbar = 'topbar';

    /** Both the top bar button and the user menu submenu. */
    case Both = 'both';

    /** Neither: the language follows the browser, the session or a persisted preference. */
    case Hidden = 'hidden';

    public function inTopbar(): bool
    {
        return $this === self::Topbar || $this === self::Both;
    }

    public function inUserMenu(): bool
    {
        return $this === self::UserMenu || $this === self::Both;
    }
}
