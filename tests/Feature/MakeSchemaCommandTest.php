<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Tests\Fixtures\Models\Post;
use Illuminate\Filesystem\Filesystem;

/*
 * `make:alfakit-schema` generates a Fragment class in app/Alfakit/Schemas.
 */

beforeEach(function (): void {
    $this->sandbox = sys_get_temp_dir().'/alfakit-schema-'.uniqid();
    (new Filesystem)->ensureDirectoryExists($this->sandbox.'/app');
    $this->originalBasePath = $this->app->basePath();
    $this->app->setBasePath($this->sandbox);
    config(['alfakit.file_generation.path' => $this->sandbox.'/app/Alfakit', 'alfakit.file_generation.namespace' => 'App\\Alfakit']);
});

afterEach(function (): void {
    $this->app->setBasePath($this->originalBasePath);
    (new Filesystem)->deleteDirectory($this->sandbox);
});

it('generates a fragment class that works inside a schema', function (): void {
    $this->artisan('make:alfakit-schema', ['name' => 'AddressFields'])->assertSuccessful();

    $path = $this->sandbox.'/app/Alfakit/Schemas/AddressFields.php';
    $class = file_get_contents($path);

    expect($class)
        ->toContain('namespace App\\Alfakit\\Schemas;')
        ->toContain('class AddressFields extends Fragment')
        ->toContain('public function define(): array')
        ->toContain("TextInput::make('street')");

    require_once $path;

    $schema = schema([App\Alfakit\Schemas\AddressFields::make()->statePath('billing')])->fill();

    expect($schema->getRawState())->toBe(['billing' => ['street' => null, 'city' => null, 'postal_code' => null]]);

    $this->artisan('make:alfakit-schema', ['name' => 'AddressFields'])
        ->expectsOutputToContain('already exists')
        ->assertSuccessful();
});

it('starts from a model when asked', function (): void {
    $this->artisan('make:alfakit-schema', ['name' => 'PostFields', '--model' => Post::class])->assertSuccessful();

    $class = file_get_contents($this->sandbox.'/app/Alfakit/Schemas/PostFields.php');

    expect($class)
        ->toContain("Select::make('category_id')")
        ->toContain("TextInput::make('title')->required()")
        ->toContain("Textarea::make('content')")
        ->toContain("Toggle::make('is_published')")
        ->not->toContain("make('id')");
});
