<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;

/*
 * `make:alfakit-related-records-page` generates a ManageRelatedRecords page for one
 * relationship of a resource.
 */

beforeEach(function (): void {
    $this->sandbox = sys_get_temp_dir().'/alfakit-related-'.uniqid();
    (new Filesystem)->ensureDirectoryExists($this->sandbox.'/app');
    $this->originalBasePath = $this->app->basePath();
    $this->app->setBasePath($this->sandbox);
    config(['alfakit.file_generation.path' => $this->sandbox.'/app/Alfakit', 'alfakit.file_generation.namespace' => 'App\\Alfakit']);
});

afterEach(function (): void {
    $this->app->setBasePath($this->originalBasePath);
    (new Filesystem)->deleteDirectory($this->sandbox);
});

function generatedRelatedPage(string $sandbox, string $class): string
{
    $files = (new Filesystem)->allFiles($sandbox.'/app/Alfakit');
    $match = collect($files)->first(fn (SplFileInfo $file): bool => $file->getFilename() === "{$class}.php");

    expect($match)->not->toBeNull("{$class}.php was not generated");

    return $match->getPathname();
}

it('generates a ManageRelatedRecords page with the relationship and its actions', function (): void {
    $this->artisan('make:alfakit-related-records-page', ['resource' => 'Post', 'relationship' => 'comments', 'recordTitleAttribute' => 'body'])
        ->expectsOutputToContain("'comments' => Pages\\ManagePostComments::route('/{record}/comments')")
        ->assertSuccessful();

    // Generated under the default panel's namespace segment when several panels are registered.
    $path = generatedRelatedPage($this->sandbox, 'ManagePostComments');
    $class = file_get_contents($path);

    expect($class)
        ->toMatch('/namespace App\\\\Alfakit(\\\\Admin)?\\\\Resources\\\\PostResource\\\\Pages;/')
        ->toMatch('/use App\\\\Alfakit(\\\\Admin)?\\\\Resources\\\\PostResource;/')
        ->toContain('class ManagePostComments extends ManageRelatedRecords')
        ->toContain('protected static string $resource = PostResource::class;')
        ->toContain("protected static string \$relationship = 'comments';")
        ->toContain("protected static ?string \$relatedRecordTitleAttribute = 'body';")
        ->toContain("TextInput::make('body')")
        ->toContain('CreateAction::make(),')
        ->not->toContain('AttachAction')
        ->not->toContain('{{');

    // Existing files are kept unless --force is given.
    $this->artisan('make:alfakit-related-records-page', ['resource' => 'Post', 'relationship' => 'comments', 'recordTitleAttribute' => 'body'])
        ->expectsOutputToContain('already exists')
        ->assertSuccessful();
});

it('adds attach/detach and associate/dissociate actions on request', function (): void {
    $this->artisan('make:alfakit-related-records-page', ['resource' => 'PostResource', 'relationship' => 'tags', 'recordTitleAttribute' => 'name', '--attach' => true, '--associate' => true])
        ->assertSuccessful();

    $class = file_get_contents(generatedRelatedPage($this->sandbox, 'ManagePostTags'));

    expect($class)
        ->toContain('AttachAction::make(),')
        ->toContain('DetachAction::make(),')
        ->toContain('AssociateAction::make(),')
        ->toContain('DissociateAction::make(),')
        ->toContain('use AlfaSix\\Alfakit\\Actions\\AttachAction;');
});
