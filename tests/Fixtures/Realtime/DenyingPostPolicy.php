<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\Realtime;

use AlfaSix\Alfakit\Tests\Fixtures\Models\Post;
use AlfaSix\Alfakit\Tests\Fixtures\Models\User;

class DenyingPostPolicy
{
    public function viewAny(User $user): bool
    {
        return false;
    }

    public function view(User $user, Post $post): bool
    {
        return false;
    }

    public function update(User $user, Post $post): bool
    {
        return false;
    }
}
