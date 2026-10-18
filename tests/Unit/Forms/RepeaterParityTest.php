<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Forms\Components\Builder;
use AlfaSix\Alfakit\Forms\Components\Builder\Block;
use AlfaSix\Alfakit\Forms\Components\Repeater;
use AlfaSix\Alfakit\Forms\Components\Select;
use AlfaSix\Alfakit\Forms\Components\TextInput;
use AlfaSix\Alfakit\Forms\Components\Toggle;
use AlfaSix\Alfakit\Support\Enums\Width;
use Illuminate\Support\Facades\View;
use Illuminate\Support\HtmlString;

function indistinctToggle(string $name): Toggle
{
    return Toggle::make($name);
}

it('fakes and restores repeater item keys for Filament-style tests', function (): void {
    $undo = Repeater::fake();

    expect(Repeater::isFaked())->toBeTrue()
        ->and(Builder::isFaked())->toBeTrue();

    $undo();

    expect(Repeater::isFaked())->toBeFalse();

    Builder::fake();
    Builder::undoRepeaterFake();

    expect(Builder::isFaked())->toBeFalse();
});

it('reads raw and dehydrated item state by key', function (): void {
    $repeater = Repeater::make('lines')->schema([
        TextInput::make('sku')->dehydrateStateUsing(fn (?string $state): string => strtoupper((string) $state)),
    ]);
    schema([$repeater])->fill(['lines' => [['sku' => 'ab'], ['sku' => 'cd']]]);

    expect($repeater->getRawItemState('1'))->toBe(['sku' => 'cd'])
        ->and($repeater->getItemState(1))->toBe(['sku' => 'CD'])
        ->and($repeater->getItemState('x'))->toBe([])
        ->and($repeater->getItemIndex('5'))->toBeNull();
});

it('fixes indistinct boolean and scalar state across sibling items', function (): void {
    $repeater = Repeater::make('addresses')->schema([
        indistinctToggle('primary')->fixIndistinctState(),
        TextInput::make('city'),
    ]);
    $schema = schema([$repeater])->fill(['addresses' => [
        ['primary' => true, 'city' => 'A'],
        ['primary' => false, 'city' => 'B'],
        ['primary' => true, 'city' => 'C'],
    ]]);

    $field = $schema->getFieldByStatePath('addresses.1.primary');

    expect($field)->not->toBeNull()
        ->and($field->isLive())->toBeTrue();

    $field->state(true);
    $field->callAfterStateUpdated(false);

    expect(array_column($schema->getRawState()['addresses'], 'primary'))->toBe([false, true, false]);

    // Turning a toggle off leaves the others alone.
    $field->state(false);
    $field->callAfterStateUpdated(true);

    expect(array_column($schema->getRawState()['addresses'], 'primary'))->toBe([false, false, false]);

    // Outside a repeater it does nothing.
    $standalone = indistinctToggle('alone')->fixIndistinctState();
    schema([$standalone])->fill(['alone' => true]);
    $standalone->callAfterStateUpdated(false);

    expect($standalone->getState())->toBeTrue();
});

it('disables options selected in sibling repeater and builder items', function (): void {
    $repeater = Repeater::make('lines')->schema([
        Select::make('product')->options(['a' => 'A', 'b' => 'B', 'c' => 'C'])->disableOptionsWhenSelectedInSiblingRepeaterItems(),
    ]);
    $props = schema([$repeater])->fill(['lines' => [['product' => 'a'], ['product' => 'b'], ['product' => null]]])->toSchema()[0];

    $disabled = static fn (array $item): array => collect($item['schema'][0]['options'])->where('disabled', true)->pluck('value')->all();

    expect($disabled($props['items'][0]))->toBe(['b'])
        ->and($disabled($props['items'][2]))->toBe(['a', 'b'])
        // A new item (the template) can't pick any selected value either.
        ->and(collect($props['template'][0]['options'])->where('disabled', true)->pluck('value')->all())->toBe(['a', 'b'])
        ->and($props['items'][0]['schema'][0]['live'] ?? null)->not->toBeNull();

    // Validation still rejects duplicates sent anyway.
    expect(fn () => schema([$repeater])->fill(['lines' => [['product' => 'a'], ['product' => 'a']]])->validate())
        ->toThrow(Illuminate\Validation\ValidationException::class);

    $builder = Builder::make('content')->blocks([
        Block::make('pick')->schema([
            Select::make('choice')->options(['x' => 'X', 'y' => 'Y'])->disableOptionsWhenSelectedInSiblingRepeaterItems(),
        ]),
    ]);
    $props = schema([$builder])->fill(['content' => [
        ['type' => 'pick', 'data' => ['choice' => 'x']],
        ['type' => 'pick', 'data' => ['choice' => null]],
    ]])->toSchema()[0];

    expect($disabled($props['items'][1]))->toBe(['x']);

    // Outside a repeater nothing is disabled.
    $select = Select::make('solo')->options(['x' => 'X'])->disableOptionsWhenSelectedInSiblingRepeaterItems();

    expect(collect(schema([$select])->fill(['solo' => 'x'])->toSchema()[0]['options'])->where('disabled', true)->all())->toBe([]);
});

it('renders block previews from views, closures and frontend components', function (): void {
    $directory = sys_get_temp_dir().'/alfakit-block-previews';
    @mkdir($directory);
    file_put_contents($directory.'/heading-preview.blade.php', '<h2>{{ $text }} #{{ $index }}</h2>');
    View::addLocation($directory);

    $builder = Builder::make('content')
        ->blockPreviews(areInteractive: true)
        ->blockPickerWidth(Width::TwoExtraLarge)
        ->blocks([
            Block::make('heading')->preview('heading-preview')->schema([TextInput::make('text')]),
            Block::make('quote')->preview(fn (array $data): HtmlString => new HtmlString('<q>'.e($data['body'] ?? '').'</q>'))->schema([TextInput::make('body')]),
            Block::make('chart')->previewComponent('chart')->schema([TextInput::make('title')]),
            Block::make('plain')->schema([TextInput::make('value')]),
        ]);

    $props = schema([$builder])->fill(['content' => [
        ['type' => 'heading', 'data' => ['text' => '<b>Hi</b>']],
        ['type' => 'quote', 'data' => ['body' => 'Q&A']],
        ['type' => 'chart', 'data' => ['title' => 'Sales']],
        ['type' => 'plain', 'data' => ['value' => 'v']],
    ]])->toSchema()[0];

    expect($props['blockPreviews'])->toBeTrue()
        ->and($props['interactivePreviews'])->toBeTrue()
        ->and($props['blockPickerWidth'])->toBe('2xl')
        // Blade escapes the data.
        ->and($props['items'][0]['preview'])->toBe('<h2>&lt;b&gt;Hi&lt;/b&gt; #0</h2>')
        ->and($props['items'][1]['preview'])->toBe('<q>Q&amp;A</q>')
        ->and($props['items'][2]['previewComponent'])->toBe('chart')
        ->and($props['items'][3])->not->toHaveKey('preview')
        ->and($props['blocks'][2]['previewComponent'])->toBe('chart');

    // Without `blockPreviews()` nothing is rendered.
    $plain = schema([Builder::make('content')->blocks([Block::make('heading')->preview('heading-preview')->schema([TextInput::make('text')])])])
        ->fill(['content' => [['type' => 'heading', 'data' => ['text' => 'x']]]])->toSchema()[0];

    expect($plain['items'][0])->not->toHaveKey('preview')
        ->and($plain)->not->toHaveKey('blockPreviews');
});

it('keeps the simple flags working alongside the actions', function (): void {
    $props = schema([Builder::make('content')->blocks([Block::make('a')->schema([TextInput::make('x')])])->reorderableWithButtons(false)->addActionAlignment('center')])
        ->fill(['content' => []])->toSchema()[0];

    expect($props['reorderable'])->toBeTrue()
        ->and($props['reorderableWithButtons'])->toBeFalse()
        ->and($props['addActionAlignment'])->toBe('center')
        ->and($props)->not->toHaveKey('itemActions');
});

it('offers fixIndistinctState() on toggles, checkboxes and radios', function (): void {
    foreach ([Toggle::make('a'), AlfaSix\Alfakit\Forms\Components\Checkbox::make('b'), AlfaSix\Alfakit\Forms\Components\Radio::make('c')] as $field) {
        expect($field->fixIndistinctState()->shouldFixIndistinctState())->toBeTrue();
    }
});
