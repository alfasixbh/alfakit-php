<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Auth\MultiFactor\Contracts;

use AlfaSix\Alfakit\Schemas\Components\Component;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * A second factor users can set up on their profile and must pass after their password
 * (`Panel::multiFactorAuthentication([...])`).
 */
interface MultiFactorAuthenticationProvider
{
    /** Identifier used in URLs and form state: `app`, `email`. */
    public function getId(): string;

    /** Name of the method on the challenge page and the profile page. */
    public function getLabel(): string;

    /** Whether the user model can use this method at all (implements its contract). */
    public function isSupportedBy(Authenticatable $user): bool;

    /** Whether the user has set this method up. */
    public function isEnabled(Authenticatable $user): bool;

    /**
     * A value that changes whenever the user's setup changes (e.g. a new secret), used to
     * invalidate trusted devices.
     */
    public function getFingerprint(Authenticatable $user): string;

    /** Explanation shown above the code field on the challenge page. */
    public function getChallengeDescription(Authenticatable $user): string;

    /** Runs when the challenge page shows this method (e.g. emails a code). */
    public function beforeChallenge(Authenticatable $user): void;

    /** Whether the code the user typed on the challenge page is correct. */
    public function verifyChallengeCode(Authenticatable $user, string $code): bool;

    /**
     * Components (usually one Section with set-up / remove actions) for the profile page.
     *
     * @return array<Component>
     */
    public function getManagementSchemaComponents(Authenticatable $user): array;
}
