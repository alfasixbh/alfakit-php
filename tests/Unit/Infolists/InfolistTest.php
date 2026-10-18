<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Infolists\Components\IconEntry;
use AlfaSix\Alfakit\Infolists\Components\KeyValueEntry;
use AlfaSix\Alfakit\Infolists\Components\RepeatableEntry;
use AlfaSix\Alfakit\Infolists\Components\TextEntry;
use AlfaSix\Alfakit\Schemas\Components\Section;
use AlfaSix\Alfakit\Schemas\Schema;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Post;
use AlfaSix\Alfakit\Tests\Fixtures\Models\User;

it('serialises entries with formatted state from the record', function (): void {
    $author = User::query()->create(['name' => 'Ada', 'email' => 'ada@example.com', 'password' => 'x']);
    $post = Post::query()->create([
        'title' => 'Hello world this is long',
        'slug' => 'hello',
        'author_id' => $author->id,
        'is_published' => true,
        'published_at' => '2026-10-08 10:00:00',
        'meta' => ['color' => 'red'],
        'rating' => 1234.5,
    ]);
    $post->comments()->create(['author_name' => 'Bob', 'body' => 'Nice']);

    $schema = Schema::make()->record($post)->components([
        Section::make('Post')->schema([
            TextEntry::make('title')->limit(11)->badge()->color('info'),
            TextEntry::make('author.name')->label('Written by'),
            TextEntry::make('published_at')->date('Y-m-d'),
            TextEntry::make('rating')->money('USD'),
            TextEntry::make('missing')->placeholder('—'),
            IconEntry::make('is_published')->boolean(),
            KeyValueEntry::make('meta'),
            RepeatableEntry::make('comments')->schema([TextEntry::make('author_name'), TextEntry::make('body')]),
        ]),
    ])->toSchema();

    $entries = collect($schema[0]['schema'])->keyBy('name');

    expect($entries['title'])->toMatchArray(['type' => 'TextEntry', 'state' => 'Hello world…', 'badge' => true, 'color' => 'info'])
        ->and($entries['author.name'])->toMatchArray(['label' => 'Written by', 'state' => 'Ada'])
        ->and($entries['published_at']['state'])->toBe('2026-10-08')
        ->and($entries['rating']['state'])->toBe('$1,234.00')
        ->and($entries['missing'])->toMatchArray(['placeholder' => '—'])->not->toHaveKey('state')
        ->and($entries['is_published'])->toMatchArray(['state' => true, 'icon' => 'circle-check', 'color' => 'success'])
        ->and($entries['meta']['state'])->toBe(['color' => 'red'])
        ->and($entries['comments']['items'][0][0]['state'])->toBe('Bob')
        ->and($entries['comments']['items'][0][1]['state'])->toBe('Nice');
});

it('resolves state() closures against each item of a repeatable entry', function (): void {
    $author = User::query()->create(['name' => 'Ada', 'email' => 'ada-items@example.com', 'password' => 'x']);
    $post = Post::query()->create(['title' => 'Items', 'slug' => 'items', 'author_id' => $author->id]);
    $post->comments()->create(['author_name' => 'Bob', 'body' => 'Nice']);
    $post->comments()->create(['author_name' => 'Cy', 'body' => 'Great']);

    $schema = Schema::make()->record($post)->components([
        RepeatableEntry::make('comments')->schema([
            TextEntry::make('label')->state(fn (mixed $record): string => $record->author_name.' says'),
            TextEntry::make('constant')->state('Fixed'),
        ]),
    ])->toSchema();

    expect($schema[0]['items'][0][0]['state'])->toBe('Bob says')
        ->and($schema[0]['items'][1][0]['state'])->toBe('Cy says')
        ->and($schema[0]['items'][1][1]['state'])->toBe('Fixed');
});
