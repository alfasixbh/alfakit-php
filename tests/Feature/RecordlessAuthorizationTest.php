<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Actions\DeleteAction;
use AlfaSix\Alfakit\Actions\EditAction;
use AlfaSix\Alfakit\Actions\ViewAction;
use AlfaSix\Alfakit\Support\Authorization;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Post;
use AlfaSix\Alfakit\Tests\Fixtures\Models\User;
use AlfaSix\Alfakit\Tests\Fixtures\Panel\Resources\PostResource;
use Illuminate\Support\Facades\Gate;

/** Record abilities that need the post, as most application policies declare them. */
class RecordlessTestPostPolicy
{
    public static bool $viewAny = true;

    /** @var list<string> */
    public static array $calls = [];

    public function viewAny(User $user): bool
    {
        self::$calls[] = 'viewAny';

        return self::$viewAny;
    }

    public function view(User $user, Post $post): bool
    {
        self::$calls[] = 'view';

        return true;
    }

    public function update(User $user, Post $post): bool
    {
        self::$calls[] = 'update';

        return true;
    }

    public function delete(User $user, Post $post): bool
    {
        self::$calls[] = 'delete';

        return true;
    }

    /** Optional model: may be asked about the class. */
    public function restore(User $user, ?Post $post = null): bool
    {
        self::$calls[] = 'restore';

        return true;
    }
}

beforeEach(function (): void {
    Gate::policy(Post::class, RecordlessTestPostPolicy::class);
    RecordlessTestPostPolicy::$viewAny = true;
    RecordlessTestPostPolicy::$calls = [];
    $this->actingAs(User::query()->create(['name' => 'Ada', 'email' => 'ada@example.com', 'password' => 'password']));
});

it('denies record abilities without a record instead of calling the policy without its model', function (): void {
    expect(PostResource::can('view'))->toBeFalse()
        ->and(PostResource::can('update'))->toBeFalse()
        ->and(Authorization::allows('delete', Post::class))->toBeFalse()
        ->and(RecordlessTestPostPolicy::$calls)->toBe([]);

    // Methods that accept no model are still asked.
    expect(PostResource::can('restore'))->toBeTrue()
        ->and(Authorization::allows('restore', Post::class))->toBeTrue()
        ->and(PostResource::can('viewAny'))->toBeTrue()
        ->and(RecordlessTestPostPolicy::$calls)->toBe(['restore', 'restore', 'viewAny']);

    $post = Post::query()->create(['title' => 'Hello', 'slug' => 'hello']);

    expect(PostResource::can('view', $post))->toBeTrue()
        ->and(Authorization::allows('update', $post))->toBeTrue();
});

it('tells which policy methods need a record', function (): void {
    expect(Authorization::requiresRecord(RecordlessTestPostPolicy::class, 'view'))->toBeTrue()
        ->and(Authorization::requiresRecord(new RecordlessTestPostPolicy, 'update'))->toBeTrue()
        ->and(Authorization::requiresRecord(RecordlessTestPostPolicy::class, 'restore'))->toBeFalse()
        ->and(Authorization::requiresRecord(RecordlessTestPostPolicy::class, 'viewAny'))->toBeFalse()
        ->and(Authorization::requiresRecord(RecordlessTestPostPolicy::class, 'missing'))->toBeFalse();
});

it('offers a view action without a record to users who may view any record', function (): void {
    $view = ViewAction::make()->model(Post::class);

    expect($view->isVisible())->toBeTrue()
        ->and(RecordlessTestPostPolicy::$calls)->toBe(['viewAny']);

    RecordlessTestPostPolicy::$viewAny = false;

    expect(ViewAction::make()->model(Post::class)->isVisible())->toBeFalse();

    // With its record, the record's `view` decides.
    $post = Post::query()->create(['title' => 'Hello', 'slug' => 'hello']);
    RecordlessTestPostPolicy::$calls = [];

    expect(ViewAction::make()->model(Post::class)->record($post)->isVisible())->toBeTrue()
        ->and(RecordlessTestPostPolicy::$calls)->toBe(['view']);
});

it('hides edit and delete actions that have no record, without errors', function (): void {
    expect(EditAction::make()->model(Post::class)->isVisible())->toBeFalse()
        ->and(DeleteAction::make()->model(Post::class)->isVisible())->toBeFalse()
        ->and(RecordlessTestPostPolicy::$calls)->toBe([]);
});
