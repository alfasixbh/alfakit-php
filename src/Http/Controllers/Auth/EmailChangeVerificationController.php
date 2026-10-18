<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Http\Controllers\Auth;

use AlfaSix\Alfakit\Auth\EmailChange\EmailChangeVerification;
use AlfaSix\Alfakit\Notifications\Notification;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;

/**
 * The signed links in the email-change messages: `verify` (sent to the new address) and
 * `block` (sent to the old one). The signature and the pending token identify the request,
 * so the links work whether or not the user is signed in.
 */
class EmailChangeVerificationController
{
    public function verify(string $id, string $token): RedirectResponse
    {
        $panel = alfakit()->getCurrentOrDefaultPanel();
        $user = $this->findUser($id);

        $verified = $user !== null && EmailChangeVerification::verify($panel, $user, $token);

        $verified
            ? Notification::make()->success()->title(__('alfakit::email-change.verified'))->send()
            : Notification::make()->danger()->title(__('alfakit::email-change.invalid'))->send();

        return $this->redirect();
    }

    public function block(string $id, string $token): RedirectResponse
    {
        $panel = alfakit()->getCurrentOrDefaultPanel();
        $user = $this->findUser($id);

        $blocked = $user !== null && EmailChangeVerification::block($panel, $user, $token);

        $blocked
            ? Notification::make()->success()->title(__('alfakit::email-change.blocked'))->send()
            : Notification::make()->warning()->title(__('alfakit::email-change.invalid'))->send();

        return $this->redirect();
    }

    protected function findUser(string $id): (Model&Authenticatable)|null
    {
        $guard = alfakit()->getCurrentOrDefaultPanel()->auth();
        $provider = method_exists($guard, 'getProvider') ? $guard->getProvider() : null;
        $user = $provider?->retrieveById($id);

        return $user instanceof Model ? $user : null;
    }

    protected function redirect(): RedirectResponse
    {
        $panel = alfakit()->getCurrentOrDefaultPanel();

        if ($panel->auth()->check()) {
            return redirect()->to($panel->getProfileUrl() ?? $panel->getUrl());
        }

        return redirect()->to($panel->getLoginUrl() ?? $panel->getUrl());
    }
}
