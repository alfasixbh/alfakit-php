<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Http\Controllers\Auth;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class LogoutController
{
    public function __invoke(Request $request): RedirectResponse
    {
        $panel = alfakit()->getCurrentOrDefaultPanel();

        $panel->auth()->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->to($panel->getLoginUrl() ?? url('/'));
    }
}
