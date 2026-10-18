<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Forms\Components\MarkdownEditor;
use AlfaSix\Alfakit\Forms\Components\RichEditor;
use AlfaSix\Alfakit\Forms\Components\Textarea;
use AlfaSix\Alfakit\Forms\Components\TextInput;
use AlfaSix\Alfakit\Schemas\Components\Utilities\Set;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Post;
use AlfaSix\Alfakit\Tests\Fixtures\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Stands in for a model using spatie/laravel-translatable's HasTranslations (the package
 * isn't installed here): only the two methods Alfakit relies on.
 */
class TranslatableSpatieStylePost extends Model
{
    protected $table = 'posts';

    protected $guarded = [];

    /** @var array<string, array<string, string>> */
    public static array $stored = [];

    public function isTranslatableAttribute(string $key): bool
    {
        return $key === 'title';
    }

    /**
     * @return array<string, string>
     */
    public function getTranslations(string $key): array
    {
        return self::$stored[$key] ?? [];
    }
}

function translatablePost(array $attributes = []): Post
{
    $user = User::query()->firstOrCreate(['email' => 'translator@example.com'], ['name' => 'T', 'password' => 'password']);

    return Post::query()->create(['title' => 'T', 'slug' => 'slug-'.uniqid(), 'author_id' => $user->id, ...$attributes]);
}

function translatableValidationErrors(Closure $callback): array
{
    try {
        $callback();
    } catch (ValidationException $exception) {
        return $exception->errors();
    }

    return [];
}

it('serialises the languages and moves required to the required locales', function (): void {
    $field = TextInput::make('meta')->required()->translatable(locales: ['en', 'ar' => 'Arabic']);
    $form = schema([$field])->fill();
    $schema = $form->toSchema()[0];

    expect($schema['translatable']['locales'])->toBe([
        ['code' => 'en', 'tag' => 'en', 'label' => $schema['translatable']['locales'][0]['label'], 'direction' => 'ltr', 'required' => true],
        ['code' => 'ar', 'tag' => 'ar', 'label' => 'Arabic', 'direction' => 'rtl', 'required' => null],
    ])
        ->and($schema['translatable']['defaultLocale'])->toBe('en')
        ->and($schema)->not->toHaveKey('required')
        ->and($form->getRawState())->toBe(['meta' => ['en' => null, 'ar' => null]]);

    expect(TextInput::make('plain')->toSchema())->not->toHaveKey('translatable');
});

it('loads and saves every language of an array-cast attribute', function (): void {
    $post = translatablePost(['meta' => ['en' => 'Hello', 'fr' => 'Bonjour']]);
    $form = schema([TextInput::make('meta')->translatable(locales: ['en', 'ar'])], $post, operation: 'edit')->fill();

    expect($form->getRawState()['meta'])->toBe(['en' => 'Hello', 'ar' => null, 'fr' => 'Bonjour']);

    // The browser edits the panel's languages; a language it invents is ignored, and one
    // the field doesn't edit (`fr`) keeps the record's value.
    $form->applyClientState(['meta' => ['en' => 'Hi', 'ar' => 'مرحبا', 'de' => 'Hallo', 'fr' => 'forged']]);

    expect($form->validate())->toBe(['meta' => ['en' => 'Hi', 'ar' => 'مرحبا', 'fr' => 'Bonjour']]);
});

it('stores a plain text column as a JSON string and reads legacy plain values', function (): void {
    $legacy = translatablePost(['content' => 'Old text']);
    $form = schema([Textarea::make('content')->translatable(locales: ['en', 'ar'])], $legacy, operation: 'edit')->fill();

    expect($form->getRawState()['content'])->toBe(['en' => 'Old text', 'ar' => null]);

    $form->applyClientState(['content' => ['en' => 'New', 'ar' => '']]);

    expect($form->validate())->toBe(['content' => '{"en":"New"}']);

    $legacy->update(['content' => '{"en":"New","ar":"جديد"}']);
    $form = schema([Textarea::make('content')->translatable(locales: ['en', 'ar'])], $legacy->refresh(), operation: 'edit')->fill();

    expect($form->getRawState()['content'])->toBe(['en' => 'New', 'ar' => 'جديد']);

    // Emptying every language stores null.
    $form->applyClientState(['content' => ['en' => '', 'ar' => null]]);

    expect($form->validate())->toBe(['content' => null]);
});

it('requires the default locale and applies other rules to every language', function (): void {
    $form = schema([TextInput::make('meta')->required()->maxLength(5)->translatable(locales: ['en', 'ar'])], model: Post::class)->fill();
    $form->applyClientState(['meta' => ['en' => '', 'ar' => 'too long']]);

    $errors = translatableValidationErrors(fn () => $form->validate());

    expect(array_keys($errors))->toBe(['meta.en', 'meta.ar'])
        ->and($errors['meta.ar'][0])->toContain('('.AlfaSix\Alfakit\Localization\Locale::make('ar')->getLabel().')');

    $all = schema([TextInput::make('meta')->required()->requiredLocales(['en', 'ar'])->translatable(locales: ['en', 'ar'])], model: Post::class)->fill();
    $all->applyClientState(['meta' => ['en' => 'Hi', 'ar' => null]]);

    expect(array_keys(translatableValidationErrors(fn () => $all->validate())))->toBe(['meta.ar']);

    $notArray = schema([TextInput::make('meta')->translatable(locales: ['en'])], model: Post::class)->fill();
    $notArray->applyClientState(['meta' => 'a string']);

    expect(array_keys(translatableValidationErrors(fn () => $notArray->validate())))->toContain('meta');
});

it('loads every language from spatie-style models and sends emptied languages back', function (): void {
    $user = User::query()->create(['name' => 'S', 'email' => 'spatie@example.com', 'password' => 'password']);
    $post = TranslatableSpatieStylePost::query()->create(['title' => '{"en":"Hello","ar":"مرحبا"}', 'slug' => 's', 'author_id' => $user->id]);
    TranslatableSpatieStylePost::$stored = ['title' => ['en' => 'Hello', 'ar' => 'مرحبا']];

    $form = schema([TextInput::make('title')->translatable(locales: ['en', 'ar'])], $post, operation: 'edit')->fill();

    expect($form->getRawState()['title'])->toBe(['en' => 'Hello', 'ar' => 'مرحبا']);

    $form->applyClientState(['title' => ['en' => 'Hi', 'ar' => '']]);

    // An array (HasTranslations sets each language), with the emptied one included.
    expect($form->validate())->toBe(['title' => ['en' => 'Hi', 'ar' => null]]);
});

it('runs afterStateUpdated when one language changes', function (): void {
    $form = schema([
        TextInput::make('meta')->translatable(locales: ['en', 'ar'])->live()
            ->afterStateUpdated(fn (Set $set, array $state) => $set('slug', str($state['en'] ?? '')->slug()->toString())),
        TextInput::make('slug'),
    ], model: Post::class)->fill();

    $form->applyClientState(['meta' => ['en' => 'Hello World', 'ar' => null], 'slug' => null]);
    $form->callAfterStateUpdated('meta.en');

    expect($form->getRawState()['slug'])->toBe('hello-world');
});

it('translates rich text and Markdown per language', function (): void {
    $form = schema([RichEditor::make('meta')->translatable(locales: ['en', 'ar'])], model: Post::class)->fill();
    $form->applyClientState(['meta' => ['en' => '<p>Hi</p>', 'ar' => '<p></p>']]);

    expect($form->validate())->toBe(['meta' => ['en' => '<p>Hi</p>']]);

    // The Markdown editor renders one HTML document per language.
    $markdown = schema([MarkdownEditor::make('meta')->translatable(locales: ['en', 'ar'])], model: Post::class)->fill(['meta' => ['en' => '# Hi']]);

    expect($markdown->toSchema()[0]['html'])->toBe(['en' => "<h1>Hi</h1>\n", 'ar' => null]);
});

it('defaults to the panel locales', function (): void {
    alfakit()->getPanel('admin')->locales(['en', 'ar']);

    try {
        expect(array_keys(TextInput::make('meta')->translatable()->getTranslatableLocales()))->toBe(['en', 'ar']);
    } finally {
        alfakit()->getPanel('admin')->locales([]);
    }
});
