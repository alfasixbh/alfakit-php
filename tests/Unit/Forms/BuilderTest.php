<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Forms\Components\Builder;
use AlfaSix\Alfakit\Forms\Components\Builder\Block;
use AlfaSix\Alfakit\Forms\Components\Repeater;
use AlfaSix\Alfakit\Forms\Components\Select;
use AlfaSix\Alfakit\Forms\Components\Textarea;
use AlfaSix\Alfakit\Forms\Components\TextInput;
use AlfaSix\Alfakit\Schemas\Components\Utilities\Get;
use Illuminate\Validation\ValidationException;

function contentBuilder(): Builder
{
    return Builder::make('content')->blocks([
        Block::make('heading')
            ->label(fn (?array $state): string => $state === null ? 'Heading' : ($state['text'] ?? 'Untitled'))
            ->icon('heading')
            ->schema([
                TextInput::make('text')->required(),
                Select::make('level')->options(['h2' => 'H2', 'h3' => 'H3'])->default('h2'),
            ]),
        Block::make('paragraph')->icon('text')->maxItems(2)->columns(2)->schema([
            Textarea::make('body')->required(),
            TextInput::make('echo')->default(fn (Get $get): ?string => $get('body')),
        ]),
        Block::make('secret')->hidden()->schema([TextInput::make('value')]),
    ]);
}

/**
 * @return array<string, array<string>>
 */
function builderErrors(AlfaSix\Alfakit\Schemas\Schema $schema): array
{
    try {
        $schema->validate();
    } catch (ValidationException $exception) {
        return $exception->errors();
    }

    return [];
}

it('hydrates and dehydrates a list of {type, data} items', function (): void {
    $schema = schema([contentBuilder()])->fill([
        'content' => [
            ['type' => 'heading', 'data' => ['text' => 'Hello']],
            ['type' => 'paragraph', 'data' => ['body' => 'World']],
            ['type' => 'removed-block', 'data' => ['x' => 1]],
            'not an item',
        ],
    ]);

    // Missing item fields take their defaults; closures see the item's own state.
    expect($schema->getRawState()['content'])->toBe([
        ['type' => 'heading', 'data' => ['text' => 'Hello', 'level' => 'h2']],
        ['type' => 'paragraph', 'data' => ['body' => 'World', 'echo' => 'World']],
    ])->and($schema->validate())->toBe([
        'content' => [
            ['type' => 'heading', 'data' => ['text' => 'Hello', 'level' => 'h2']],
            ['type' => 'paragraph', 'data' => ['body' => 'World', 'echo' => 'World']],
        ],
    ]);
});

it('starts empty and serialises blocks, items and templates', function (): void {
    $builder = contentBuilder()->label('Content')->blockPickerColumns(2)->cloneable()->collapsible();
    $schema = schema([$builder])->fill();

    expect($schema->getRawState())->toBe(['content' => []]);

    $schema->rawState(['content' => [['type' => 'heading', 'data' => ['text' => 'Intro', 'level' => 'h2']]]]);
    $props = $builder->toSchema();

    expect($props['type'])->toBe('Builder')
        ->and($props)->not->toHaveKey('schema')
        ->and(array_column($props['blocks'], 'name'))->toBe(['heading', 'paragraph'])
        ->and($props['blocks'][0]['label'])->toBe('Heading')
        ->and($props['blocks'][0]['icon'])->toBe('heading')
        ->and($props['blocks'][0]['state'])->toBe(['type' => 'heading', 'data' => ['text' => null, 'level' => 'h2']])
        ->and($props['blocks'][0]['schema'][0]['statePath'])->toBe('content.__INDEX__.data.text')
        ->and($props['blocks'][1]['maxItems'])->toBe(2)
        ->and($props['blocks'][1]['columns'])->toBe(['default' => 1, 'lg' => 2])
        ->and($props['items'][0])->toMatchArray(['key' => '0', 'type' => 'heading', 'label' => 'Intro', 'icon' => 'heading'])
        ->and($props['items'][0]['schema'][0]['statePath'])->toBe('content.0.data.text')
        ->and($props['blockPickerColumns'])->toBe(['default' => 1, 'sm' => 2])
        ->and($props['addActionLabel'])->toBe('Add to content')
        ->and($props['cloneable'])->toBeTrue()
        ->and($props['collapsible'])->toBeTrue()
        ->and($props['blockNumbers'])->toBeTrue();
});

it('omits unset item limits instead of serialising them as null', function (): void {
    $builder = contentBuilder();
    $schema = schema([$builder])->fill();
    $props = $builder->toSchema();

    // A null limit would read as "0 allowed" in a renderer and disable every block.
    expect($props)->not->toHaveKey('maxItems')
        ->and($props)->not->toHaveKey('minItems')
        ->and($props['blocks'][0])->not->toHaveKey('maxItems')
        ->and($props['blocks'][1]['maxItems'])->toBe(2);

    $limited = contentBuilder()->minItems(1)->maxItems(5);
    schema([$limited])->fill();
    $repeater = Repeater::make('members')->schema([TextInput::make('name')]);
    schema([$repeater])->fill();

    expect($limited->toSchema())->toMatchArray(['minItems' => 1, 'maxItems' => 5])
        ->and($repeater->toSchema())->not->toHaveKey('maxItems')
        ->and($repeater->toSchema())->not->toHaveKey('minItems');
});

it('serialises item summaries and the block actions menu', function (): void {
    $builder = Builder::make('content')->blockActionsMenu()->blocks([
        Block::make('quote')
            ->summary(fn (array $state): ?string => $state['text'] ?? null)
            ->schema([TextInput::make('text')]),
        Block::make('divider')->schema([]),
    ]);
    $schema = schema([$builder])->fill([
        'content' => [
            ['type' => 'quote', 'data' => ['text' => "<b>Hello</b>\n   world"]],
            ['type' => 'divider', 'data' => []],
        ],
    ]);
    $props = $builder->toSchema();

    expect($props['blockActionsMenu'])->toBeTrue()
        ->and($props['items'][0]['summary'])->toBe('Hello world')
        ->and($props['items'][1])->not->toHaveKey('summary')
        ->and(contentBuilder()->toSchema()['blockActionsMenu'] ?? null)->toBeNull();
});

it('validates block types, item fields and block limits on the server', function (): void {
    $schema = fn (array $state) => schema([contentBuilder()->maxItems(4)])->fill()->applyClientState(['content' => $state]);

    $unknown = builderErrors($schema([['type' => 'script', 'data' => ['src' => 'x']]]));
    $hidden = builderErrors($schema([['type' => 'secret', 'data' => ['value' => 'x']]]));
    $fields = builderErrors($schema([['type' => 'heading', 'data' => ['text' => '']]]));
    $limit = builderErrors($schema(array_fill(0, 3, ['type' => 'paragraph', 'data' => ['body' => 'x']])));
    $max = builderErrors($schema(array_fill(0, 5, ['type' => 'heading', 'data' => ['text' => 'x']])));

    expect($unknown)->toHaveKey('content.0.type')
        ->and($hidden)->toHaveKey('content.0.type')
        ->and($fields)->toHaveKey('content.0.data.text')
        ->and($limit['content'] ?? null)->toBe(['Paragraph can be used up to 2 times.'])
        ->and($max)->toHaveKey('content')
        ->and(builderErrors($schema([['type' => 'heading', 'data' => ['text' => 'Ok']]])))->toBe([]);
});

it('keeps existing items of a block that was hidden after they were saved', function (): void {
    $schema = schema([contentBuilder()])->fill(['content' => [['type' => 'secret', 'data' => ['value' => 'kept']]]]);
    $schema->applyClientState(['content' => [['type' => 'secret', 'data' => ['value' => 'kept']]]]);

    expect(builderErrors($schema))->toBe([]);
});

it('enforces addable and deletable on the server', function (): void {
    $state = ['content' => [['type' => 'heading', 'data' => ['text' => 'A']]]];

    $added = schema([contentBuilder()->addable(false)])->fill($state)
        ->applyClientState(['content' => [...$state['content'], ['type' => 'heading', 'data' => ['text' => 'B']]]]);
    $removed = schema([contentBuilder()->deletable(false)])->fill($state)->applyClientState(['content' => []]);

    expect(builderErrors($added)['content'] ?? null)->toBe(["Items can't be added to content."])
        ->and(builderErrors($removed)['content'] ?? null)->toBe(["Items can't be removed from content."]);
});

it('locates nested fields and runs their closures with item state', function (): void {
    $builder = contentBuilder();
    $schema = schema([$builder])->fill(['content' => [['type' => 'paragraph', 'data' => ['body' => 'Hi']]]]);

    expect($schema->getFieldByStatePath('content.0.data.body'))->toBeInstanceOf(Textarea::class)
        ->and($schema->getFieldByStatePath('content.0.data.text'))->toBeNull()
        ->and($schema->getFieldByStatePath('content.4.data.body'))->toBeNull();
});

it('propagates read-only and disabled to block fields', function (): void {
    $builder = contentBuilder()->readOnly();
    $schema = schema([$builder])->fill(['content' => [['type' => 'heading', 'data' => ['text' => 'A']]]]);

    expect($schema->getFieldByStatePath('content.0.data.text')?->isReadOnly())->toBeTrue()
        ->and($builder->toSchema()['items'][0]['schema'][0]['readOnly'])->toBeTrue();
});

it('nests repeaters inside blocks', function (): void {
    $builder = Builder::make('content')->blocks([
        Block::make('list')->schema([Repeater::make('rows')->schema([TextInput::make('cell')->required()])->defaultItems(0)]),
    ]);
    $schema = schema([$builder])->fill(['content' => [['type' => 'list', 'data' => ['rows' => [['cell' => 'a']]]]]]);

    expect($schema->validate())->toBe(['content' => [['type' => 'list', 'data' => ['rows' => [['cell' => 'a']]]]]]);

    $schema->applyClientState(['content' => [['type' => 'list', 'data' => ['rows' => [['cell' => '']]]]]]);

    expect(builderErrors($schema))->toHaveKey('content.0.data.rows.0.cell');
});

it('refuses relationship and simple modes', function (): void {
    expect(fn () => Builder::make('content')->relationship())->toThrow(LogicException::class)
        ->and(fn () => Builder::make('content')->simple(TextInput::make('x')))->toThrow(LogicException::class);
});
