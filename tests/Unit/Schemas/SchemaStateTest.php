<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Forms\Components\Checkbox;
use AlfaSix\Alfakit\Forms\Components\Select;
use AlfaSix\Alfakit\Forms\Components\TextInput;
use AlfaSix\Alfakit\Schemas\Components\Group;
use AlfaSix\Alfakit\Schemas\Components\Section;
use AlfaSix\Alfakit\Schemas\Components\Tabs;
use AlfaSix\Alfakit\Schemas\Components\Tabs\Tab;
use AlfaSix\Alfakit\Schemas\Components\Utilities\Get;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Post;
use Illuminate\Validation\ValidationException;

it('fills defaults on create', function (): void {
    $schema = schema([
        TextInput::make('title')->default('Untitled'),
        TextInput::make('slug'),
        Checkbox::make('is_published'),
        Select::make('tags')->multiple(),
    ])->fill();

    expect($schema->getRawState())->toBe([
        'title' => 'Untitled',
        'slug' => null,
        'is_published' => false,
        'tags' => [],
    ]);
});

it('fills from the record on edit', function (): void {
    $post = Post::query()->create(['title' => 'Hello', 'slug' => 'hello', 'is_published' => true]);

    $schema = schema([TextInput::make('title'), TextInput::make('slug'), Checkbox::make('is_published')], record: $post, operation: 'edit')->fill();

    expect($schema->getRawState())->toMatchArray(['title' => 'Hello', 'slug' => 'hello', 'is_published' => true]);
});

it('nests state under group and section state paths while sections without a path stay flat', function (): void {
    $schema = schema([
        Section::make('Meta')->schema([
            TextInput::make('title'),
            Group::make([TextInput::make('street'), TextInput::make('city')])->statePath('address'),
        ]),
        Tabs::make()->tabs([Tab::make('SEO')->schema([TextInput::make('meta_title')])]),
    ])->fill(['title' => 'T', 'address' => ['street' => 'S', 'city' => 'C'], 'meta_title' => 'M']);

    $fields = $schema->getFlatFields();

    expect(array_keys($fields))->toBe(['title', 'address.street', 'address.city', 'meta_title'])
        ->and($fields['address.city']->getState())->toBe('C')
        ->and($schema->getState())->toBe(['title' => 'T', 'address' => ['street' => 'S', 'city' => 'C'], 'meta_title' => 'M']);
});

it('resolves relative paths with Get and Set', function (): void {
    $title = TextInput::make('title');
    $slug = TextInput::make('slug');

    schema([Group::make([$title, $slug])->statePath('seo')])->fill(['seo' => ['title' => 'Hello World', 'slug' => null]]);

    $get = $title->makeGetUtility();
    $set = $title->makeSetUtility();

    expect($get('slug'))->toBeNull()
        ->and($get('../seo.title'))->toBe('Hello World')
        ->and($get('seo.title', isAbsolute: true))->toBe('Hello World');

    $set('slug', 'hello-world');

    expect($slug->getState())->toBe('hello-world');

    expect(Get::resolveRelativePath('items.0.name', '../1.name'))->toBe('items.1.name');
});

it('runs afterStateUpdated hooks for a path and marks the field live', function (): void {
    $title = TextInput::make('title')->afterStateUpdated(fn ($state, $set) => $set('slug', str($state)->slug()->toString()));
    $slug = TextInput::make('slug');
    $schema = schema([$title, $slug])->fill();

    expect($title->isLive())->toBeTrue();

    $schema->setStateAt('title', 'Hello World')->callAfterStateUpdated('title');

    expect($slug->getState())->toBe('hello-world');
});

it('excludes hidden and non-dehydrated fields from state', function (): void {
    $schema = schema([
        TextInput::make('a'),
        TextInput::make('b')->hidden(),
        TextInput::make('c')->dehydrated(false),
        TextInput::make('d')->hidden()->dehydratedWhenHidden(),
    ])->fill(['a' => 1, 'b' => 2, 'c' => 3, 'd' => 4]);

    expect($schema->getState())->toBe(['a' => 1, 'd' => 4]);
});

it('validates using field rules with readable attribute names', function (): void {
    $schema = schema([
        TextInput::make('title')->required()->maxLength(5),
        TextInput::make('author_email')->email(),
    ])->fill(['title' => 'Too long title', 'author_email' => 'nope']);

    try {
        $schema->validate();
        $this->fail('Expected validation to fail');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKeys(['title', 'author_email'])
            ->and($e->errors()['title'][0])->toContain('title')
            ->and($e->errors()['author_email'][0])->toContain('author email');
    }
});

it('applies operation visibility', function (): void {
    $field = TextInput::make('password')->visibleOn('create');

    schema([$field], operation: 'edit');

    expect($field->isHidden())->toBeTrue();

    schema([$field], operation: 'create');

    expect($field->isHidden())->toBeFalse();
});

it('gives layout components keys that survive a rebuild', function (): void {
    $build = fn () => schema([
        Section::make('A')->schema([
            Section::make('Nested')->schema([TextInput::make('name')]),
        ]),
        Section::make('B'),
        Tabs::make('Tabs'),
    ])->toSchema();

    $keys = fn (array $tree) => array_map(fn (array $node) => $node['key'], $tree);
    $first = $build();

    expect($keys($first))->toBe($keys($build()))
        ->toBe(['section-0', 'section-1', 'tabs-0'])
        ->and($first[0]['schema'][0]['key'])->toBe('section-0.section-0');
});
