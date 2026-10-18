<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\AvatarProviders;

use AlfaSix\Alfakit\AvatarProviders\Contracts\AvatarProvider;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * Avatars from ui-avatars.com: the user's or tenant's initials on a coloured square.
 * The browser loads the image from that service, which then sees the name; use
 * `InitialsAvatarProvider` to keep avatars on your own server.
 */
class UiAvatarsProvider implements AvatarProvider
{
    public function __construct(
        protected string $background = '09090b',
        protected string $color = 'FFFFFF',
    ) {}

    public function get(Model|Authenticatable $record): string
    {
        $name = alfakit()->getCurrentOrDefaultPanel()->getNameForDefaultAvatar($record);

        return 'https://ui-avatars.com/api/?'.http_build_query([
            'name' => $name,
            'color' => ltrim($this->color, '#'),
            'background' => ltrim($this->background, '#'),
        ], '', '&', PHP_QUERY_RFC3986);
    }
}
