<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Http\Controllers\Auth;

use AlfaSix\Alfakit\Notifications\Notification;
use Illuminate\Auth\Events\Verified;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class VerifyEmailController
{
    public function __invoke(Request $request, string $id, string $hash): RedirectResponse
    {
        $panel = alfakit()->getCurrentOrDefaultPanel();
        $user = $panel->auth()->user();

        abort_unless($user instanceof MustVerifyEmail, 403);
        abort_unless((string) $user->getKey() === $id, 403);
        abort_unless(hash_equals($hash, sha1($user->getEmailForVerification())), 403);

        if (! $user->hasVerifiedEmail() && $user->markEmailAsVerified()) {
            event(new Verified($user));
        }

        Notification::make()->success()->title(__('alfakit::auth.email_verification.verified'))->send();

        return redirect()->to($panel->getUrl());
    }
}
