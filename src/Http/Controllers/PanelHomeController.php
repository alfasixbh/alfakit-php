<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Http\Controllers;

use Illuminate\Http\RedirectResponse;

/**
 * The tenant-less panel root (`/admin`): redirects to the user's home. A controller rather
 * than a closure, so `php artisan route:cache` can serialise the panel's routes.
 */
class PanelHomeController
{
    public function __invoke(): RedirectResponse
    {
        return redirect(alfakit()->getCurrentOrDefaultPanel()->getUrl());
    }
}
