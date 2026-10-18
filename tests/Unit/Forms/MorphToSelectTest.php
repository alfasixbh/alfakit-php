<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Forms\Components\MorphToSelect;
use AlfaSix\Alfakit\Forms\Components\MorphToSelect\Type;
use AlfaSix\Alfakit\Forms\Components\Repeater;
use AlfaSix\Alfakit\Forms\Components\Select;
use AlfaSix\Alfakit\Forms\Components\TextInput;
use AlfaSix\Alfakit\Forms\Components\ToggleButtons;
use AlfaSix\Alfakit\Schemas\Components\Group;
use AlfaSix\Alfakit\Tests\Fixtures\FormsMorph\Note;
use AlfaSix\Alfakit\Tests\Fixtures\FormsMorph\Product;
use AlfaSix\Alfakit\Tests\Fixtures\FormsMorph\Tables;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Post;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Project;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Team;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    Tables::create();
    Relation::$morphMap = [];
});

afterEach(function (): void {
    Relation::$morphMap = [];
});

function notableSelect(): MorphToSelect
{
    return MorphToSelect::make('notable')->types([
        Type::make(Post::class)->titleAttribute('title'),
        Type::make(Product::class)
            ->getOptionLabelFromRecordUsing(fn (Product $record): string => "#{$record->id} {$record->name}")
            ->modifyOptionsQueryUsing(fn (Builder $query) => $query->where('active', true)),
    ]);
}

/**
 * @return array<string, string>
 */
function morphErrors(AlfaSix\Alfakit\Schemas\Schema $schema): array
{
    try {
        $schema->validate();
    } catch (ValidationException $e) {
        return array_map(static fn (array $messages): string => $messages[0], $e->errors());
    }

    return [];
}

it('fills both columns from the record and renders a fieldset with two selects', function (): void {
    $post = Post::query()->create(['title' => 'Hello', 'slug' => 'hello']);
    Post::query()->create(['title' => 'Another', 'slug' => 'another']);
    $note = Note::query()->create(['body' => 'n', 'notable_type' => Post::class, 'notable_id' => $post->id]);

    $schema = schema([notableSelect()], record: $note, operation: 'edit')->fill();
    $payload = $schema->toPayload();
    $component = $payload['components'][0];

    expect($payload['state'])->toMatchArray(['notable_type' => Post::class, 'notable_id' => $post->id])
        ->and($component['type'])->toBe('Fieldset')
        ->and($component['label'])->toBe('Notable')
        ->and($component['schema'][0]['statePath'])->toBe('notable_type')
        ->and(array_column($component['schema'][0]['options'], 'label'))->toBe(['Post', 'Product'])
        ->and($component['schema'][0]['live'])->not->toBeNull()
        ->and($component['schema'][1]['statePath'])->toBe('notable_id')
        ->and($component['schema'][1]['label'])->toBe('Post')
        ->and(array_column($component['schema'][1]['options'], 'label'))->toBe(['Another', 'Hello']);
});

it('hides the key select until a type is chosen and clears the key when the type changes', function (): void {
    $product = Product::query()->create(['name' => 'Pen']);
    $post = Post::query()->create(['title' => 'Hello', 'slug' => 'hello']);
    $note = Note::query()->create(['notable_type' => Post::class, 'notable_id' => $post->id]);

    $schema = schema([notableSelect()], model: Note::class)->fill();

    expect($schema->toPayload()['components'][0]['schema'])->toHaveCount(1);

    // A live refresh choosing a type: the key select of that type appears.
    $schema->applyClientState(['notable_type' => Product::class, 'notable_id' => null]);
    $schema->callAfterStateUpdated('notable_type');
    $keySelect = $schema->toPayload()['components'][0]['schema'][1];

    expect($keySelect['label'])->toBe('Product')
        ->and($keySelect['options'])->toBe([['value' => (string) $product->id, 'label' => "#{$product->id} Pen"]]);

    // Switching the type on an edit form resets the key.
    $schema = schema([notableSelect()], record: $note, operation: 'edit')->fill();
    $schema->applyClientState(['notable_type' => Product::class, 'notable_id' => $post->id]);
    $schema->callAfterStateUpdated('notable_type', Post::class);

    expect($schema->getRawState()['notable_id'])->toBeNull();
});

it('saves the type and key and validates both', function (): void {
    $active = Product::query()->create(['name' => 'Pen']);
    $inactive = Product::query()->create(['name' => 'Old', 'active' => false]);

    $schema = schema([TextInput::make('body'), notableSelect()->required()], model: Note::class)->fill();

    $schema->applyClientState(['body' => 'x', 'notable_type' => Product::class, 'notable_id' => $active->id]);
    $data = $schema->validate();

    expect($data)->toBe(['body' => 'x', 'notable_type' => Product::class, 'notable_id' => $active->id]);

    $note = Note::query()->create($data);
    expect($note->notable?->is($active))->toBeTrue();

    // A record excluded by modifyOptionsQueryUsing, a missing record and a foreign type fail.
    $schema->applyClientState(['body' => 'x', 'notable_type' => Product::class, 'notable_id' => $inactive->id]);
    expect(morphErrors($schema))->toHaveKey('notable_id');

    $schema->applyClientState(['body' => 'x', 'notable_type' => Product::class, 'notable_id' => 999]);
    expect(morphErrors($schema))->toHaveKey('notable_id');

    $schema->applyClientState(['body' => 'x', 'notable_type' => Team::class, 'notable_id' => 1]);
    expect(morphErrors($schema))->toHaveKey('notable_type');

    // Required: the type is required (the key appears once a type is chosen).
    $schema->applyClientState(['body' => 'x', 'notable_type' => null, 'notable_id' => null]);
    expect(morphErrors($schema))->toHaveKey('notable_type');

    $schema->applyClientState(['body' => 'x', 'notable_type' => Product::class, 'notable_id' => null]);
    expect(morphErrors($schema))->toHaveKey('notable_id');
});

it('clears the key when the type is cleared', function (): void {
    $post = Post::query()->create(['title' => 'Hello', 'slug' => 'hello']);
    $note = Note::query()->create(['notable_type' => Post::class, 'notable_id' => $post->id]);

    $schema = schema([notableSelect()], record: $note, operation: 'edit')->fill();
    $schema->applyClientState(['notable_type' => null, 'notable_id' => $post->id]);

    expect($schema->validate())->toBe(['notable_type' => null, 'notable_id' => null]);
});

it('searches the chosen type and resolves the selected label', function (): void {
    Post::query()->create(['title' => 'Ada', 'slug' => 'ada']);
    $alan = Post::query()->create(['title' => 'Alan', 'slug' => 'alan']);
    $note = Note::query()->create(['notable_type' => Post::class, 'notable_id' => $alan->id]);

    $schema = schema([notableSelect()->searchable()], record: $note, operation: 'edit')->fill();
    $key = $schema->getFieldByStatePath('notable_id');

    expect($key)->toBeInstanceOf(Select::class)
        ->and($key->getOptions())->toBe([])
        ->and($key->getSearchResults('ad'))->toBe(['1' => 'Ada'])
        ->and($key->toSchema()['selectedLabels'])->toBe([(string) $alan->id => 'Alan']);

    $schema = schema([notableSelect()->searchable()->preload()], record: $note, operation: 'edit')->fill();

    expect($schema->getFieldByStatePath('notable_id')?->getOptions())->toHaveCount(2);
});

it('stores the morph map alias and accepts records saved with the class name', function (): void {
    Relation::morphMap(['post' => Post::class, 'product' => Product::class]);
    $post = Post::query()->create(['title' => 'Hello', 'slug' => 'hello']);
    $legacy = Note::query()->create(['notable_type' => Post::class, 'notable_id' => $post->id]);

    $schema = schema([notableSelect()], record: $legacy, operation: 'edit')->fill();

    expect($schema->getRawState()['notable_type'])->toBe('post')
        ->and(array_column($schema->toPayload()['components'][0]['schema'][0]['options'], 'value'))->toBe(['post', 'product']);

    $schema->applyClientState(['notable_type' => 'post', 'notable_id' => $post->id]);
    $legacy->update($schema->validate());

    expect($legacy->fresh()?->notable_type)->toBe('post')
        ->and($legacy->fresh()?->notable?->is($post))->toBeTrue();
});

it('limits options and the key rule to the current tenant', function (): void {
    $alpha = Team::query()->create(['name' => 'Alpha', 'slug' => 'alpha']);
    $beta = Team::query()->create(['name' => 'Beta', 'slug' => 'beta']);
    $mine = Project::query()->create(['team_id' => $alpha->id, 'name' => 'Alpha project']);
    $theirs = Project::query()->create(['team_id' => $beta->id, 'name' => 'Beta project']);

    alfakit()->setCurrentPanel(alfakit()->getPanel('app'));
    alfakit()->setTenant($alpha);

    $field = MorphToSelect::make('notable')->types([Type::make(Project::class)->titleAttribute('name')]);
    $schema = schema([$field], model: Note::class)->fill();
    $schema->applyClientState(['notable_type' => Project::class, 'notable_id' => $theirs->id]);

    expect($schema->getFieldByStatePath('notable_id')?->getOptions())->toBe([(string) $mine->id => 'Alpha project'])
        ->and(morphErrors($schema))->toHaveKey('notable_id');

    $schema->applyClientState(['notable_type' => Project::class, 'notable_id' => $mine->id]);

    expect($schema->validate())->toBe(['notable_type' => Project::class, 'notable_id' => $mine->id]);
});

it('supports toggle buttons, a plain group and select modifiers', function (): void {
    $field = notableSelect()
        ->typeSelectToggleButtons()
        ->contained(false)
        ->modifyTypeSelectUsing(fn (ToggleButtons $toggleButtons): ToggleButtons => $toggleButtons->grouped())
        ->modifyKeySelectUsing(fn (Select $select): Select => $select->helperText('Pick one'));

    $schema = schema([$field], model: Note::class)->fill();
    $schema->applyClientState(['notable_type' => Post::class]);
    $component = $schema->toPayload()['components'][0];

    expect($component['type'])->toBe('Group')
        ->and($component)->not->toHaveKey('label')
        ->and($component['schema'][0]['type'])->toBe('ToggleButtons')
        ->and($component['schema'][1]['helperText'])->toBe('Pick one');
});

it('works inside repeater items, each with its own type', function (): void {
    $post = Post::query()->create(['title' => 'Hello', 'slug' => 'hello']);
    $product = Product::query()->create(['name' => 'Pen']);

    $repeater = Repeater::make('links')->schema([notableSelect()]);
    $schema = schema([$repeater])->fill();
    $schema->applyClientState(['links' => [
        ['notable_type' => Post::class, 'notable_id' => $post->id],
        ['notable_type' => Product::class, 'notable_id' => $product->id],
    ]]);

    $items = $repeater->toSchema()['items'];

    expect($items[0]['schema'][0]['schema'][1]['label'])->toBe('Post')
        ->and($items[1]['schema'][0]['schema'][1]['label'])->toBe('Product')
        ->and($schema->validate()['links'])->toBe([
            ['notable_type' => Post::class, 'notable_id' => $post->id],
            ['notable_type' => Product::class, 'notable_id' => $product->id],
        ]);
});

it('edits and creates a MorphTo record through a layout relationship', function (): void {
    $form = fn (): array => [
        TextInput::make('body'),
        Group::make([TextInput::make('name')->required()])->relationship('notable', relatedModel: Product::class),
    ];

    $schema = schema($form(), model: Note::class)->fill();
    $schema->applyClientState(['body' => 'new', 'notable' => ['name' => 'Pen']]);
    $note = Note::query()->create($schema->validate());
    $schema->record($note)->saveRelationships();

    $product = $note->fresh()?->notable;

    expect($product)->toBeInstanceOf(Product::class)
        ->and($product?->name)->toBe('Pen');

    $schema = schema($form(), record: $note->fresh(), operation: 'edit')->fill();

    expect($schema->getRawState()['notable'])->toBe(['name' => 'Pen']);

    $schema->applyClientState(['body' => 'new', 'notable' => ['name' => 'Pencil']]);
    $schema->validate();
    $schema->saveRelationships();

    expect($product?->fresh()?->name)->toBe('Pencil')
        ->and(Product::query()->count())->toBe(1);
});
