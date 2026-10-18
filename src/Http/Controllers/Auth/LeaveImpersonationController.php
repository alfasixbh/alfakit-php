<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Http\Controllers\Auth;

use AlfaSix\Alfakit\Auth\Impersonation\Impersonation;
use Illuminate\Http\RedirectResponse;

class LeaveImpersonationController
{
    public function __invoke(): RedirectResponse
    {
        return Impersonation::leave(alfakit()->getCurrentOrDefaultPanel());
    }
}
