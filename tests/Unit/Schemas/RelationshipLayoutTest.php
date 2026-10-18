<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Forms\Components\TextInput;
use AlfaSix\Alfakit\Infolists\Components\TextEntry;
use AlfaSix\Alfakit\Schemas\Components\Fieldset;
use AlfaSix\Alfakit\Schemas\Components\Group;
use AlfaSix\Alfakit\Schemas\Components\Section;
use AlfaSix\Alfakit\Tests\Fixtures\FormsB\Models\Address;
use AlfaSix\Alfakit\Tests\Fixtures\FormsB\Models\Article;
use AlfaSix\Alfakit\Tests\Fixtures\FormsB\Models\ArticleSeo;
use AlfaSix\Alfakit\Tests\Fixtures\FormsB\Models\Image;
use AlfaSix\Alfakit\Tests\Fixtures\FormsB\Models\Tables;
use Illuminate\Validation\ValidationException;

beforeEach(fn () => Tables::create());

function articleForm(): array
{
    return [
        TextInput::make('title')->required(),
        Section::make('SEO')->relationship('seo')->schema([
            TextInput::make('meta_title')->required()->unique(ignoreRecord: true),
            TextInput::make('meta_description'),
        ]),
        Fieldset::make('Address')->relationship('address')->schema([
            TextInput::make('street')->required(),
            TextInput::make('city'),
        ]),
        Group::make([TextInput::make('url')])->relationship('image', condition: fn (array $state): bool => filled($state['url'] ?? null)),
    ];
}

it('loads related records into nested state', function (): void {
    $address = Address::query()->create(['street' => 'Main St', 'city' => 'Bahrain']);
    $article = Article::query()->create(['title' => 'Hello', 'address_id' => $address->id]);
    ArticleSeo::query()->create(['article_id' => $article->id, 'meta_title' => 'Meta', 'meta_description' => 'Desc', 'secret' => 'hidden']);

    $schema = schema(articleForm(), record: $article, operation: 'edit')->fill();
    $payload = $schema->toPayload();

    expect($payload['state']['title'])->toBe('Hello')
        ->and($payload['state']['seo'])->toBe(['meta_title' => 'Meta', 'meta_description' => 'Desc'])
        ->and($payload['state']['address'])->toBe(['street' => 'Main St', 'city' => 'Bahrain'])
        ->and($payload['state']['image'])->toBe(['url' => null])
        // Fields inside are keyed and named by their nested path.
        ->and($payload['components'][1]['schema'][0]['statePath'])->toBe('seo.meta_title');
});

it('creates related records after the owner and keeps them out of its data', function (): void {
    $schema = schema(articleForm(), model: Article::class)->fill();

    $schema->applyClientState([
        'title' => 'New',
        'seo' => ['meta_title' => 'New meta', 'meta_description' => null],
        'address' => ['street' => 'Second St', 'city' => 'Manama'],
        'image' => ['url' => ''],
    ]);

    $data = $schema->validate();

    expect($data)->toBe(['title' => 'New']);

    $article = Article::query()->create($data);
    $schema->record($article)->saveRelationships();

    $article->refresh();

    expect($article->seo?->meta_title)->toBe('New meta')
        ->and($article->address?->street)->toBe('Second St')
        // The condition failed: no image was created.
        ->and($article->image)->toBeNull();
});

it('updates existing related records and removes them when the condition fails', function (): void {
    $article = Article::query()->create(['title' => 'Hello']);
    $seo = ArticleSeo::query()->create(['article_id' => $article->id, 'meta_title' => 'Meta']);
    Image::query()->create(['imageable_type' => Article::class, 'imageable_id' => $article->id, 'url' => 'a.png']);

    $schema = schema(articleForm(), record: $article, operation: 'edit')->fill();

    $schema->applyClientState([
        'title' => 'Hello',
        // The meta title is unique, ignoring the related record itself.
        'seo' => ['meta_title' => 'Meta', 'meta_description' => 'Now described'],
        'address' => ['street' => 'Third St', 'city' => null],
        'image' => ['url' => null],
    ]);

    $schema->validate();
    $schema->saveRelationships();

    expect($seo->fresh()->meta_description)->toBe('Now described')
        ->and(ArticleSeo::query()->count())->toBe(1)
        ->and($article->fresh()->address?->street)->toBe('Third St')
        ->and(Image::query()->count())->toBe(0);
});

it('validates nested fields against the related table', function (): void {
    $other = Article::query()->create(['title' => 'Other']);
    ArticleSeo::query()->create(['article_id' => $other->id, 'meta_title' => 'Taken']);

    $schema = schema(articleForm(), model: Article::class)->fill();
    $schema->applyClientState(['title' => 'X', 'seo' => ['meta_title' => 'Taken'], 'address' => ['street' => '']]);

    try {
        $schema->validate();
        $this->fail('Validation should fail.');
    } catch (ValidationException $exception) {
        expect(array_keys($exception->errors()))->toBe(['seo.meta_title', 'address.street']);
    }
});

it('does not save hidden or disabled relationship components', function (): void {
    $article = Article::query()->create(['title' => 'Hello']);

    $schema = schema([
        Section::make('SEO')->relationship('seo')->hidden()->schema([TextInput::make('meta_title')]),
        Fieldset::make('Address')->relationship('address')->disabled()->schema([TextInput::make('street')]),
    ], record: $article, operation: 'edit')->fill();

    $schema->applyClientState(['seo' => ['meta_title' => 'Forged'], 'address' => ['street' => 'Forged']]);
    $schema->validate();
    $schema->saveRelationships();

    expect(ArticleSeo::query()->count())->toBe(0)
        ->and(Address::query()->count())->toBe(0)
        // Hidden state never reaches the browser.
        ->and($schema->toPayload()['state']['seo'] ?? [])->toBe([]);
});

it('mutates relationship data before fill, create and save', function (): void {
    $article = Article::query()->create(['title' => 'Hello']);

    $section = fn () => Section::make('SEO')->relationship('seo')
        ->mutateRelationshipDataBeforeFillUsing(fn (array $data): array => ['meta_title' => strtoupper($data['meta_title'])])
        ->mutateRelationshipDataBeforeCreateUsing(fn (array $data): array => [...$data, 'secret' => 'created'])
        ->mutateRelationshipDataBeforeSaveUsing(fn (array $data): array => [...$data, 'secret' => 'saved'])
        ->schema([TextInput::make('meta_title')]);

    $schema = schema([$section()], record: $article, operation: 'edit')->fill();
    $schema->applyClientState(['seo' => ['meta_title' => 'first']]);
    $schema->validate();
    $schema->saveRelationships();

    expect(ArticleSeo::query()->sole()->secret)->toBe('created');

    $schema = schema([$section()], record: $article->fresh(), operation: 'edit')->fill();

    expect($schema->getRawState()['seo'])->toBe(['meta_title' => 'FIRST']);

    $schema->applyClientState(['seo' => ['meta_title' => 'second']]);
    $schema->validate();
    $schema->saveRelationships();

    expect(ArticleSeo::query()->sole()->only(['meta_title', 'secret']))->toBe(['meta_title' => 'second', 'secret' => 'saved']);
});

it('shows related records in infolists', function (): void {
    $article = Article::query()->create(['title' => 'Hello']);
    ArticleSeo::query()->create(['article_id' => $article->id, 'meta_title' => 'Meta']);

    $components = schema([Section::make('SEO')->relationship('seo')->schema([TextEntry::make('meta_title')])], record: $article, operation: 'view')->toSchema();

    expect($components[0]['schema'][0]['state'])->toBe('Meta');
});
