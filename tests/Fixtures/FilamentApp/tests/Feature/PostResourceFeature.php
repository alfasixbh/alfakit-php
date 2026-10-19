<?php

declare(strict_types=1);

use App\Filament\Resources\PostResource\Pages\ListPosts;
use App\Models\Post;

use function Pest\Livewire\livewire;

it('lists posts', function () {
    $posts = Post::factory()->count(3)->create();

    livewire(ListPosts::class)
        ->assertCanSeeTableRecords($posts);
});
