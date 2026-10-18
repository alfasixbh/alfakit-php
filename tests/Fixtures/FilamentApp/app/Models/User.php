<?php

declare(strict_types=1);

namespace App\Models;

use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;

class User extends \AlfaSix\Alfakit\Tests\Fixtures\Models\User implements FilamentUser
{
    protected $table = 'users';

    public function canAccessPanel(Panel $panel): bool
    {
        return str_ends_with((string) $this->email, '@acme.test');
    }
}
