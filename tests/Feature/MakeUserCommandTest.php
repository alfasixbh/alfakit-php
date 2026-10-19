<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Tests\Fixtures\Models\User;
use Illuminate\Support\Facades\Hash;

/*
 * `make:alfakit-user`: the console vouches for the address, so the user can sign in to a
 * panel with `->emailVerification()` right away.
 */

it('creates a verified user that can sign in', function (): void {
    $this->artisan('make:alfakit-user', ['--name' => 'Root', '--email' => 'root@example.com', '--password' => 'secret-pass', '--no-interaction' => true])
        ->assertSuccessful();

    $user = User::query()->where('email', 'root@example.com')->sole();

    expect($user->email_verified_at)->not->toBeNull()
        ->and(Hash::check('secret-pass', $user->password))->toBeTrue();
});
