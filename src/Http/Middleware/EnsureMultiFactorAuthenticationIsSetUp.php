<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Http\Middleware;

use AlfaSix\Alfakit\Auth\Impersonation\Impersonation;
use AlfaSix\Alfakit\Auth\MultiFactor\MultiFactorAuthentication;
use AlfaSix\Alfakit\Support\SchemaContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * With `multiFactorAuthentication(..., isRequired: true)`, users who haven't set up a
 * second factor can only use the profile page (where they set one up) until they do.
 */
class EnsureMultiFactorAuthenticationIsSetUp
{
    public function handle(Request $request, Closure $next, string $panelId): Response
    {
        $panel = alfakit()->getPanel($panelId);
        $user = $panel->auth()->user();

        if ($user === null || ! $panel->isMultiFactorAuthenticationRequired() || Impersonation::isImpersonating($panel)) {
            return $next($request);
        }

        if (MultiFactorAuthentication::getEnabledProviders($panel, $user) !== []) {
            return $next($request);
        }

        abort_unless(
            MultiFactorAuthentication::supportsAnyProvider($panel, $user),
            403,
            'Multi-factor authentication is required, but the user model implements none of the panel\'s methods (HasAppAuthentication, HasEmailAuthentication).',
        );

        $profileUrl = $panel->getProfileUrl();

        abort_if($profileUrl === null, 403, 'Required multi-factor authentication needs the profile page: enable it with ->profile().');

        if ($this->isProfileRequest($request, $panelId)) {
            return $next($request);
        }

        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            abort(403, __('alfakit::multi-factor.required'));
        }

        session()->flash('alfakit.mfa-required', true);

        return redirect()->to($profileUrl);
    }

    /**
     * The profile page itself, and internal requests (set-up actions) made from it.
     */
    protected function isProfileRequest(Request $request, string $panelId): bool
    {
        $panel = alfakit()->getPanel($panelId);

        if ($request->routeIs($panel->generateRouteName('auth.profile'), $panel->generateRouteName('auth.multi-factor.recovery-codes'))) {
            return true;
        }

        $token = $request->input('token') ?? $request->query('token');

        if (! is_string($token) || $token === '') {
            return false;
        }

        try {
            $context = SchemaContext::fromToken($token);
        } catch (Throwable) {
            return false;
        }

        $profilePage = $panel->getProfilePage();

        return $context->panel === $panelId && $profilePage !== null && $context->page === $profilePage;
    }
}
