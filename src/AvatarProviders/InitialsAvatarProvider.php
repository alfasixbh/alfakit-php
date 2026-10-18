<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\AvatarProviders;

use AlfaSix\Alfakit\AvatarProviders\Contracts\AvatarProvider;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Avatars drawn on the server: the initials of the user or tenant on a colour derived
 * from the name, as an SVG `data:` URL. Nothing is loaded from another service.
 */
class InitialsAvatarProvider implements AvatarProvider
{
    /**
     * @param  string|null  $background  a CSS colour, or null for a colour derived from the name
     */
    public function __construct(
        protected ?string $background = null,
        protected string $color = '#ffffff',
    ) {}

    public function get(Model|Authenticatable $record): string
    {
        $name = alfakit()->getCurrentOrDefaultPanel()->getNameForDefaultAvatar($record);
        $initials = self::initials($name);
        $background = $this->background ?? 'hsl('.(crc32($name) % 360).' 45% 42%)';

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" viewBox="0 0 64 64">'
            .'<rect width="64" height="64" fill="'.e($background).'"/>'
            .'<text x="50%" y="50%" dy=".35em" text-anchor="middle" fill="'.e($this->color).'" '
            .'font-family="system-ui, -apple-system, Segoe UI, sans-serif" font-size="26" font-weight="600">'
            .e($initials).'</text></svg>';

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }

    /**
     * Up to two initials: first letters of the first and last words.
     */
    public static function initials(string $name): string
    {
        $words = preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if ($words === []) {
            return '?';
        }

        $first = mb_substr($words[0], 0, 1);
        $last = count($words) > 1 ? mb_substr($words[count($words) - 1], 0, 1) : '';

        return Str::upper($first.$last);
    }
}
