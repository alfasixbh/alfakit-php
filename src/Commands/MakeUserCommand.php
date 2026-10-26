<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Console\Attribute\AsCommand;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

#[AsCommand(name: 'make:alfakit-user', description: 'Create a user that can sign in to a panel')]
class MakeUserCommand extends Command
{
    protected $signature = 'make:alfakit-user {--name=} {--email=} {--password=} {--panel=}';

    public function handle(): int
    {
        $panel = alfakit()->getPanel($this->option('panel'));
        $provider = config('auth.guards.'.$panel->getAuthGuard().'.provider');
        $model = config("auth.providers.{$provider}.model");

        if (! is_string($model) || ! class_exists($model)) {
            $this->components->error("Could not resolve the user model for guard [{$panel->getAuthGuard()}].");

            return self::FAILURE;
        }

        $name = $this->option('name') ?? text('Name', required: true);
        $exists = static fn (string $value): bool => $model::query()->where('email', $value)->exists();
        $email = $this->option('email') ?? text('Email address', required: true, validate: fn (string $value): ?string => match (true) {
            ! filter_var($value, FILTER_VALIDATE_EMAIL) => 'Invalid email address.',
            $exists($value) => 'A user with this email address already exists.',
            default => null,
        });

        // `--email`, or demo data that already has the address (W-S1-02).
        if ($exists((string) $email)) {
            $this->components->error("A user with the email address {$email} already exists.");

            return self::FAILURE;
        }

        $passwordValue = $this->option('password') ?? password('Password', required: true);

        $user = $model::query()->create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make($passwordValue),
        ]);

        // Created from the console: the address is trusted, so a panel with
        // `->emailVerification()` doesn't send this user to the verification prompt.
        if (Schema::connection($user->getConnectionName())->hasColumn($user->getTable(), 'email_verified_at') && $user->getAttribute('email_verified_at') === null) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        $this->components->info("User {$user->getAttribute('email')} created. Sign in at ".($panel->getLoginUrl() ?? url($panel->getPath())));

        return self::SUCCESS;
    }
}
