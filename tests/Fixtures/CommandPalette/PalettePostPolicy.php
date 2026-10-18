<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\CommandPalette;

use AlfaSix\Alfakit\Tests\Fixtures\Models\Post;
use AlfaSix\Alfakit\Tests\Fixtures\Models\User;

/** Unpublished posts are private to their author; only authors may update or create. */
class PalettePostPolicy
{
    public static bool $canCreate = true;

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Post $post): bool
    {
        return $post->is_published || $post->author_id === $user->getKey();
    }

    public function update(User $user, Post $post): bool
    {
        return $post->author_id === $user->getKey();
    }

    public function create(User $user): bool
    {
        return self::$canCreate;
    }
}
