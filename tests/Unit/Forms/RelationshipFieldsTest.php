<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Forms\Components\CheckboxList;
use AlfaSix\Alfakit\Forms\Components\Repeater;
use AlfaSix\Alfakit\Forms\Components\Select;
use AlfaSix\Alfakit\Forms\Components\TextInput;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Category;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Post;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Tag;
use AlfaSix\Alfakit\Tests\Fixtures\Models\User;

it('loads BelongsTo options from a relationship and validates existence', function (): void {
    Category::query()->create(['name' => 'News', 'slug' => 'news']);
    Category::query()->create(['name' => 'Art', 'slug' => 'art']);

    $select = Select::make('category_id')->relationship('category', 'name');
    $schema = schema([$select], model: Post::class)->fill(['category_id' => 999]);

    expect($select->getOptions())->toBe(['2' => 'Art', '1' => 'News'])
        ->and($select->getLabel())->toBe('Category')
        ->and(fn () => $schema->validate())->toThrow(Illuminate\Validation\ValidationException::class);
});

it('searches relationship options and resolves selected labels', function (): void {
    User::query()->create(['name' => 'Ada Lovelace', 'email' => 'ada@example.com', 'password' => 'secret']);
    User::query()->create(['name' => 'Alan Turing', 'email' => 'alan@example.com', 'password' => 'secret']);

    $select = Select::make('author_id')->relationship('author', 'name')->searchable();
    schema([$select], model: Post::class)->fill(['author_id' => 2]);

    expect($select->getOptions())->toBe([])
        ->and($select->getSearchResults('ada'))->toBe(['1' => 'Ada Lovelace'])
        ->and($select->toSchema()['selectedLabels'])->toBe(['2' => 'Alan Turing']);
});

it('syncs BelongsToMany through Select and CheckboxList', function (): void {
    $post = Post::query()->create(['title' => 'P', 'slug' => 'p']);
    $tags = collect(['php', 'laravel', 'inertia'])->map(fn (string $name) => Tag::query()->create(['name' => $name]));
    $post->tags()->attach($tags[0]);

    $select = Select::make('tags')->relationship('tags', 'name')->multiple();
    $list = CheckboxList::make('tag_ids')->relationship('tags', 'name');
    $schema = schema([$select, $list], record: $post, operation: 'edit')->fill();

    expect($select->getState())->toBe(['1'])
        ->and($list->getState())->toBe(['1'])
        ->and($schema->getState())->toBe([]); // not dehydrated: saved through relationships

    $schema->rawState(['tags' => ['2', '3'], 'tag_ids' => ['2', '3']]);
    $select->saveRelationships();

    expect($post->tags()->pluck('name')->all())->toBe(['laravel', 'inertia']);
});

it('hydrates, validates, dehydrates and saves a HasMany repeater', function (): void {
    $post = Post::query()->create(['title' => 'P', 'slug' => 'p']);
    $post->comments()->create(['author_name' => 'A', 'body' => 'first', 'sort' => 0]);
    $post->comments()->create(['author_name' => 'B', 'body' => 'second', 'sort' => 1]);

    $repeater = Repeater::make('comments')->relationship()->orderColumn()->schema([
        TextInput::make('author_name')->required(),
        TextInput::make('body')->required()->maxLength(10),
    ]);

    $schema = schema([$repeater], record: $post, operation: 'edit')->fill();

    expect($schema->getRawState()['comments'])->toHaveCount(2)
        ->and($schema->getRawState()['comments'][0]['body'])->toBe('first');

    $serialized = $repeater->toSchema();
    expect($serialized['items'])->toHaveCount(2)
        ->and($serialized['items'][1]['schema'][0]['statePath'])->toBe('comments.1.author_name')
        ->and($serialized['template'][0]['statePath'])->toBe('comments.__INDEX__.author_name');

    // Reorder, edit, delete one, add one — and trigger a nested validation error first.
    $state = $schema->getRawState();
    $state['comments'] = [
        ['id' => 2, 'author_name' => 'B', 'body' => 'way too long body'],
    ];
    $schema->rawState($state);

    expect(fn () => $schema->validate())->toThrow(Illuminate\Validation\ValidationException::class);

    $state['comments'] = [
        ['id' => 2, 'author_name' => 'B', 'body' => 'second!'],
        ['author_name' => 'C', 'body' => 'third'],
    ];
    $schema->rawState($state);
    $schema->validate();
    $schema->saveRelationships();

    $comments = $post->comments()->orderBy('sort')->get();

    expect($comments->pluck('body')->all())->toBe(['second!', 'third'])
        ->and($comments->pluck('sort')->all())->toBe([0, 1]);
});

it('dehydrates a plain repeater as a nested array and validates per item', function (): void {
    $repeater = Repeater::make('lines')->schema([
        TextInput::make('sku')->required(),
        TextInput::make('qty')->integer()->minValue(1),
    ])->defaultItems(2);

    $schema = schema([$repeater])->fill();

    expect($schema->getRawState()['lines'])->toBe([['sku' => null, 'qty' => null], ['sku' => null, 'qty' => null]]);

    $schema->rawState(['lines' => [['sku' => 'A', 'qty' => '2'], ['sku' => 'B', 'qty' => '0']]]);

    try {
        $schema->validate();
        $this->fail('expected failure');
    } catch (Illuminate\Validation\ValidationException $e) {
        expect($e->errors())->toHaveKey('lines.1.qty');
    }

    $schema->rawState(['lines' => [['sku' => 'A', 'qty' => '2']]]);

    expect($schema->validate())->toBe(['lines' => [['sku' => 'A', 'qty' => 2]]]);
});
