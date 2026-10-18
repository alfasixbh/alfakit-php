<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Theming;

/**
 * The colour scheme a panel starts in (`Panel::defaultThemeMode()`) until the user picks
 * one with the theme switcher.
 */
enum ThemeMode: string
{
    case Light = 'light';
    case Dark = 'dark';
    case System = 'system';
}
