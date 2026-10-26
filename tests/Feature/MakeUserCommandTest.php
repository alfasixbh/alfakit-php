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

it('refuses an email address that already exists (W-S1-02)', function (): void {
    User::query()->create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'x']);

    $this->artisan('make:alfakit-user', ['--name' => 'Root', '--email' => 'admin@example.com', '--password' => 'secret-pass', '--no-interaction' => true])
        ->expectsOutputToContain('A user with the email address admin@example.com already exists.')
        ->assertFailed();

    expect(User::query()->where('email', 'admin@example.com')->count())->toBe(1);
});
