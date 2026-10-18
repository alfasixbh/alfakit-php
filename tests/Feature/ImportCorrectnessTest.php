<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Actions\Imports\Exceptions\RowImportFailedException;
use AlfaSix\Alfakit\Actions\Imports\Jobs\ImportCsv;
use AlfaSix\Alfakit\Actions\Imports\Models\FailedImportRow;
use AlfaSix\Alfakit\Actions\Imports\Models\Import;
use AlfaSix\Alfakit\Tests\Fixtures\ImportExport\TaggedPostImporter;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Post;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Tag;
use AlfaSix\Alfakit\Tests\Fixtures\Models\User;

beforeEach(function (): void {
    (require dirname(__DIR__, 2).'/database/migrations/0001_01_01_000100_create_alfakit_import_export_tables.php')->up();
});

function taggedImport(): Import
{
    return Import::query()->create(['importer' => TaggedPostImporter::class, 'file_name' => 'posts.csv', 'total_rows' => 0]);
}

function taggedImporter(array $options = []): TaggedPostImporter
{
    /** @var TaggedPostImporter */
    return taggedImport()->getImporter(['slug' => 'Slug', 'title' => 'Title', 'tags' => 'Tags'], $options);
}

function taggedPost(string $slug): Post
{
    $user = User::query()->firstOrCreate(['email' => 'ada@example.com'], ['name' => 'Ada', 'password' => 'x']);

    return Post::query()->create(['title' => ucfirst($slug), 'slug' => $slug, 'author_id' => $user->id]);
}

it('syncs a BelongsToMany column from a multi-value cell', function (): void {
    $post = taggedPost('hello');
    $php = Tag::query()->create(['name' => 'php']);
    $laravel = Tag::query()->create(['name' => 'laravel']);
    $old = Tag::query()->create(['name' => 'old']);
    $post->tags()->attach($old);

    taggedImporter()(['Slug' => 'hello', 'Title' => 'Hello', 'Tags' => 'php | laravel']);

    expect($post->tags()->orderBy('tags.id')->pluck('tags.id')->all())->toBe([$php->id, $laravel->id]);
});

it('syncs BelongsToMany on newly created records', function (): void {
    $php = Tag::query()->create(['name' => 'php']);

    taggedImporter(['createMissing' => true])(['Slug' => 'fresh', 'Title' => 'Fresh', 'Tags' => 'php']);

    expect(Post::query()->where('slug', 'fresh')->sole()->tags()->pluck('tags.id')->all())->toBe([$php->id]);
});

it('fails the row when a related value is unknown and writes nothing', function (): void {
    $post = taggedPost('hello');
    Tag::query()->create(['name' => 'php']);

    expect(fn () => taggedImporter()(['Slug' => 'hello', 'Title' => 'Changed', 'Tags' => 'php|nope']))
        ->toThrow(RowImportFailedException::class);

    expect($post->tags()->count())->toBe(0)
        ->and($post->fresh()?->title)->toBe('Hello');
});

it('skips rows whose resolveRecord() returns null instead of failing them', function (): void {
    taggedPost('known');
    $import = taggedImport();

    (new ImportCsv($import, [
        ['Slug' => 'known', 'Title' => 'Updated', 'Tags' => ''],
        ['Slug' => 'unknown', 'Title' => 'Ghost', 'Tags' => ''],
    ], ['slug' => 'Slug', 'title' => 'Title', 'tags' => 'Tags']))->handle();

    expect(FailedImportRow::query()->count())->toBe(0)
        ->and(Post::query()->where('slug', 'known')->value('title'))->toBe('Updated')
        ->and(Post::query()->where('slug', 'unknown')->exists())->toBeFalse();
});
