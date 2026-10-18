<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Actions\Action;
use AlfaSix\Alfakit\Actions\EditAction;
use AlfaSix\Alfakit\Actions\ReplicateAction;
use AlfaSix\Alfakit\Actions\ViewAction;
use AlfaSix\Alfakit\Forms\Components\TextInput;
use AlfaSix\Alfakit\Schemas\Components\Section;
use AlfaSix\Alfakit\Schemas\Schema;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Category;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Post;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/*
 * `mutateRecordDataUsing()` and the form lifecycle hooks of modal actions.
 */

function formHooksPost(array $attributes = []): Post
{
    return Post::query()->create(['title' => 'Hello', 'slug' => 'hello-'.uniqid(), ...$attributes]);
}

it('mutates the record data before it fills the edit, view and replicate forms', function (string $class): void {
    $post = formHooksPost(['status' => 'secret']);
    $seen = null;

    $action = $class::make()
        ->record($post)
        ->form([TextInput::make('title'), TextInput::make('subtitle')])
        ->mutateRecordDataUsing(function (array $data, Model $record) use (&$seen): array {
            $seen = [$data['title'], $record->getKey()];

            return [...$data, 'title' => strtoupper($data['title']), 'subtitle' => 'from '.$data['slug']];
        });

    $state = $action->toModalSchema()['form']['state'];

    expect($state)->toBe(['title' => 'HELLO', 'subtitle' => 'from '.$post->slug])
        // Attributes no field reads (status, slug) never reach the browser.
        ->and($state)->not->toHaveKey('status')
        ->and($seen)->toBe(['Hello', $post->getKey()]);
})->with([EditAction::class, ViewAction::class, ReplicateAction::class]);

it('ignores mutateRecordDataUsing when fillForm sets the data', function (): void {
    $action = EditAction::make()
        ->record(formHooksPost())
        ->form([TextInput::make('title')])
        ->fillForm(['title' => 'Explicit'])
        ->mutateRecordDataUsing(fn (array $data): array => ['title' => 'Mutated']);

    expect($action->toModalSchema()['form']['state'])->toBe(['title' => 'Explicit']);
});

it('keeps formatStateUsing and relationship layouts working with mutated record data', function (): void {
    $category = Category::query()->create(['name' => 'News', 'slug' => 'news']);
    $post = formHooksPost(['category_id' => $category->id]);

    $action = EditAction::make()
        ->record($post)
        ->form([
            TextInput::make('title')->formatStateUsing(fn (?string $state): string => "[{$state}]"),
            Section::make('Category')->relationship('category')->schema([TextInput::make('name')]),
        ])
        ->mutateRecordDataUsing(fn (array $data): array => [...$data, 'title' => 'Changed']);

    expect($action->toModalSchema()['form']['state'])->toMatchArray([
        'title' => '[Changed]',
        'category' => ['name' => 'News'],
    ]);
});

it('runs the form fill hooks when the modal opens, not when the action runs', function (): void {
    $calls = [];

    $action = Action::make('rename')
        ->record(formHooksPost())
        ->form([TextInput::make('title')])
        ->beforeFormFilled(function (Schema $schema) use (&$calls): void {
            $calls[] = 'beforeFill:'.json_encode($schema->getRawState());
        })
        ->afterFormFilled(function (array $data, Schema $schema) use (&$calls): void {
            $calls[] = 'afterFill:'.$data['title'];
            $schema->getComponent('title')?->getContainer()->setStateAt('title', 'Prefilled');
        })
        ->action(fn () => null);

    expect($action->toModalSchema()['form']['state'])->toBe(['title' => 'Prefilled'])
        ->and($calls)->toBe(['beforeFill:[]', 'afterFill:Hello']);

    $calls = [];
    $action->execute(['title' => 'New']);

    expect($calls)->toBe([]);
});

it('runs the form validation hooks around validation, before the action', function (): void {
    $calls = [];

    $action = Action::make('rename')
        ->form([TextInput::make('title')->required()])
        ->beforeFormValidated(function (array $data) use (&$calls): void {
            $calls[] = 'beforeValidated:'.($data['title'] ?? 'null');
        })
        ->afterFormValidated(function (array $data) use (&$calls): void {
            $calls[] = 'afterValidated:'.$data['title'];
        })
        ->before(function () use (&$calls): void {
            $calls[] = 'before';
        })
        ->action(function () use (&$calls): void {
            $calls[] = 'action';
        });

    expect(fn () => $action->execute(['title' => '']))->toThrow(ValidationException::class)
        ->and($calls)->toBe(['beforeValidated:']);

    $calls = [];
    $action->execute(['title' => 'Ok']);

    expect($calls)->toBe(['beforeValidated:Ok', 'afterValidated:Ok', 'before', 'action']);
});

it('can halt the action from a validation hook', function (): void {
    $ran = false;

    $action = Action::make('rename')
        ->form([TextInput::make('title')])
        ->afterFormValidated(fn (Action $action) => $action->halt())
        ->action(function () use (&$ran): void {
            $ran = true;
        });

    expect(fn () => $action->execute(['title' => 'x']))->toThrow(AlfaSix\Alfakit\Actions\Exceptions\Halt::class)
        ->and($ran)->toBeFalse();
});
