<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Forms\Components\Builder;
use AlfaSix\Alfakit\Forms\Components\Builder\Block;
use AlfaSix\Alfakit\Forms\Components\Repeater;
use AlfaSix\Alfakit\Forms\Components\TextInput;
use AlfaSix\Alfakit\Schemas\Components\Component;
use AlfaSix\Alfakit\Schemas\Components\Group;
use AlfaSix\Alfakit\Schemas\Components\Section;
use AlfaSix\Alfakit\Schemas\Components\Text;
use AlfaSix\Alfakit\Schemas\Components\Utilities\Get;
use AlfaSix\Alfakit\Schemas\Components\Utilities\Set;
use AlfaSix\Alfakit\Schemas\Schema;

/**
 * The first component with `$key` in a schema, searched depth-first.
 */
function componentByKey(Schema $schema, string $key): Component
{
    $component = $schema->getComponent($key, withHidden: true);

    expect($component)->not->toBeNull();

    return $component;
}

it('resolves relative paths of a layout component in a repeater item from the item', function (): void {
    $repeater = Repeater::make('items')->schema([
        TextInput::make('quantity'),
        Section::make('Totals')->key('totals')->schema([
            Text::make('line')->key('line'),
        ]),
        Group::make([Text::make('grouped')->key('grouped')])->key('group'),
    ]);
    $schema = schema([TextInput::make('currency'), $repeater])->fill([
        'currency' => 'EUR',
        'items' => [['quantity' => '2'], ['quantity' => '5']],
    ]);

    $item = $repeater->getItemSchema(1);
    $section = componentByKey($item, 'totals');
    $text = componentByKey($item, 'line');
    $grouped = componentByKey($item, 'grouped');

    expect($section->makeGetUtility()('quantity'))->toBe('5')
        ->and($text->makeGetUtility()('quantity'))->toBe('5')
        ->and($grouped->makeGetUtility()('quantity'))->toBe('5')
        ->and($text->makeGetUtility()->resolvePath('quantity'))->toBe('items.1.quantity')
        ->and($text->makeGetUtility()('../../currency'))->toBe('EUR')
        ->and($text->makeGetUtility()('currency', isAbsolute: true))->toBe('EUR')
        ->and($text->makeGetUtility()('items.0.quantity', isAbsolute: true))->toBe('2')
        ->and($text->makeGetUtility()(''))->toBe(['quantity' => '5']);

    $text->makeSetUtility()('quantity', '7');
    $text->makeSetUtility()('../../currency', 'USD');

    expect($schema->getRawState()['items'][1]['quantity'])->toBe('7')
        ->and($schema->getRawState()['currency'])->toBe('USD');

    $text->makeSetUtility()('items.0.quantity', '9', isAbsolute: true);

    expect($schema->getRawState()['items'][0]['quantity'])->toBe('9');
});

it('keeps field relative paths resolving from the field container', function (): void {
    $repeater = Repeater::make('items')->schema([
        TextInput::make('quantity'),
        TextInput::make('total')->key('total'),
    ]);
    $schema = schema([TextInput::make('currency'), $repeater])->fill([
        'currency' => 'EUR',
        'items' => [['quantity' => '3', 'total' => '30']],
    ]);

    $total = componentByKey($repeater->getItemSchema(0), 'total');

    expect($total->makeGetUtility()('quantity'))->toBe('3')
        ->and($total->makeGetUtility()('../../currency'))->toBe('EUR')
        ->and($total->makeGetUtility()('./quantity'))->toBe('3');

    $total->makeSetUtility()('quantity', '4');

    expect($schema->getRawState()['items'][0]['quantity'])->toBe('4');
});

it('resolves layout components in nested repeaters level by level', function (): void {
    $lines = Repeater::make('lines')->key('lines')->schema([
        TextInput::make('amount'),
        Text::make('summary')->key('summary'),
    ]);
    $groups = Repeater::make('groups')->schema([
        TextInput::make('name'),
        $lines,
    ]);
    $schema = schema([TextInput::make('title'), $groups])->fill([
        'title' => 'Invoice',
        'groups' => [['name' => 'A', 'lines' => [['amount' => '1'], ['amount' => '2']]]],
    ]);

    $innerRepeater = componentByKey($groups->getItemSchema(0), 'lines');
    expect($innerRepeater)->toBeInstanceOf(Repeater::class);
    /** @var Repeater $innerRepeater */
    $summary = componentByKey($innerRepeater->getItemSchema(1), 'summary');
    $get = $summary->makeGetUtility();

    expect($get('amount'))->toBe('2')
        ->and($get('../../name'))->toBe('A')
        ->and($get('../../../../title'))->toBe('Invoice')
        ->and($get('title', isAbsolute: true))->toBe('Invoice');

    $summary->makeSetUtility()('../../name', 'B');

    expect($schema->getRawState()['groups'][0]['name'])->toBe('B');
});

it('resolves layout components in builder blocks from the block data', function (): void {
    $builder = Builder::make('content')->blocks([
        Block::make('price')->schema([
            TextInput::make('amount'),
            Text::make('preview')->key('preview'),
        ]),
    ]);
    $schema = schema([TextInput::make('title'), $builder])->fill([
        'title' => 'Page',
        'content' => [['type' => 'price', 'data' => ['amount' => '10']]],
    ]);

    $preview = componentByKey($builder->getItemSchema(0), 'preview');
    $get = $preview->makeGetUtility();

    expect($get('amount'))->toBe('10')
        ->and($get->resolvePath('amount'))->toBe('content.0.data.amount')
        ->and($get('../type'))->toBe('price')
        ->and($get('../../../title'))->toBe('Page');

    $preview->makeSetUtility()('amount', '12');

    expect($schema->getRawState()['content'][0]['data']['amount'])->toBe('12');
});

it('evaluates a layout content closure with the item state', function (): void {
    $repeater = Repeater::make('items')->schema([
        TextInput::make('quantity'),
        TextInput::make('price'),
        Text::make(fn (Get $get): string => (string) ((int) $get('quantity') * (int) $get('price')))->key('total'),
    ]);
    $schema = schema([$repeater])->fill(['items' => [['quantity' => '2', 'price' => '3']]]);

    $payload = $schema->toPayload();
    $item = $payload['components'][0]['items'][0]['schema'];

    expect(collect($item)->firstWhere('key', 'total')['content'] ?? null)->toBe('6');
});

it('rebuilds closure-generated children after the browser state is applied', function (): void {
    $calls = 0;
    $section = Section::make('Dynamic')->key('dynamic')->schema(function (Get $get) use (&$calls): array {
        $calls++;

        return array_map(
            static fn (int $i): TextInput => TextInput::make("extra_{$i}"),
            range(1, max(0, (int) $get('count'))),
        );
    });
    $schema = schema([TextInput::make('count')->live(), $section])->fill(['count' => '1']);

    // The server-filled schema is built (as resolving the page does) before the live update.
    expect(array_keys($schema->getFlatFields()))->toBe(['count', 'extra_1']);

    $schema->applyClientState(['count' => '3', 'extra_1' => 'a']);

    expect(array_keys($schema->getFlatFields()))->toBe(['count', 'extra_1', 'extra_2', 'extra_3']);

    $keys = array_column($schema->toPayload()['components'][1]['schema'], 'statePath');
    expect($keys)->toBe(['extra_1', 'extra_2', 'extra_3']);

    // Memoised per state version: reading again without a state change doesn't rebuild.
    $before = $calls;
    $section->getChildSchema();
    $section->getChildSchema();
    expect($section->getChildSchema())->toBe($section->getChildSchema());

    // A `$set()` during the update changes the state version, so the children follow it.
    $section->makeSetUtility()('count', '2');

    expect(array_keys($schema->getFlatFields()))->toBe(['count', 'extra_1', 'extra_2'])
        ->and($calls)->toBeGreaterThan($before);
});

it('rebuilds closure-generated children after an afterStateUpdated hook sets state', function (): void {
    $schema = schema([
        TextInput::make('mode')->live()->afterStateUpdated(fn (Set $set, ?string $state) => $set('count', $state === 'many' ? '3' : '1')),
        TextInput::make('count'),
        Section::make('Dynamic')->key('dynamic')->schema(fn (Get $get): array => array_map(
            static fn (int $i): TextInput => TextInput::make("row_{$i}"),
            (int) $get('count') > 0 ? range(1, (int) $get('count')) : [],
        )),
    ])->fill(['mode' => 'none', 'count' => '1']);

    expect(array_keys($schema->getFlatFields()))->toBe(['mode', 'count', 'row_1']);

    $schema->applyClientState(['mode' => 'many', 'count' => '1']);

    expect(array_keys($schema->getFlatFields()))->toBe(['mode', 'count', 'row_1']);

    $schema->callAfterStateUpdated('mode', 'none');

    expect(array_keys($schema->getFlatFields()))->toBe(['mode', 'count', 'row_1', 'row_2', 'row_3']);
});

it('resolves a dotted field name from its container, as Filament does', function (): void {
    $schema = schema([
        TextInput::make('settings.mode')->key('mode'),
        TextInput::make('settings.url')->key('url'),
        TextInput::make('mode'),
    ])->fill(['settings' => ['mode' => 'redirect', 'url' => 'https://example.com'], 'mode' => 'root']);

    $get = componentByKey($schema, 'url')->makeGetUtility();

    expect($get('settings.mode'))->toBe('redirect')
        ->and($get('mode'))->toBe('root');
});

it('sends the container path with the frontend context of a layout component in a repeater item', function (): void {
    $repeater = Repeater::make('items')->schema([
        Text::make('x')->key('custom')->exposeContext(),
    ]);
    schema([$repeater])->fill(['items' => [['a' => 1]]]);

    expect(componentByKey($repeater->getItemSchema(0), 'custom')->getFrontendContext()['basePath'] ?? null)->toBe('items.0');
});
