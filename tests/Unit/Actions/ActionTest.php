<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Actions\Action;
use AlfaSix\Alfakit\Actions\ActionGroup;
use AlfaSix\Alfakit\Actions\BulkAction;
use AlfaSix\Alfakit\Actions\DeleteAction;
use AlfaSix\Alfakit\Actions\EditAction;
use AlfaSix\Alfakit\Actions\Exceptions\Halt;
use AlfaSix\Alfakit\Forms\Components\TextInput;
use AlfaSix\Alfakit\Notifications\Notification;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Post;
use Illuminate\Validation\ValidationException;

it('serialises a basic action', function (): void {
    $action = Action::make('publish')->icon('send')->color('success')->requiresConfirmation()->keyBindings(['mod+p']);

    expect($action->toSchema())->toMatchArray([
        'type' => 'Action',
        'name' => 'publish',
        'label' => 'Publish',
        'icon' => 'send',
        'color' => 'success',
        'hasModal' => true,
        'keyBindings' => ['mod+p'],
    ])->not->toHaveKey('hasAction');

    expect($action->toModalSchema())->toMatchArray([
        'heading' => 'Publish',
        'description' => 'Are you sure you would like to do this?',
        'submitLabel' => 'Confirm',
        'requiresConfirmation' => true,
        'width' => 'sm',
    ]);
});

it('executes an action with a validated form and injects data', function (): void {
    $captured = null;

    $action = Action::make('rename')
        ->form([TextInput::make('title')->required()->maxLength(5)])
        ->action(function (array $data, Action $action) use (&$captured): string {
            $captured = $data;

            return 'ok';
        });

    expect(fn () => $action->execute(['title' => 'way too long']))->toThrow(ValidationException::class);

    expect($action->execute(['title' => 'Hi']))->toBe('ok')
        ->and($captured)->toBe(['title' => 'Hi'])
        ->and($action->toModalSchema()['form']['components'][0]['name'])->toBe('title')
        ->and($action->toModalSchema()['width'])->toBe('2xl');
});

it('fills the modal form from the record and injects it by type', function (): void {
    $post = Post::query()->create(['title' => 'Hello', 'slug' => 'hello']);

    $action = Action::make('edit')->record($post)->form([TextInput::make('title')])
        ->action(fn (Post $record, array $data) => $record->update($data));

    expect($action->toModalSchema()['form']['state'])->toBe(['title' => 'Hello'])
        ->and($action->toModalSchema()['record'])->toBe($post->id);

    $action->execute(['title' => 'Changed']);

    expect($post->fresh()->title)->toBe('Changed');
});

it('hides unauthorized actions and refuses to execute them', function (): void {
    $action = Action::make('secret')->authorize(false)->action(fn () => 'ran');

    expect($action->isVisible())->toBeFalse()
        ->and(fn () => $action->execute())->toThrow(Symfony\Component\HttpKernel\Exception\HttpException::class);
});

it('runs lifecycle hooks and supports halting', function (): void {
    $log = [];

    $action = Action::make('x')
        ->before(function () use (&$log): void {
            $log[] = 'before';
        })
        ->action(function () use (&$log): void {
            $log[] = 'action';
        })
        ->after(function () use (&$log): void {
            $log[] = 'after';
        });

    $action->execute();

    expect($log)->toBe(['before', 'action', 'after']);

    $halting = Action::make('y')->before(fn (Action $action) => $action->halt())->action(fn () => throw new RuntimeException('should not run'));

    expect(fn () => $halting->execute())->toThrow(Halt::class);
});

it('groups actions and flattens them', function (): void {
    $group = ActionGroup::make([Action::make('a'), ActionGroup::make([Action::make('b')->hidden()]), Action::make('c')]);

    expect(array_map(fn (Action $a) => $a->getName(), $group->getFlatActions()))->toBe(['a', 'b', 'c'])
        ->and(array_column($group->toSchema()['actions'], 'name'))->toBe(['a', 'c'])
        ->and($group->toSchema()['icon'])->toBe('ellipsis-vertical');
});

it('runs bulk actions against the selected records', function (): void {
    $posts = collect(['a', 'b'])->map(fn ($s) => Post::query()->create(['title' => $s, 'slug' => $s]));

    BulkAction::make('publish')
        ->records(Post::query()->whereKey($posts->pluck('id'))->get())
        ->action(fn ($records) => $records->each->update(['is_published' => true]))
        ->execute();

    expect(Post::query()->where('is_published', true)->count())->toBe(2);
});

it('flashes notifications through Inertia and serialises them', function (): void {
    session()->start();

    Notification::make()->title('Saved')->body('All good')->success()->seconds(3)->send();
    Notification::make()->title('Oops')->danger()->persistent()->send();

    $flashed = Notification::getFlashed();

    expect($flashed)->toHaveCount(2)
        ->and($flashed[0])->toMatchArray(['title' => 'Saved', 'body' => 'All good', 'status' => 'success', 'icon' => 'circle-check', 'duration' => 3000])
        ->and($flashed[1]['duration'])->toBe('persistent');
});

it('prebuilt edit and delete actions work without a resource', function (): void {
    session()->start();
    $post = Post::query()->create(['title' => 'Old', 'slug' => 'old']);

    $edit = EditAction::make()->record($post)->form([TextInput::make('title')->required()]);

    expect($edit->toSchema()['label'])->toBe('Edit')
        ->and($edit->toModalSchema()['heading'])->toBe('Edit Old');

    $edit->execute(['title' => 'New']);

    expect($post->fresh()->title)->toBe('New')
        ->and(Notification::getFlashed()[0]['title'])->toBe('Saved');

    DeleteAction::make()->record($post)->execute();

    expect($post->fresh()->trashed())->toBeTrue()
        ->and(DeleteAction::make()->record($post->fresh())->isVisible())->toBeFalse();
});

it('sends the success notification of a custom action', function (): void {
    session()->start();

    Action::make('archive')->action(fn () => null)->successNotificationTitle('Archived')->execute();

    expect(Notification::getFlashed())->toHaveCount(1)
        ->and(Notification::getFlashed()[0])->toMatchArray(['title' => 'Archived', 'status' => 'success']);
});

it('does not add the success notification when the handler sent one or none is configured', function (): void {
    session()->start();

    Action::make('archive')
        ->action(fn () => Notification::make()->title('Custom')->send())
        ->successNotificationTitle('Archived')
        ->execute();
    Action::make('plain')->action(fn () => null)->execute();

    expect(array_column(Notification::getFlashed(), 'title'))->toBe(['Custom']);
});

it('sends a prebuilt action success notification only once', function (): void {
    session()->start();
    $post = Post::query()->create(['title' => 'Once', 'slug' => 'once']);

    DeleteAction::make()->record($post)->execute();

    expect(Notification::getFlashed())->toHaveCount(1);
});

it('keeps a hidden label as the tooltip and accessible name (W-V5-01)', function (): void {
    expect(Action::make('hidden')->label('Hidden label')->icon('eye-off')->hiddenLabel()->toSchema())
        ->toMatchArray(['name' => 'hidden', 'icon' => 'eye-off', 'tooltip' => 'Hidden label'])
        ->not->toHaveKey('label');

    // An explicit tooltip wins over the hidden label.
    expect(Action::make('hidden')->label('Hidden label')->icon('eye-off')->tooltip('Hide it')->hiddenLabel()->toSchema())
        ->toMatchArray(['tooltip' => 'Hide it']);
});

it('hides an EditAction with nothing to edit: no form, no page to link to (W-V5-06)', function (): void {
    $post = Post::query()->create(['title' => 'Old', 'slug' => 'old-'.uniqid()]);

    expect(EditAction::make()->record($post)->isVisible())->toBeFalse()
        ->and(EditAction::make()->record($post)->form([TextInput::make('title')])->isVisible())->toBeTrue()
        ->and(EditAction::make()->record($post)->url('/posts/1/edit')->isVisible())->toBeTrue()
        ->and(EditAction::make()->record($post)->using(fn () => null)->isVisible())->toBeTrue()
        ->and(EditAction::make()->record($post)->action(fn () => null)->isVisible())->toBeTrue()
        ->and(EditAction::make()->offeredBeforeRecord()->isVisible())->toBeTrue();
});
