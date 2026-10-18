<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Support\RecordState;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Post;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Tag;
use AlfaSix\Alfakit\Tests\Fixtures\Models\User;

it('reads scalar, belongs-to and many-relationship paths', function (): void {
    $author = User::query()->create(['name' => 'Ada', 'email' => 'ada@example.com', 'password' => 'x']);
    $post = Post::query()->create(['title' => 'T', 'slug' => 't', 'author_id' => $author->id, 'meta' => ['color' => 'red']]);
    $post->tags()->attach([Tag::query()->create(['name' => 'php'])->id, Tag::query()->create(['name' => 'js'])->id]);
    $post->comments()->create(['author_name' => 'Bob', 'body' => 'hi']);

    $post = $post->fresh();

    expect(RecordState::get($post, 'title'))->toBe('T')
        ->and(RecordState::get($post, 'author.name'))->toBe('Ada')
        ->and(RecordState::get($post, 'meta.color'))->toBe('red')
        ->and(RecordState::get($post, 'tags.name'))->toBe(['php', 'js'])
        ->and(RecordState::get($post, 'comments.author_name'))->toBe(['Bob'])
        ->and(RecordState::get($post, 'category.name'))->toBeNull()
        ->and(RecordState::get($post, ''))->toBe($post);
});
