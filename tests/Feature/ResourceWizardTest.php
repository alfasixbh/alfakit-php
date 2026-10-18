<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Commands\Support\GeneratedCode;
use AlfaSix\Alfakit\Commands\Support\ModelInspector;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Comment;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Post;
use Illuminate\Filesystem\Filesystem;

/*
 * The resource wizard (`alfakit:generate`, `make:alfakit-resource --wizard`): model
 * introspection, smart defaults per column type, explicit choices for CI and relation
 * managers. Each test generates into its own temporary directory.
 */

beforeEach(function (): void {
    $this->sandbox = sys_get_temp_dir().'/alfakit-wizard-'.uniqid();
    config(['alfakit.file_generation.path' => $this->sandbox.'/app/Alfakit', 'alfakit.file_generation.namespace' => 'App\\Alfakit']);
});

afterEach(function (): void {
    (new Filesystem)->deleteDirectory($this->sandbox);
});

function wizardFile(string $sandbox, string $suffix): string
{
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($sandbox, FilesystemIterator::SKIP_DOTS)) as $file) {
        if (str_ends_with(str_replace('\\', '/', $file->getPathname()), $suffix)) {
            return (string) file_get_contents($file->getPathname());
        }
    }

    throw new RuntimeException("{$suffix} was not generated.");
}

it('introspects columns, foreign keys, casts and relationships', function (): void {
    $info = ModelInspector::inspect(Post::class);
    $kinds = array_column($info['columns'], 'kind', 'name');

    expect($info['softDeletes'])->toBeTrue()
        ->and($kinds)->toMatchArray([
            'id' => 'key',
            'author_id' => 'relation',
            'category_id' => 'relation',
            'title' => 'string',
            'content' => 'richtext',
            'is_published' => 'boolean',
            'published_at' => 'datetime',
            'rating' => 'integer',
            'meta' => 'json',
            'images' => 'json',
        ])
        ->and(collect($info['columns'])->firstWhere('name', 'slug')['unique'])->toBeTrue()
        ->and(collect($info['columns'])->firstWhere('name', 'author_id')['relationship'])->toBe('author')
        ->and(array_column($info['relations'], 'type', 'name'))->toMatchArray([
            'author' => 'BelongsTo',
            'comments' => 'HasMany',
            'tags' => 'BelongsToMany',
        ]);
});

it('maps column kinds to components', function (string $name, string $type, ?string $cast, string $kind): void {
    expect(ModelInspector::kind($name, $type, $cast))->toBe($kind);
})->with([
    ['body', 'text', null, 'richtext'],
    ['notes', 'text', null, 'text'],
    ['is_active', 'tinyint', null, 'boolean'],
    ['settings', 'text', 'array', 'json'],
    ['born_on', 'date', null, 'date'],
    ['starts_at', 'time', null, 'time'],
    ['price', 'decimal', null, 'decimal'],
    ['email', 'varchar', null, 'email'],
    ['password', 'varchar', 'hashed', 'password'],
    ['avatar', 'varchar', null, 'image'],
    ['website_url', 'varchar', null, 'url'],
    ['name', 'varchar', null, 'string'],
]);

it('generates a resource with smart defaults without prompts', function (): void {
    $this->artisan('alfakit:generate', ['model' => Post::class, '--no-interaction' => true])->assertSuccessful();

    $code = wizardFile($this->sandbox, 'Resources/PostResource.php');

    expect($code)
        ->toContain("Select::make('author_id')")
        ->toContain("->relationship('author', 'name')")
        ->toContain("TextInput::make('slug')")
        ->toContain('->unique(ignoreRecord: true)')
        ->toContain("RichEditor::make('content')")
        ->toContain("Toggle::make('is_published')")
        ->toContain("DateTimePicker::make('published_at')")
        ->toContain("KeyValue::make('meta')")
        ->toContain("IconColumn::make('is_published')")
        ->toContain("TextColumn::make('author.name')")
        ->toContain("TernaryFilter::make('is_published')")
        ->toContain("SelectFilter::make('category_id')")
        // The model soft deletes: trashed filter and restore actions by default.
        ->toContain('TrashedFilter::make()')
        ->toContain('RestoreAction::make()')
        ->toContain('RelationManagers\\CommentsRelationManager::class')
        ->toContain('RelationManagers\\TagsRelationManager::class')
        ->not->toContain("TextColumn::make('content')")
        ->not->toContain('public static function infolist');

    expect(fn () => token_get_all($code, TOKEN_PARSE))->not->toThrow(ParseError::class);

    $tags = wizardFile($this->sandbox, 'PostResource/RelationManagers/TagsRelationManager.php');

    expect($tags)->toContain('AttachAction::make()')
        ->and(wizardFile($this->sandbox, 'PostResource/RelationManagers/CommentsRelationManager.php'))->toContain("protected static string \$relationship = 'comments';");
});

it('takes every choice as an option, for CI', function (): void {
    $this->artisan('alfakit:generate', [
        'model' => Post::class,
        '--form' => ['title,slug'],
        '--table' => ['title'],
        '--filters' => ['published_at'],
        '--infolist' => ['title,is_published'],
        '--relation-managers' => ['none'],
        '--no-soft-deletes' => true,
        '--no-interaction' => true,
    ])->assertSuccessful();

    $code = wizardFile($this->sandbox, 'Resources/PostResource.php');

    expect($code)
        ->toContain("TextInput::make('title')")
        ->not->toContain("Toggle::make('is_published')")
        ->toContain("Filter::make('published_at')")
        ->toContain("->whereDate('published_at', '>=', \$date)")
        ->toContain('public static function infolist(Schema $schema): Schema')
        ->toContain("IconEntry::make('is_published')")
        ->toContain("'view' => Pages\\ViewPost::route('/{record}')")
        ->not->toContain('TrashedFilter')
        ->not->toContain('RelationManagers');

    expect(wizardFile($this->sandbox, 'PostResource/Pages/ViewPost.php'))->toContain('class ViewPost extends ViewRecord');
});

it('writes sorted imports without unused ones', function (): void {
    $this->artisan('alfakit:generate', ['model' => Comment::class, '--table' => ['author_name'], '--form' => ['author_name'], '--filters' => ['none'], '--no-interaction' => true])->assertSuccessful();

    $code = wizardFile($this->sandbox, 'Resources/CommentResource.php');
    preg_match_all('/^use ([^;]+);$/m', $code, $matches);
    $sorted = $matches[1];
    usort($sorted, static fn (string $a, string $b): int => strcasecmp(str_replace('\\', ' ', $a), str_replace('\\', ' ', $b)));

    expect($matches[1])->toBe($sorted)
        ->not->toContain('AlfaSix\\Alfakit\\Forms\\Components\\Select')
        ->toContain('AlfaSix\\Alfakit\\Forms\\Components\\TextInput');
});

it('is reachable from make:alfakit-resource --wizard', function (): void {
    $this->artisan('make:alfakit-resource', ['model' => Comment::class, '--wizard' => true, '--no-interaction' => true])->assertSuccessful();

    expect(wizardFile($this->sandbox, 'Resources/CommentResource.php'))->toContain("TextInput::make('author_name')");
});

it('fails clearly without a model when prompts are off', function (): void {
    $this->artisan('alfakit:generate', ['--no-interaction' => true])->assertFailed();
    $this->artisan('alfakit:generate', ['model' => 'App\\Models\\Missing', '--no-interaction' => true])
        ->expectsOutputToContain('is not an Eloquent model')
        ->assertFailed();
});

it('runs the interactive flow', function (): void {
    $this->artisan('alfakit:generate', ['model' => Comment::class, '--panel' => 'admin'])
        ->expectsQuestion('Form fields', ['author_name', 'body'])
        ->expectsQuestion('Table columns', ['author_name'])
        ->expectsQuestion('Table filters', [])
        ->expectsConfirmation('Add a View page with an infolist?', 'no')
        ->expectsConfirmation('Generate CommentResource?', 'yes')
        ->assertSuccessful();

    expect(wizardFile($this->sandbox, 'Resources/CommentResource.php'))
        ->toContain("RichEditor::make('body')")
        ->not->toContain("Select::make('post_id')");
});

it('tidies imports', function (): void {
    $code = "<?php\n\nnamespace App;\n\nuse B\\Zed;\nuse A\\Used;\n\nuse A\\Unused;\nuse A\\Used;\n\nclass X extends Used\n{\n}\n";

    expect(GeneratedCode::tidyImports($code))->toBe("<?php\n\nnamespace App;\n\nuse A\\Used;\n\nclass X extends Used\n{\n}\n");
});
