<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Actions\ActionGroup;
use AlfaSix\Alfakit\Actions\DeleteAction;
use AlfaSix\Alfakit\Actions\ReplicateAction;
use AlfaSix\Alfakit\Resources\Pages\ListRecords;
use AlfaSix\Alfakit\Tables\Table;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Post;
use AlfaSix\Alfakit\Tests\Fixtures\Panel\Resources\PostResource;
use Illuminate\Database\Eloquent\Model;

/*
 * Record actions in an `ActionGroup` name the record through the resource, like the
 * ungrouped ones ("Delete Post #7", not a guessed `title` column).
 */

class GroupedActionRecordTitleResource extends PostResource
{
    public static function getRecordTitle(?Model $record): ?string
    {
        return $record === null ? null : 'Post #'.$record->getKey();
    }
}

class GroupedActionRecordTitleList extends ListRecords
{
    protected static string $resource = GroupedActionRecordTitleResource::class;
}

it('titles the record the same way in and out of action groups', function (): void {
    $post = new Post(['title' => 'Hello']);
    $post->id = 7;

    $table = Table::make(app(GroupedActionRecordTitleList::class))
        ->recordActions([
            ReplicateAction::make('replicateOut'),
            ActionGroup::make([
                ReplicateAction::make(),
                ActionGroup::make([DeleteAction::make()]),
            ]),
        ]);

    [$out, $group] = $table->getRecordActions();
    $group->record($post);
    $out->record($post);
    [$replicate, $delete] = $group->getFlatActions();

    expect($out->getModalHeading())->toContain('Post #7')
        ->and($replicate->getModalHeading())->toBe($out->getModalHeading())
        ->and($delete->getModalHeading())->toContain('Post #7');
});

class UntitledRecordTitleResource extends PostResource
{
    protected static ?string $recordTitleAttribute = null;
}

class UntitledRecordTitleList extends ListRecords
{
    protected static string $resource = UntitledRecordTitleResource::class;
}

it('names records by the model label without a title attribute, in pages and table actions', function (): void {
    $post = new Post(['title' => 'Hello']);
    $post->id = 7;

    $table = Table::make(app(UntitledRecordTitleList::class))
        ->recordActions([DeleteAction::make(), ActionGroup::make([ReplicateAction::make()])]);

    [$delete, $group] = $table->getRecordActions();
    $delete->record($post);
    $group->record($post);
    [$replicate] = $group->getFlatActions();

    // Not the key ("Delete 7") and not a guessed `title` column ("Delete Hello").
    expect(UntitledRecordTitleResource::getRecordTitle($post))->toBe('Post')
        ->and(UntitledRecordTitleResource::hasRecordTitle())->toBeFalse()
        ->and(PostResource::hasRecordTitle())->toBeTrue()
        ->and(GroupedActionRecordTitleResource::hasRecordTitle())->toBeTrue()
        ->and($delete->getModalHeading())->toBe(__('alfakit::actions.delete.modal.heading', ['label' => 'Post']))
        ->and($replicate->getModalHeading())->toBe(__('alfakit::actions.replicate.modal.heading', ['label' => 'Post']));
});

it('titles a record from a rich text attribute by its text, not its HTML', function (): void {
    expect(PostResource::getRecordTitle(new Post(['title' => '<p>Hello &amp; <strong>world</strong></p><p>Again</p>'])))->toBe('Hello & world Again')
        // Plain text with a "<" is left alone.
        ->and(PostResource::getRecordTitle(new Post(['title' => 'a < b'])))->toBe('a < b');
});
