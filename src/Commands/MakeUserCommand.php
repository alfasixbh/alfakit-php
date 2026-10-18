<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
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
        $email = $this->option('email') ?? text('Email address', required: true, validate: fn (string $value): ?string => filter_var($value, FILTER_VALIDATE_EMAIL) ? null : 'Invalid email address.');
        $passwordValue = $this->option('password') ?? password('Password', required: true);

        $user = $model::query()->create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make($passwordValue),
        ]);

        $this->components->info("User {$user->getAttribute('email')} created. Sign in at ".($panel->getLoginUrl() ?? url($panel->getPath())));

        return self::SUCCESS;
    }
}
