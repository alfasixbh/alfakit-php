<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Http\Controllers;

use AlfaSix\Alfakit\Localization\LocaleResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Validation\Rule;

/**
 * Switches the panel language: stores the choice in the session and a long-lived
 * cookie, persists it for signed-in users when the panel says how, then goes back.
 */
class LocaleController
{
    public function __invoke(Request $request): RedirectResponse
    {
        $panel = alfakit()->getCurrentOrDefaultPanel();

        $data = $request->validate([
            'locale' => ['required', 'string', Rule::in(array_keys($panel->getLocales()))],
        ]);

        $locale = $data['locale'];

        $request->session()->put(LocaleResolver::SESSION_KEY, $locale);
        Cookie::queue(LocaleResolver::COOKIE, $locale, 60 * 24 * 365);

        $user = $panel->auth()->user();

        if ($user !== null && ($persist = $panel->getPersistLocaleUsing()) !== null) {
            $persist($user, $locale);
        }

        return back();
    }
}
