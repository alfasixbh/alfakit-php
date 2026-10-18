<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Forms\Components\Repeater;
use AlfaSix\Alfakit\Forms\Components\TextInput;
use AlfaSix\Alfakit\Forms\Components\Toggle;
use AlfaSix\Alfakit\Schemas\Components\Utilities\Set;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Post;
use Illuminate\Validation\ValidationException;

function postWithComments(): Post
{
    $post = Post::query()->create(['title' => 'P', 'slug' => 'p']);
    $post->comments()->create(['author_name' => 'A', 'body' => 'first', 'sort' => 0]);
    $post->comments()->create(['author_name' => 'B', 'body' => 'second', 'sort' => 1]);

    return $post;
}

/**
 * @return array<string, array<string>>
 */
function validationErrors(AlfaSix\Alfakit\Schemas\Schema $schema): array
{
    try {
        $schema->validate();
    } catch (ValidationException $exception) {
        return $exception->errors();
    }

    return [];
}

function commentsRepeater(): Repeater
{
    return Repeater::make('comments')->relationship()->orderColumn('sort')->schema([
        TextInput::make('author_name')->required(),
        TextInput::make('body')->required(),
    ]);
}

it('rejects items added, removed or reordered on a locked relationship repeater', function (): void {
    $post = postWithComments();
    [$first, $second] = $post->comments()->orderBy('sort')->get()->map->only(['id', 'author_name', 'body'])->all();

    $schema = fn (Repeater $repeater) => schema([$repeater], record: $post, operation: 'edit')->fill();

    $added = $schema(commentsRepeater()->addable(false))->applyClientState(['comments' => [$first, $second, ['author_name' => 'C', 'body' => 'third']]]);
    $removed = $schema(commentsRepeater()->deletable(false))->applyClientState(['comments' => [$first]]);
    $dropped = $schema(commentsRepeater()->deletable(false))->applyClientState([]);
    $reordered = $schema(commentsRepeater()->reorderable(false))->applyClientState(['comments' => [$second, $first]]);
    $swapped = $schema(commentsRepeater()->addable(false))->applyClientState(['comments' => [$first, ['author_name' => 'C', 'body' => 'new']]]);
    $edited = $schema(commentsRepeater()->addable(false)->deletable(false)->reorderable(false))
        ->applyClientState(['comments' => [[...$first, 'body' => 'edited'], $second]]);

    expect(validationErrors($added)['comments'] ?? null)->toBe(["Items can't be added to comments."])
        ->and(validationErrors($removed)['comments'] ?? null)->toBe(["Items can't be removed from comments."])
        ->and(validationErrors($dropped)['comments'] ?? null)->toBe(["Items can't be removed from comments."])
        ->and(validationErrors($reordered)['comments'] ?? null)->toBe(["The items in comments can't be reordered."])
        ->and(validationErrors($swapped)['comments'] ?? null)->toBe(["Items can't be added to comments."])
        ->and(validationErrors($edited))->toBe([]);
});

it('limits a locked plain repeater to the server item count', function (): void {
    $make = fn (): Repeater => Repeater::make('items')->defaultItems(2)->schema([TextInput::make('sku')]);

    $added = schema([$make()->addable(false)])->fill()->applyClientState(['items' => [['sku' => 'a'], ['sku' => 'b'], ['sku' => 'c']]]);
    $removed = schema([$make()->deletable(false)])->fill()->applyClientState(['items' => [['sku' => 'a']]]);
    $kept = schema([$make()->addable(false)->deletable(false)])->fill()->applyClientState(['items' => [['sku' => 'a'], ['sku' => 'b']]]);

    expect(validationErrors($added))->toHaveKey('items')
        ->and(validationErrors($removed))->toHaveKey('items')
        ->and(validationErrors($kept))->toBe([]);
});

it('checks unique() and exists() inside a relationship repeater against the related table', function (): void {
    $post = postWithComments();
    [$first, $second] = $post->comments()->orderBy('sort')->get()->map->only(['id', 'author_name', 'body'])->all();

    $repeater = Repeater::make('comments')->relationship()->schema([
        TextInput::make('author_name')->unique(ignoreRecord: true),
        TextInput::make('body')->exists(column: 'body'),
    ]);

    // Unchanged items pass: each item ignores its own comment; `body` exists in `comments`.
    $unchanged = schema([$repeater], record: $post, operation: 'edit')->fill()->applyClientState(['comments' => [$first, $second]]);
    expect(validationErrors($unchanged))->toBe([]);

    // Reusing another comment's author name fails; a body that no comment has fails `exists`.
    $repeater = Repeater::make('comments')->relationship()->schema([
        TextInput::make('author_name')->unique(ignoreRecord: true),
        TextInput::make('body')->exists(column: 'body'),
    ]);
    $clash = schema([$repeater], record: $post, operation: 'edit')->fill()
        ->applyClientState(['comments' => [[...$first, 'author_name' => 'B'], [...$second, 'body' => 'P']]]);

    expect(array_keys(validationErrors($clash)))->toBe(['comments.0.author_name', 'comments.1.body']);
});

it('uses the related table for repeater rules before the parent record exists', function (): void {
    postWithComments();

    $repeater = Repeater::make('comments')->relationship()->schema([TextInput::make('author_name')->unique()]);
    $schema = schema([$repeater], model: Post::class)->fill()->applyClientState(['comments' => [['author_name' => 'A']]]);

    expect(validationErrors($schema))->toHaveKey('comments.0.author_name');
});

it('quotes in() values and resolves sibling references inside repeaters', function (): void {
    $repeater = Repeater::make('items')->schema([
        TextInput::make('size')->in(['Small, light', 'Large']),
        Toggle::make('has_note'),
        TextInput::make('note')->requiredIf('has_note', true),
        TextInput::make('confirm')->same('size'),
    ]);

    $valid = schema([$repeater])->fill()->applyClientState(['items' => [['size' => 'Small, light', 'has_note' => false, 'note' => null, 'confirm' => 'Small, light']]]);
    $invalid = schema([$repeater])->fill()->applyClientState(['items' => [['size' => 'Small', 'has_note' => true, 'note' => null, 'confirm' => 'Large']]]);

    expect(validationErrors($valid))->toBe([])
        ->and(array_keys(validationErrors($invalid)))->toBe(['items.0.size', 'items.0.note', 'items.0.confirm']);
});

it('passes the previous state as $old to afterStateUpdated', function (): void {
    $seen = [];
    $title = TextInput::make('title')->afterStateUpdated(function (mixed $state, mixed $old) use (&$seen): void {
        $seen[] = [$old, $state];
    });
    $slug = TextInput::make('slug');

    $schema = schema([$title, $slug])->fill(['title' => 'Before', 'slug' => null])->applyClientState(['title' => 'After', 'slug' => null]);
    $schema->callAfterStateUpdated('title', $schema->getServerStateAt('title'));

    (new Set($slug))('title', 'Again', shouldCallUpdatedHooks: true);

    expect($seen)->toBe([['Before', 'After'], ['After', 'Again']]);
});
