<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\AvatarProviders\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * Makes an avatar URL for users and tenants without their own (`HasAvatar` returning
 * null, no `avatar_url` attribute). Set with `Panel::defaultAvatarProvider()`.
 */
interface AvatarProvider
{
    public function get(Model|Authenticatable $record): string;
}
