<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Forms\Components\DateTimePicker;
use AlfaSix\Alfakit\Forms\Components\KeyValue;
use AlfaSix\Alfakit\Forms\Components\Select;
use AlfaSix\Alfakit\Forms\Components\Textarea;
use AlfaSix\Alfakit\Forms\Components\TextInput;
use AlfaSix\Alfakit\Forms\Components\Toggle;
use AlfaSix\Alfakit\Schemas\Components\Utilities\Get;
use AlfaSix\Alfakit\Schemas\Schema;
use AlfaSix\Alfakit\Tests\Fixtures\FormsB\Models\Address;
use AlfaSix\Alfakit\Tests\Fixtures\FormsB\Models\Article;
use AlfaSix\Alfakit\Tests\Fixtures\FormsB\Models\Tables;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Post;
use AlfaSix\Alfakit\Tests\Fixtures\Schemas\AddressFields;

/*
 * Reusable schema fragments (`Fragment`) and `Schema::fromModel()`.
 */

it('drops a fragment into a schema as an invisible group', function (): void {
    $schema = schema([TextInput::make('name'), AddressFields::make()])->fill();
    $payload = $schema->toPayload();

    expect($payload['components'][1]['type'])->toBe('Group')
        ->and(array_column($payload['components'][1]['schema'], 'statePath'))->toBe(['street', 'city'])
        ->and($schema->getRawState())->toBe(['name' => null, 'street' => null, 'city' => null])
        ->and(array_keys($schema->getFlatFields()))->toBe(['name', 'street', 'city']);
});

it('prefixes the fragment state with statePath()', function (): void {
    $schema = schema([
        AddressFields::make()->statePath('billing'),
        AddressFields::make(withCity: false)->statePath('shipping'),
    ])->fill();

    expect($schema->getRawState())->toBe(['billing' => ['street' => null, 'city' => null], 'shipping' => ['street' => null]])
        ->and($schema->toPayload()['components'][0]['schema'][0]['statePath'])->toBe('billing.street')
        ->and(array_keys($schema->getFlatFields()))->toBe(['billing.street', 'billing.city', 'shipping.street']);

    $schema->applyClientState(['billing' => ['street' => 'Main St', 'city' => 'Manama'], 'shipping' => ['street' => 'Second St']]);

    expect($schema->getState())->toBe(['billing' => ['street' => 'Main St', 'city' => 'Manama'], 'shipping' => ['street' => 'Second St']]);
});

it('edits a related record with relationship()', function (): void {
    Tables::create();
    $address = Address::query()->create(['street' => 'Main St', 'city' => 'Bahrain']);
    $article = Article::query()->create(['title' => 'Hello', 'address_id' => $address->id]);

    $schema = schema([TextInput::make('title'), AddressFields::make()->relationship('address')], record: $article, operation: 'edit')->fill();

    expect($schema->toPayload()['state']['address'])->toBe(['street' => 'Main St', 'city' => 'Bahrain']);

    $schema->applyClientState(['title' => 'Hello', 'address' => ['street' => 'New St', 'city' => 'Riffa']]);
    $article->fill($schema->getState())->save();
    $schema->saveRelationships();

    expect($address->fresh()->street)->toBe('New St')
        ->and($article->fresh()->getAttributes())->not->toHaveKey('address');
});

it('hides a fragment like any component', function (): void {
    $schema = schema([Toggle::make('ships'), AddressFields::make()->statePath('shipping')->visible(fn (Get $get): bool => (bool) $get('ships'))])->fill();

    expect(array_keys($schema->getFlatFields()))->toBe(['ships'])
        ->and(count($schema->toPayload()['components']))->toBe(1);

    $schema->applyClientState(['ships' => true, 'shipping' => ['street' => 'Main St', 'city' => null]]);

    expect(array_keys($schema->getFlatFields()))->toBe(['ships', 'shipping.street', 'shipping.city']);
});

it('infers fields for a model from casts and columns', function (): void {
    $schema = Schema::fromModel(Post::class);
    $fields = $schema->getComponents();
    $byName = collect($fields)->keyBy(fn ($field) => $field->getName());

    expect($schema->getModel())->toBe(Post::class)
        ->and($byName->keys()->all())->toBe(['author_id', 'category_id', 'title', 'slug', 'content', 'is_published', 'published_at', 'rating', 'meta', 'images', 'tags_list', 'status'])
        ->and($byName['author_id'])->toBeInstanceOf(Select::class)
        ->and($byName['author_id']->getRelationshipName())->toBe('author')
        ->and($byName['category_id']->getRelationshipName())->toBe('category')
        ->and($byName['title'])->toBeInstanceOf(TextInput::class)
        ->and($byName['title']->isRequired())->toBeTrue()
        ->and($byName['slug']->isRequired())->toBeTrue()
        ->and($byName['content'])->toBeInstanceOf(Textarea::class)
        ->and($byName['content']->isRequired())->toBeFalse()
        ->and($byName['is_published'])->toBeInstanceOf(Toggle::class)
        ->and($byName['published_at'])->toBeInstanceOf(DateTimePicker::class)
        ->and($byName['rating'])->toBeInstanceOf(TextInput::class)
        ->and($byName['meta'])->toBeInstanceOf(KeyValue::class)
        // `status` has a default, so it is not required.
        ->and($byName['status']->isRequired())->toBeFalse();
});

it('lets you narrow and tweak the inferred fields', function (): void {
    $schema = Schema::fromModel(Post::class, only: ['title', 'content', 'is_published']);

    expect(array_map(fn ($field) => $field->getName(), $schema->getComponents()))->toBe(['title', 'content', 'is_published']);

    $schema->getComponent('title')?->maxLength(120);
    $schema->components([...$schema->getComponents(), Toggle::make('featured')]);

    expect($schema->toSchema()[0]['maxLength'])->toBe(120)
        ->and(array_keys($schema->fill()->getRawState()))->toBe(['title', 'content', 'is_published', 'featured']);

    expect(array_map(fn ($field) => $field->getName(), Schema::fromModel(Post::class, except: ['slug', 'meta', 'images', 'tags_list'])->getComponents()))
        ->toBe(['author_id', 'category_id', 'title', 'content', 'is_published', 'published_at', 'rating', 'status']);
});

it('rejects classes that are not models', function (): void {
    expect(fn () => Schema::fromModel(stdClass::class))->toThrow(InvalidArgumentException::class);
});
