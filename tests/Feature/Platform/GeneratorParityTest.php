<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Commands\Generators\ClassGenerator;
use AlfaSix\Alfakit\Commands\InstallCommand;
use AlfaSix\Alfakit\Commands\MakeIssueCommand;
use AlfaSix\Alfakit\Commands\MakeThemeCommand;
use AlfaSix\Alfakit\Infolists\Components\ColorEntry;
use AlfaSix\Alfakit\Panel;
use AlfaSix\Alfakit\Tables\Columns\ColorColumn;
use AlfaSix\Alfakit\Tests\Fixtures\AuthTenancy\Panels;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Category;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Comment;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Post;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Team;
use AlfaSix\Alfakit\Tests\Fixtures\Panel\Resources\PostResource;
use Illuminate\Filesystem\Filesystem;

/*
 * Generator options matching Filament's (`--generate`, `--model`, `--soft-deletes`,
 * `--type`, `--resource`, `--pm`, …), the new generators, `ClassGenerator` and the
 * installer's composer script. Each test runs in its own temporary app directory.
 */

beforeEach(function (): void {
    $this->sandbox = sys_get_temp_dir().'/alfakit-parity-gen-'.uniqid();
    $files = new Filesystem;
    $files->ensureDirectoryExists($this->sandbox.'/app/Models');
    $files->ensureDirectoryExists($this->sandbox.'/resources/js/alfakit');
    $files->ensureDirectoryExists($this->sandbox.'/database/migrations');
    $files->ensureDirectoryExists($this->sandbox.'/database/factories');
    file_put_contents($this->sandbox.'/composer.json', json_encode(['autoload' => ['psr-4' => ['App\\' => 'app/']]]));

    $this->originalBasePath = $this->app->basePath();
    $this->app->setBasePath($this->sandbox);
    config(['alfakit.file_generation.path' => $this->sandbox.'/app/Alfakit', 'alfakit.file_generation.namespace' => 'App\\Alfakit']);
});

afterEach(function (): void {
    $this->app->setBasePath($this->originalBasePath);
    (new Filesystem)->deleteDirectory($this->sandbox);
});

/**
 * A generated file, wherever the panel namespace put it (`Resources/…` or `Admin/Resources/…`).
 */
function generatedFile(string $suffix): string
{
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path('Alfakit'), FilesystemIterator::SKIP_DOTS)) as $file) {
        if (str_ends_with(str_replace('\\', '/', $file->getPathname()), $suffix)) {
            return (string) file_get_contents($file->getPathname());
        }
    }

    throw new RuntimeException("{$suffix} was not generated.");
}

it('generates standalone form fields and table columns from a model', function (): void {
    $this->artisan('make:alfakit-form', ['name' => 'PostForm', '--model' => Post::class, '--generate' => true])->assertSuccessful();
    $this->artisan('make:alfakit-table', ['name' => 'PostsTable', '--model' => Post::class, '--generate' => true])->assertSuccessful();

    $form = file_get_contents(app_path('Alfakit/Standalone/PostForm.php'));
    $table = file_get_contents(app_path('Alfakit/Standalone/PostsTable.php'));

    expect($form)
        ->toContain("TextInput::make('title')")
        ->toContain("Toggle::make('is_published')")
        ->toContain('use AlfaSix\\Alfakit\\Forms\\Components\\Toggle;')
        ->not->toContain("TextInput::make('email')")
        ->and($table)
        ->toContain("TextColumn::make('title')")
        ->toContain("IconColumn::make('is_published')")
        ->not->toContain("make('id')");

    foreach ([$form, $table] as $code) {
        expect(fn () => token_get_all($code, TOKEN_PARSE))->not->toThrow(ParseError::class);
    }
});

it('generates resource fields from the model casts, like the wizard', function (): void {
    $this->artisan('make:alfakit-resource', ['model' => Post::class, '--generate' => true])->assertSuccessful();

    // SQLite reports json columns as text: the `array` cast makes them key-value fields.
    expect(generatedFile('Resources/PostResource.php'))
        ->toContain("KeyValue::make('meta')")
        ->toContain("Toggle::make('is_published')")
        ->toContain("->relationship('author', ")
        ->not->toContain("Textarea::make('meta')")
        ->toContain('protected static ?string $recordTitleAttribute = \'title\';');

    $this->artisan('make:alfakit-form', ['name' => 'PostForm', '--model' => Post::class, '--generate' => true])->assertSuccessful();
    expect(file_get_contents(app_path('Alfakit/Standalone/PostForm.php')))->toContain("KeyValue::make('meta')");
});

it('leaves the owner column out of resources for a panel scoped to the signed-in user', function (): void {
    Panels::register('portal', fn (Panel $panel): Panel => $panel->portal()->scopeToUser('author'));

    $this->artisan('make:alfakit-resource', ['model' => Post::class, '--generate' => true, '--panel' => 'portal'])
        ->expectsOutputToContain('Left out the owner column')
        ->assertSuccessful();

    expect(generatedFile('Resources/PostResource.php'))
        ->toContain("TextInput::make('title')")
        ->not->toContain("make('author_id')")
        ->not->toContain('author.');

    $this->artisan('alfakit:generate', ['model' => Post::class, '--panel' => 'portal', '--force' => true, '--no-interaction' => true])->assertSuccessful();

    expect(generatedFile('Resources/PostResource.php'))->not->toContain("make('author_id')");
});

it('leaves the tenant ownership column out of resources for a tenancy panel', function (): void {
    Panels::register('teams', fn (Panel $panel): Panel => $panel->tenant(Team::class, ownershipRelationship: 'author'));

    $this->artisan('make:alfakit-resource', ['model' => Post::class, '--generate' => true, '--panel' => 'teams'])
        ->expectsOutputToContain('Left out the tenant column')
        ->assertSuccessful();

    expect(generatedFile('Resources/PostResource.php'))
        ->toContain("TextInput::make('title')")
        ->not->toContain("make('author_id')")
        ->not->toContain('author.');

    $this->artisan('alfakit:generate', ['model' => Post::class, '--panel' => 'teams', '--force' => true, '--no-interaction' => true])->assertSuccessful();

    expect(generatedFile('Resources/PostResource.php'))->not->toContain("make('author_id')");
});

it('leaves the foreign key to the parent out of a nested resource', function (): void {
    $this->artisan('make:alfakit-resource', ['model' => Comment::class, '--generate' => true, '--nested' => PostResource::class])->assertSuccessful();

    expect(generatedFile('Resources/CommentResource.php'))
        ->toContain('protected static ?string $parentResource = PostResource::class;')
        ->toContain("make('body')")
        ->not->toContain("make('post_id')")
        ->not->toContain("make('post.");
});

it('keeps the example fields without --generate or without a model', function (): void {
    $this->artisan('make:alfakit-form', ['name' => 'ContactForm'])->assertSuccessful();
    $this->artisan('make:alfakit-table', ['name' => 'OrdersTable', '--generate' => true])
        ->expectsOutputToContain('--generate needs an existing model')
        ->assertSuccessful();

    expect(file_get_contents(app_path('Alfakit/Standalone/ContactForm.php')))
        ->toContain("Textarea::make('message')")
        ->toContain('use AlfaSix\\Alfakit\\Forms\\Components\\TextInput;')
        ->and(file_get_contents(app_path('Alfakit/Standalone/OrdersTable.php')))
        ->toContain("TextColumn::make('created_at')->dateTime()->sortable(),");
});

it('creates the model, migration and factory for a resource, in a custom namespace', function (): void {
    $this->artisan('make:alfakit-resource', ['model' => 'Invoice', '--model' => true, '--migration' => true, '--factory' => true])->assertSuccessful();

    expect(app_path('Models/Invoice.php'))->toBeFile()
        ->and(glob(database_path('migrations/*_create_invoices_table.php')))->toHaveCount(1)
        ->and(database_path('factories/InvoiceFactory.php'))->toBeFile()
        ->and(generatedFile('Resources/InvoiceResource.php'))->toContain('App\\Models\\Invoice');

    $this->artisan('make:alfakit-resource', ['model' => 'Order', '--model-namespace' => 'App\\Domain\\Shop\\Models'])->assertSuccessful();

    expect(generatedFile('Resources/OrderResource.php'))
        ->toContain('App\\Domain\\Shop\\Models\\Order')
        ->not->toContain('App\\Models\\Order');
});

it('adds only a migration or factory when the model already exists', function (): void {
    $this->artisan('make:alfakit-resource', ['model' => Post::class, '--model' => true, '--migration' => true])
        ->expectsOutputToContain('already exists, skipping the model')
        ->assertSuccessful();

    expect(glob(database_path('migrations/*_create_posts_table.php')))->toHaveCount(1);
});

it('generates relation managers with soft deletes and a view action', function (): void {
    $this->artisan('make:alfakit-relation-manager', ['resource' => 'PostResource', 'relationship' => 'comments', 'recordTitleAttribute' => 'body', '--soft-deletes' => true, '--view' => true])->assertSuccessful();

    $code = generatedFile('PostResource/RelationManagers/CommentsRelationManager.php');

    expect($code)
        ->toContain('TrashedFilter::make()')
        ->toContain('withoutGlobalScopes([SoftDeletingScope::class])')
        ->toContain('RestoreAction::make()')
        ->toContain('ForceDeleteBulkAction::make()')
        ->toContain('ViewAction::make()')
        ->toContain('public function infolist(Schema $schema): Schema')
        ->toContain("TextEntry::make('body')");

    expect(fn () => token_get_all($code, TOKEN_PARSE))->not->toThrow(ParseError::class);

    $this->artisan('make:alfakit-relation-manager', ['resource' => 'PostResource', 'relationship' => 'tags', 'recordTitleAttribute' => 'name'])->assertSuccessful();

    expect(generatedFile('PostResource/RelationManagers/TagsRelationManager.php'))
        ->not->toContain('TrashedFilter')
        ->not->toContain('infolist');
});

it('generates widgets inside a resource', function (): void {
    $this->artisan('make:alfakit-widget', ['name' => 'PostStats', '--type' => 'stats', '--resource' => 'Post'])
        ->expectsOutputToContain('PostResource::getWidgets()')
        ->assertSuccessful();

    expect(generatedFile('Resources/PostResource/Widgets/PostStats.php'))
        ->toMatch('/namespace App\\\\Alfakit(\\\\\\w+)?\\\\Resources\\\\PostResource\\\\Widgets;/');
});

it('generates record pages by --type', function (): void {
    $this->artisan('make:alfakit-page', ['name' => 'ViewPostStats', '--resource' => 'PostResource', '--type' => 'ViewRecord'])->assertSuccessful();
    $this->artisan('make:alfakit-page', ['name' => 'EditPostSeo', '--resource' => 'Post', '--type' => 'EditRecord'])->assertSuccessful();
    $this->artisan('make:alfakit-page', ['name' => 'Ignored', '--resource' => 'Post', '--type' => 'ManageRelatedRecords', '--relationship' => 'comments', '--record-title-attribute' => 'body'])->assertSuccessful();

    expect(generatedFile('PostResource/Pages/ViewPostStats.php'))
        ->toContain('class ViewPostStats extends ViewRecord')
        ->and(generatedFile('PostResource/Pages/EditPostSeo.php'))
        ->toContain('class EditPostSeo extends EditRecord')
        ->toContain('return PostResource::form($schema);')
        ->and(generatedFile('PostResource/Pages/ManagePostComments.php'))->toContain("'body'");

    $this->artisan('make:alfakit-page', ['name' => 'X', '--type' => 'EditRecord'])
        ->expectsOutputToContain('needs --resource')
        ->assertFailed();
    $this->artisan('make:alfakit-page', ['name' => 'X', '--resource' => 'Post', '--type' => 'Wizard'])
        ->expectsOutputToContain('Unknown page type')
        ->assertFailed();
});

it('validates the theme package manager and detects it from the lockfile', function (): void {
    $this->artisan('make:alfakit-theme', ['name' => 'acme', '--extends' => 'alfakit', '--pm' => 'gradle'])
        ->expectsOutputToContain('Unknown package manager')
        ->assertFailed();

    expect(MakeThemeCommand::detectPackageManager($this->sandbox))->toBe('npm');

    touch($this->sandbox.'/pnpm-lock.yaml');

    expect(MakeThemeCommand::detectPackageManager($this->sandbox))->toBe('pnpm');
});

it('generates rich content custom blocks that work with the editor', function (): void {
    $this->artisan('make:alfakit-rich-content-custom-block', ['name' => 'Hero'])->assertSuccessful();

    $path = app_path('Alfakit/Forms/Components/RichEditor/RichContentCustomBlocks/HeroBlock.php');

    require_once $path;

    $class = 'App\\Alfakit\\Forms\\Components\\RichEditor\\RichContentCustomBlocks\\HeroBlock';

    expect($class::getId())->toBe('hero')
        ->and($class::getLabel())->toBe('Hero')
        ->and($class::toHtml(['heading' => '<b>Hi</b>'], []))->toBe('<section class="hero"><h2>&lt;b&gt;Hi&lt;/b&gt;</h2></section>');
});

it('generates schema components with a renderer component', function (): void {
    file_put_contents(resource_path('js/alfakit/app.tsx'), "import { createAlfakit } from '@alfakit/react';\n\nvoid createAlfakit({\n    components: {},\n});\n");

    $this->artisan('make:alfakit-schema-component', ['name' => 'Card', '--adapter' => 'react'])->assertSuccessful();

    $path = app_path('Alfakit/Schemas/Components/Card.php');

    require_once $path;

    $schema = App\Alfakit\Schemas\Components\Card::make([AlfaSix\Alfakit\Forms\Components\TextInput::make('title')]);

    expect(schema([$schema])->toSchema()[0])
        ->toMatchArray(['type' => 'Card'])
        ->and(file_get_contents(resource_path('js/alfakit/components/Card.tsx')))
        ->toContain('<SchemaChildren component={component} />')
        ->and(file_get_contents(resource_path('js/alfakit/app.tsx')))->toContain("'Card': Card");
});

it('prints a prefilled GitHub issue link with the versions', function (): void {
    file_put_contents(base_path('package.json'), json_encode(['dependencies' => ['@alfakit/react' => '^1.2.0', 'left-pad' => '1.0.0']]));

    $this->artisan('make:alfakit-issue', ['--title' => 'Table bug'])
        ->expectsOutputToContain(MakeIssueCommand::REPOSITORY.'/issues/new?title=Table%20bug')
        ->expectsOutputToContain('@alfakit/react')
        ->assertSuccessful();

    $versions = app(MakeIssueCommand::class)->versions();

    expect($versions)->toHaveKeys(['AlfaKit', 'Laravel', 'PHP', '@alfakit/react'])->not->toHaveKey('left-pad');
});

it('lets the app replace the class generator', function (): void {
    $this->app->bind(ClassGenerator::class, fn ($app) => new class($app->make(Filesystem::class)) extends ClassGenerator
    {
        protected function modify(string $stub, string $contents, array $replacements): string
        {
            return $stub === 'standalone/form' ? str_replace('class ', "#[\\AllowDynamicProperties]\nclass ", $contents) : $contents;
        }
    });

    $this->artisan('make:alfakit-form', ['name' => 'ContactForm'])->assertSuccessful();
    $this->artisan('make:alfakit-table', ['name' => 'OrdersTable'])->assertSuccessful();

    expect(file_get_contents(app_path('Alfakit/Standalone/ContactForm.php')))->toContain("#[\\AllowDynamicProperties]\nclass ContactForm")
        ->and(file_get_contents(app_path('Alfakit/Standalone/OrdersTable.php')))->not->toContain('AllowDynamicProperties');
});

it('adds alfakit:upgrade to post-autoload-dump once', function (): void {
    $composer = InstallCommand::addUpgradeScript(['scripts' => ['post-autoload-dump' => ['Illuminate\\Foundation\\ComposerScripts::postAutoloadDump', '@php artisan package:discover --ansi']]]);

    expect($composer['scripts']['post-autoload-dump'])->toBe([
        'Illuminate\\Foundation\\ComposerScripts::postAutoloadDump',
        '@php artisan package:discover --ansi',
        '@php artisan alfakit:upgrade',
    ])
        ->and(InstallCommand::addUpgradeScript($composer))->toBe($composer)
        ->and(InstallCommand::addUpgradeScript([])['scripts']['post-autoload-dump'])->toBe(['@php artisan alfakit:upgrade'])
        ->and(InstallCommand::addUpgradeScript(['scripts' => ['post-autoload-dump' => '@php artisan x']])['scripts']['post-autoload-dump'])->toBe(['@php artisan x', '@php artisan alfakit:upgrade']);
});

it('adds the framework Vite plugin when scaffolding', function (): void {
    $config = "import { defineConfig } from 'vite';\nimport laravel from 'laravel-vite-plugin';\n\nexport default defineConfig({\n    plugins: [\n        laravel({ input: ['resources/js/app.js'] }),\n    ],\n});\n";

    $react = InstallCommand::addVitePlugin($config, 'react');

    expect($react)
        ->toContain("import laravel from 'laravel-vite-plugin';\nimport react from '@vitejs/plugin-react';")
        ->toContain("plugins: [\n        react(),")
        ->and(InstallCommand::addVitePlugin((string) $react, 'react'))->toBe($react)
        ->and(InstallCommand::addVitePlugin($config, 'svelte'))->toContain("import { svelte } from '@sveltejs/vite-plugin-svelte';")
        ->and(InstallCommand::addVitePlugin('export default {}', 'vue'))->toBeNull()
        ->and(InstallCommand::scaffoldPackages('vue')['dependencies'])->toContain('@inertiajs/vue3');
});

it('only sends plain CSS colours from ColorColumn and ColorEntry', function (): void {
    $state = static fn (object $component): mixed => (fn () => $this->getFormattedState())->call($component);

    expect($state(ColorColumn::make('color')->state('#ff0000')))->toBe('#ff0000')
        ->and($state(ColorColumn::make('color')->state('red;background:url(x)')))->toBeNull()
        ->and($state(ColorColumn::make('color')->state(['#fff', 'url(x)', 'oklch(0.5 0.1 30)'])))->toBe(['#fff', 'oklch(0.5 0.1 30)'])
        ->and($state(ColorEntry::make('color')->state('var(--x)')))->toBeNull()
        ->and($state(ColorEntry::make('color')->state(null)))->toBeNull()
        ->and($state(ColorEntry::make('color')->state('rgb(1, 2, 3)')))->toBe('rgb(1, 2, 3)');
});

it('leaves soft-delete support out of a resource whose model does not soft delete', function (): void {
    $this->artisan('make:alfakit-resource', ['model' => Category::class, '--soft-deletes' => true])
        ->expectsOutputToContain('does not use SoftDeletes')
        ->assertSuccessful();

    expect(generatedFile('CategoryResource.php'))->not->toContain('TrashedFilter')->not->toContain('RestoreAction');

    $this->artisan('make:alfakit-resource', ['model' => Post::class, '--soft-deletes' => true])
        ->doesntExpectOutputToContain('does not use SoftDeletes')
        ->assertSuccessful();

    expect(generatedFile('PostResource.php'))->toContain('TrashedFilter::make()');
});
