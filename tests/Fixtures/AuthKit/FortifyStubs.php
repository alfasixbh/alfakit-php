<?php

declare(strict_types=1);

// Minimal stand-ins for the Laravel Fortify classes the auth kit's Fortify bridge talks to
// (Fortify is not a dependency of the package). Only declared when Fortify is absent.

namespace Laravel\Fortify {
    if (! class_exists(Fortify::class)) {
        class Fortify
        {
            /** @var callable|null */
            public static $authenticateUsingCallback;

            public static function currentEncrypter(): \Illuminate\Contracts\Encryption\Encrypter
            {
                return app('encrypter');
            }
        }
    }
}

namespace Laravel\Fortify\Contracts {
    if (! interface_exists(CreatesNewUsers::class)) {
        interface CreatesNewUsers
        {
            /** @param  array<string, mixed>  $input */
            public function create(array $input): mixed;
        }

        interface ResetsUserPasswords
        {
            /** @param  array<string, mixed>  $input */
            public function reset(mixed $user, array $input): void;
        }

        interface UpdatesUserPasswords
        {
            /** @param  array<string, mixed>  $input */
            public function update(mixed $user, array $input): void;
        }

        interface UpdatesUserProfileInformation
        {
            /** @param  array<string, mixed>  $input */
            public function update(mixed $user, array $input): void;
        }

        interface TwoFactorAuthenticationProvider
        {
            public function verify(string $secret, string $code): bool;
        }
    }
}
