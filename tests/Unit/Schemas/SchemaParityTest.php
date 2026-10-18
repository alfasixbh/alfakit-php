<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Actions\Action;
use AlfaSix\Alfakit\Forms\Components\Checkbox;
use AlfaSix\Alfakit\Forms\Components\Repeater;
use AlfaSix\Alfakit\Forms\Components\TextInput;
use AlfaSix\Alfakit\Infolists\Components\TextEntry;
use AlfaSix\Alfakit\Schemas\Components\Grid;
use AlfaSix\Alfakit\Schemas\Components\Section;
use AlfaSix\Alfakit\Schemas\Components\Text;
use AlfaSix\Alfakit\Schemas\Components\Wizard;
use AlfaSix\Alfakit\Schemas\Components\Wizard\Step;
use AlfaSix\Alfakit\Schemas\Schema;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Post;
use Carbon\CarbonInterface;
use Illuminate\Support\HtmlString;

enum ParityStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
}

enum ParityPriority: int
{
    case Low = 1;
    case High = 2;
}

// ---------------------------------------------------------------- Column span

it('applies an integer column span and start from lg up, like Filament', function (): void {
    $schema = Grid::make(3)->schema([
        Text::make('a')->columnSpan(2)->columnStart(2),
        Text::make('b')->columnSpan('full'),
        Text::make('c')->columnSpan(['default' => 2, 'md' => 1]),
        Text::make('d')->columnSpanFull(),
        Text::make('e')->columnSpan(fn (): int => 3),
    ])->toSchema();

    expect($schema['schema'][0]['columnSpan'])->toBe(['lg' => 2])
        ->and($schema['schema'][0]['columnStart'])->toBe(['lg' => 2])
        ->and($schema['schema'][1]['columnSpan'])->toBe(['default' => 'full'])
        ->and($schema['schema'][2]['columnSpan'])->toBe(['default' => 2, 'md' => 1])
        ->and($schema['schema'][3]['columnSpan'])->toBe(['default' => 'full'])
        ->and($schema['schema'][4]['columnSpan'])->toBe(['lg' => 3]);
});

// ---------------------------------------------------------------- Inline labels

it('cascades inline labels from the schema and layouts to fields and entries', function (): void {
    $schema = Schema::make()->inlineLabel()->components([
        TextInput::make('a'),
        Section::make('Plain')->inlineLabel(false)->schema([
            TextInput::make('b'),
            TextInput::make('c')->inlineLabel(),
            Grid::make()->schema([TextInput::make('d')]),
        ]),
        TextInput::make('e')->inlineLabel(false),
        TextEntry::make('f'),
    ]);

    $fields = $schema->getFlatComponents();
    $byName = collect($fields)->filter(fn ($c): bool => method_exists($c, 'getName'))->keyBy(fn ($c) => $c->getName());

    expect($byName['a']->hasInlineLabel())->toBeTrue()
        ->and($byName['b']->hasInlineLabel())->toBeFalse()
        ->and($byName['c']->hasInlineLabel())->toBeTrue()
        ->and($byName['d']->hasInlineLabel())->toBeFalse()
        ->and($byName['e']->hasInlineLabel())->toBeFalse()
        ->and($byName['f']->hasInlineLabel())->toBeTrue();

    $serialised = $schema->toSchema();

    expect($serialised[0]['inlineLabel'])->toBeTrue()
        ->and($serialised[1]['schema'][0])->not->toHaveKey('inlineLabel')
        ->and($serialised[1]['schema'][1]['inlineLabel'])->toBeTrue()
        ->and($serialised[3]['inlineLabel'])->toBeTrue();
});

it('has no inline labels by default and supports closures', function (): void {
    $schema = schema([
        Checkbox::make('compact'),
        Section::make('S')->inlineLabel(fn ($get): bool => (bool) $get('compact'))->schema([TextInput::make('name')]),
    ])->fill(['compact' => true]);

    $name = $schema->getFlatFields()['name'];

    expect($schema->getFlatFields()['compact']->hasInlineLabel())->toBeFalse()
        ->and($name->hasInlineLabel())->toBeTrue();

    $schema->setStateAt('compact', false);

    expect($name->hasInlineLabel())->toBeFalse();
});

it('cascades inline labels into repeater items', function (): void {
    $schema = schema([Section::make('S')->inlineLabel()->schema([Repeater::make('items')->schema([TextInput::make('name')])])])
        ->fill(['items' => [['name' => 'x']]]);

    $items = collect($schema->toSchema())->first()['schema'][0];

    expect($items['inlineLabel'])->toBeTrue()
        ->and(json_encode($items))->toContain('"inlineLabel":true');
});

// ---------------------------------------------------------------- Typed Get accessors

it('reads typed values with Get accessors', function (): void {
    $field = TextInput::make('probe');

    schema([
        $field,
        TextInput::make('name'),
        TextInput::make('qty'),
        TextInput::make('price'),
        TextInput::make('flag'),
        TextInput::make('tags'),
        TextInput::make('when'),
        TextInput::make('status'),
        TextInput::make('priority'),
        TextInput::make('empty'),
    ])->fill([
        'name' => 'Ann',
        'qty' => '12',
        'price' => '9.5',
        'flag' => 'false',
        'tags' => ['a'],
        'when' => '2026-10-13 10:00:00',
        'status' => 'published',
        'priority' => '2',
        'empty' => '',
    ]);

    $get = $field->makeGetUtility();

    expect($get->string('name'))->toBe('Ann')
        ->and($get->string('empty'))->toBe('')
        ->and($get->string('empty', isNullable: true))->toBeNull()
        ->and($get->integer('qty'))->toBe(12)
        ->and($get->integer('empty', isNullable: true))->toBeNull()
        ->and($get->integer('missing'))->toBe(0)
        ->and($get->float('price'))->toBe(9.5)
        ->and($get->boolean('flag'))->toBeFalse()
        ->and($get->boolean('name'))->toBeTrue()
        ->and($get->boolean('qty'))->toBeTrue()
        ->and($get->boolean('empty', isNullable: true))->toBeNull()
        ->and($get->array('tags'))->toBe(['a'])
        ->and($get->array('missing'))->toBe([])
        ->and($get->array('name', isNullable: true))->toBeNull()
        ->and($get->date('when'))->toBeInstanceOf(CarbonInterface::class)
        ->and($get->date('when')->format('Y-m-d'))->toBe('2026-10-13')
        ->and($get->date('empty', isNullable: true))->toBeNull()
        ->and($get->enum('status', ParityStatus::class))->toBe(ParityStatus::Published)
        ->and($get->enum('priority', ParityPriority::class))->toBe(ParityPriority::High)
        ->and($get->enum('name', ParityStatus::class))->toBeNull()
        ->and($get->enum('empty', ParityStatus::class, isNullable: true))->toBeNull()
        ->and($get->filled('name'))->toBeTrue()
        ->and($get->filled('empty'))->toBeFalse()
        ->and($get->blank('empty'))->toBeTrue()
        ->and($get->blank('missing'))->toBeTrue();
});

it('resolves typed Get accessors relative to the field and absolutely', function (): void {
    $field = TextInput::make('qty');

    schema([Repeater::make('lines')->schema([$field])])->fill(['total' => '3']);

    // The template field is not in an item, so only absolute paths make sense here.
    expect($field->makeGetUtility()->integer('total', isAbsolute: true))->toBe(3);
});

// ---------------------------------------------------------------- $rawState

it('injects $rawState into field and entry closures', function (): void {
    $post = Post::query()->create(['title' => 'Hello', 'slug' => 'hello-raw', 'is_published' => 1, 'rating' => 4]);

    $field = TextInput::make('title')->helperText(fn (mixed $rawState): string => "raw: {$rawState}");
    schema([$field])->fill(['title' => 'Typed']);

    $entry = TextEntry::make('is_published')->formatStateUsing(fn (mixed $state, mixed $rawState): string => var_export($state, true).'/'.var_export($rawState, true));
    $relationEntry = TextEntry::make('missing')->formatStateUsing(fn (mixed $rawState): string => var_export($rawState, true));
    Schema::make()->record($post)->components([$entry, $relationEntry]);

    expect($field->toSchema()['helperText'])->toContain('raw: Typed')
        ->and($entry->toSchema()['state'])->toBe('true/1')
        ->and($relationEntry->toSchema()['state'])->toBe('NULL');
});

// ---------------------------------------------------------------- Wizard actions

it('serialises wizard actions, completed icons and the step query-string key', function (): void {
    $wizard = Wizard::make([
        Step::make('One')->schema([TextInput::make('a')]),
        Step::make('Two')->completedIcon('star')->schema([TextInput::make('b')]),
    ])
        ->completedIcon('check-circle')
        ->persistStepInQueryString('order-step')
        ->nextAction(fn (Action $action) => $action->label('Continue')->icon('arrow-right'))
        ->previousAction(function (Action $action): void {
            $action->hidden();
        })
        ->submitAction(Action::make('place')->label('Place order')->action(fn () => null));

    schema([$wizard]);
    $schema = $wizard->toSchema();

    expect($schema['nextAction'])->toMatchArray(['type' => 'Action', 'name' => 'next', 'label' => 'Continue', 'icon' => 'arrow-right'])
        ->and($schema['nextActionLabel'])->toBe('Continue')
        ->and($schema['previousAction'])->toBeFalse()
        ->and($schema['submitAction'])->toMatchArray(['name' => 'place', 'label' => 'Place order', 'hasAction' => true])
        ->and($schema['completedIcon'])->toBe('check-circle')
        ->and($schema['schema'][1]['completedIcon'])->toBe('star')
        ->and($schema['schema'][0])->not->toHaveKey('completedIcon')
        ->and($schema['persistStepInQueryString'])->toBe('order-step')
        ->and(array_map(fn (Action $action): string => $action->getName(), $wizard->getAllActions()))->toBe(['place']);
});

it('keeps the default wizard buttons and accepts a boolean step persistence', function (): void {
    $plain = Wizard::make([Step::make('One')]);
    $persisted = Wizard::make([Step::make('One')])->persistStepInQueryString();
    $legacy = Wizard::make([Step::make('One')])->persistStepInQueryString(true);
    $off = Wizard::make([Step::make('One')])->persistStepInQueryString(false);

    schema([$plain, $persisted, $legacy, $off]);

    expect($plain->toSchema())->not->toHaveKeys(['nextAction', 'previousAction', 'submitAction', 'submitActionHtml', 'persistStepInQueryString', 'completedIcon'])
        ->and($plain->toSchema()['nextActionLabel'])->toBe(__('alfakit::forms.wizard.next'))
        ->and($persisted->toSchema()['persistStepInQueryString'])->toBe('step')
        ->and($legacy->toSchema()['persistStepInQueryString'])->toBe('step')
        ->and($off->toSchema())->not->toHaveKey('persistStepInQueryString')
        ->and($plain->getAllActions())->toBe([]);
});

it('renders Htmlable submit content and hides unauthorised submit actions', function (): void {
    $html = Wizard::make([Step::make('One')])->submitAction(new HtmlString('<button type="submit">Send</button>'));
    $denied = Wizard::make([Step::make('One')])->submitAction(Action::make('place')->authorize(false)->action(fn () => null));
    $fromClosure = Wizard::make([Step::make('One')])->submitAction(fn (): Action => Action::make('dynamic'));

    schema([$html, $denied, $fromClosure]);

    expect($html->toSchema()['submitActionHtml'])->toBe('<button type="submit">Send</button>')
        ->and($html->getAllActions())->toBe([])
        ->and($denied->toSchema())->not->toHaveKey('submitAction')
        ->and($fromClosure->toSchema()['submitAction']['name'])->toBe('dynamic');
});
