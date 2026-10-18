<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Actions\BulkAction;
use AlfaSix\Alfakit\Actions\DeleteBulkAction;
use AlfaSix\Alfakit\Actions\DetachBulkAction;
use AlfaSix\Alfakit\Actions\ForceDeleteBulkAction;
use AlfaSix\Alfakit\Actions\RestoreBulkAction;
use AlfaSix\Alfakit\Tests\Fixtures\ActionsResources\ArticleResource\Pages\EditArticle;
use AlfaSix\Alfakit\Tests\Fixtures\ActionsResources\ArticleResource\RelationManagers\TagsRelationManager;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Post;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Tag;
use Illuminate\Support\Facades\DB;

/*
 * Regression: the built-in delete / force-delete / restore / detach bulk actions used to
 * type-hint `Model` for each record, so `fetchSelectedRecords(false)` (plain keys) broke them.
 */

function keysPosts(int $count = 3): array
{
    return collect(range(1, $count))
        ->map(fn (int $i): Post => Post::query()->create(['title' => "Keys {$i}", 'slug' => 'keys-'.$i.'-'.uniqid(), 'status' => 'draft']))
        ->all();
}

function runKeysAction(BulkAction $action, array $keys, ?Illuminate\Database\Eloquent\Builder $query = null): void
{
    $action->selectedRecordsQuery($query ?? Post::query()->whereKey($keys));
    $action->execute();
}

it('deletes the selected keys by query with fetchSelectedRecords(false)', function (): void {
    [$a, $b, $c] = keysPosts();

    $action = DeleteBulkAction::make()->authorize(true)->authorizeIndividualRecords(null)->fetchSelectedRecords(false);
    runKeysAction($action, [$a->id, $b->id]);

    expect($action->receivesSelectedKeys())->toBeTrue()
        ->and(Post::query()->pluck('id')->all())->toBe([$c->id])
        // Soft-deleting models are still soft-deleted.
        ->and(Post::withTrashed()->whereKey([$a->id, $b->id])->count())->toBe(2);
});

it('still authorises each record when deleting keys', function (): void {
    [$a, $b] = keysPosts(2);

    // Per-record authorisation needs the record, so it is loaded (lazily) to check it.
    $action = DeleteBulkAction::make()->authorize(true)
        ->authorizeIndividualRecords(fn (Post $record): bool => $record->id !== $b->id)
        ->fetchSelectedRecords(false);
    runKeysAction($action, [$a->id, $b->id]);

    expect(Post::query()->pluck('id')->all())->toBe([$b->id])
        ->and($action->getUnauthorizedRecordsCount())->toBe(1);
});

it('force-deletes and restores the selected keys by query', function (): void {
    [$a, $b, $c] = keysPosts();
    Post::query()->whereKey([$a->id, $b->id, $c->id])->delete();

    $restore = RestoreBulkAction::make()->authorize(true)->authorizeIndividualRecords(null)->fetchSelectedRecords(false);
    runKeysAction($restore, [$a->id], Post::onlyTrashed()->whereKey([$a->id]));

    expect(Post::query()->pluck('id')->all())->toBe([$a->id]);

    $force = ForceDeleteBulkAction::make()->authorize(true)->authorizeIndividualRecords(null)->fetchSelectedRecords(false);
    runKeysAction($force, [$b->id], Post::onlyTrashed()->whereKey([$b->id]));

    expect(Post::withTrashed()->pluck('id')->sort()->values()->all())->toBe([$a->id, $c->id]);
});

it('keeps acting on models when records are fetched', function (): void {
    [$a, $b] = keysPosts(2);

    $action = DeleteBulkAction::make()->authorize(true)->authorizeIndividualRecords(null);
    runKeysAction($action, [$a->id]);

    expect($action->receivesSelectedKeys())->toBeFalse()
        ->and(Post::query()->pluck('id')->all())->toBe([$b->id]);
});

it('detaches the selected keys with fetchSelectedRecords(false)', function (): void {
    [$post, $other] = keysPosts(2);
    [$x, $y] = [Tag::query()->create(['name' => 'X']), Tag::query()->create(['name' => 'Y'])];
    $post->tags()->attach([$x->id, $y->id]);
    $other->tags()->attach([$x->id]);

    $manager = TagsRelationManager::make($post, app(EditArticle::class));
    $action = DetachBulkAction::make()->authorize(true)->context($manager)->fetchSelectedRecords(false);
    $action->selectedRecordsQuery($post->tags()->getQuery()->whereKey([$x->id]));
    $action->execute();

    expect($post->tags()->pluck('tags.id')->all())->toBe([$y->id])
        ->and($other->tags()->pluck('tags.id')->all())->toBe([$x->id])
        ->and(DB::table('post_tag')->count())->toBe(2);
});
