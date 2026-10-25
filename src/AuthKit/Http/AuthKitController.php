<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\AuthKit\Http;

use AlfaSix\Alfakit\Auth\MultiFactor\MultiFactorAuthentication;
use AlfaSix\Alfakit\Auth\RegistrationInvitations;
use AlfaSix\Alfakit\AuthKit\AuthKitPanel;
use AlfaSix\Alfakit\AuthKit\Pages\TwoFactorChallenge;
use AlfaSix\Alfakit\Notifications\Notification;
use Illuminate\Auth\Events\Verified;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The auth kit's pages: each renders one of the app's two Inertia pages (`inertiaPages()`) with
 * the page's standalone component (`component`), or the account sections (`sections`),
 * plus `authKit` (the page key, its title and a few URLs).
 */
class AuthKitController
{
    public function login(): Response
    {
        return $this->guest('login');
    }

    public function register(Request $request): Response|RedirectResponse
    {
        $panel = $this->panel();

        // Invitation-only: a signed invitation link (or one opened earlier in the session).
        if ($panel->hasInvitationOnlyRegistration() && ! RegistrationInvitations::accept($panel, $request)) {
            Notification::make()->warning()->title(__('alfakit::auth.invitation.required'))->send();

            return redirect()->to($panel->getLoginUrl() ?? $panel->getUrl());
        }

        return $this->guest('register');
    }

    public function forgotPassword(): Response
    {
        return $this->guest('forgot-password');
    }

    public function resetPassword(Request $request, string $token): Response
    {
        $email = $request->query('email');

        return $this->guest('reset-password', ['token' => $token, 'email' => is_string($email) ? $email : null]);
    }

    public function twoFactorChallenge(Request $request, ?string $method = null): Response|RedirectResponse
    {
        $panel = $this->panel();

        if (MultiFactorAuthentication::getChallengedUser($panel) === null) {
            return redirect()->to($panel->getLoginUrl() ?? $panel->getUrl());
        }

        $class = $panel->getComponent('two-factor-challenge');
        $component = $class::make(['method' => $method]);

        // Only opening the page sends an emailed code; submitting it doesn't.
        if ($component instanceof TwoFactorChallenge) {
            $component->beforeChallenge();
        }

        return $this->render($panel->getGuestPage(), 'two-factor-challenge', ['component' => $component], $component->getTitle());
    }

    public function verifyEmail(): Response|RedirectResponse
    {
        $panel = $this->panel();
        $user = $panel->auth()->user();

        if (! $user instanceof MustVerifyEmail || $user->hasVerifiedEmail()) {
            return redirect()->intended($panel->getUrl());
        }

        return $this->guest('verify-email');
    }

    public function verify(Request $request, string $id, string $hash): RedirectResponse
    {
        $panel = $this->panel();
        $user = $panel->auth()->user();

        abort_unless($user instanceof MustVerifyEmail, 403);
        abort_unless((string) $user->getAuthIdentifier() === $id, 403);
        abort_unless(hash_equals($hash, sha1($user->getEmailForVerification())), 403);

        if (! $user->hasVerifiedEmail() && $user->markEmailAsVerified()) {
            event(new Verified($user));
        }

        Notification::make()->success()->title(__('alfakit::auth.email_verification.verified'))->send();

        return redirect()->intended($panel->getUrl());
    }

    /**
     * Fortify's password confirmation page (`password.confirm`), which Fortify only registers
     * with its views on; its form posts to Fortify's `password.confirm.store`.
     */
    public function confirmPassword(): mixed
    {
        return app(AuthKitPanel::FORTIFY_CONFIRM_PASSWORD_VIEW);
    }

    public function account(): Response
    {
        $panel = $this->panel();

        $sections = array_filter([
            'profile' => $panel->getComponent('profile')::make(),
            'password' => $panel->getComponent('password')::make(),
            'twoFactor' => $panel->hasMultiFactorAuthentication() ? $panel->getComponent('two-factor')::make() : null,
            'sessions' => $panel->hasSessions() ? $panel->getComponent('sessions')::make() : null,
            'deleteAccount' => $panel->hasAccountDeletion() ? $panel->getComponent('delete-account')::make() : null,
        ]);

        return $this->render($panel->getAccountPage(), 'account', ['sections' => $sections], __('alfakit::auth.account.title'));
    }

    public function logout(Request $request): RedirectResponse
    {
        $panel = $this->panel();
        $guard = $panel->auth();

        if (method_exists($guard, 'logout')) {
            $guard->logout();
        }

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->to(url('/'));
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    protected function guest(string $key, array $parameters = []): Response
    {
        $panel = $this->panel();
        $component = $panel->getComponent($key)::make($parameters);

        return $this->render($panel->getGuestPage(), $key, ['component' => $component], $component->getTitle());
    }

    /**
     * @param  array<string, mixed>  $props
     */
    protected function render(string $page, string $key, array $props, string $title): Response
    {
        $panel = $this->panel();

        return Inertia::render($page, [
            ...$props,
            'authKit' => [
                'page' => $key,
                'title' => $title,
                'homeUrl' => $panel->getUrl(),
                'loginUrl' => $panel->getLoginUrl(),
                'accountUrl' => $panel->getProfileUrl(),
                'logoutUrl' => $panel->getLogoutUrl(),
            ],
        ]);
    }

    protected function panel(): AuthKitPanel
    {
        return alfakit()->getAuthKitPanel();
    }
}
