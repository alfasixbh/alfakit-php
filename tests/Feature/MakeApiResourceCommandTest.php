<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Resources\Api\DataSource;
use AlfaSix\Alfakit\Resources\Api\HttpDataSource;
use AlfaSix\Alfakit\Resources\ApiResource;
use AlfaSix\Alfakit\Resources\Pages\CreateApiRecord;
use AlfaSix\Alfakit\Resources\Pages\EditApiRecord;
use AlfaSix\Alfakit\Resources\Pages\ListApiRecords;
use AlfaSix\Alfakit\Resources\Pages\ViewApiRecord;
use Illuminate\Filesystem\Filesystem;

/*
 * `make:alfakit-api-resource` generates an ApiResource, its four pages and a data source.
 */

beforeEach(function (): void {
    $this->sandbox = sys_get_temp_dir().'/alfakit-api-resource-'.uniqid();
    (new Filesystem)->ensureDirectoryExists($this->sandbox.'/app');
    $this->originalBasePath = $this->app->basePath();
    $this->app->setBasePath($this->sandbox);
    config(['alfakit.file_generation.path' => $this->sandbox.'/app/Alfakit', 'alfakit.file_generation.namespace' => 'App\\Alfakit']);
});

afterEach(function (): void {
    $this->app->setBasePath($this->originalBasePath);
    (new Filesystem)->deleteDirectory($this->sandbox);
});

/**
 * @return array<string, string> file name => path
 */
function generatedApiFiles(string $sandbox): array
{
    return collect((new Filesystem)->allFiles($sandbox.'/app/Alfakit'))
        ->mapWithKeys(fn (SplFileInfo $file): array => [$file->getFilename() => $file->getPathname()])
        ->all();
}

it('generates an API resource with an HTTP data source that compiles', function (): void {
    $this->artisan('make:alfakit-api-resource', ['name' => 'Warehouse', '--url' => 'https://erp.example.com/api/'])->assertSuccessful();

    $files = generatedApiFiles($this->sandbox);

    expect(array_keys($files))->toEqualCanonicalizing([
        'WarehouseResource.php', 'WarehouseDataSource.php', 'ListWarehouses.php', 'CreateWarehouse.php', 'EditWarehouse.php', 'ViewWarehouse.php',
    ]);

    expect(file_get_contents($files['WarehouseDataSource.php']))
        ->toContain('extends HttpDataSource')
        ->toContain("protected string \$baseUrl = 'https://erp.example.com/api';")
        ->toContain("protected string \$endpoint = '/warehouses';");

    // Every generated file loads against the current API.
    foreach ($files as $path) {
        require_once $path;
    }

    $namespace = collect(get_declared_classes())->first(fn (string $class): bool => str_ends_with($class, '\\Resources\\WarehouseResource'));

    expect($namespace)->not->toBeNull()
        ->and(is_subclass_of($namespace, ApiResource::class))->toBeTrue()
        ->and(is_subclass_of($namespace.'\\WarehouseDataSource', HttpDataSource::class))->toBeTrue()
        ->and(is_subclass_of($namespace.'\\Pages\\ListWarehouses', ListApiRecords::class))->toBeTrue()
        ->and(is_subclass_of($namespace.'\\Pages\\CreateWarehouse', CreateApiRecord::class))->toBeTrue()
        ->and(is_subclass_of($namespace.'\\Pages\\EditWarehouse', EditApiRecord::class))->toBeTrue()
        ->and(is_subclass_of($namespace.'\\Pages\\ViewWarehouse', ViewApiRecord::class))->toBeTrue()
        ->and(array_keys($namespace::getPages()))->toBe(['index', 'create', 'view', 'edit']);
});

it('generates a plain data source without --url', function (): void {
    $this->artisan('make:alfakit-api-resource', ['name' => 'ExchangeRate'])->assertSuccessful();

    $path = generatedApiFiles($this->sandbox)['ExchangeRateDataSource.php'];
    require_once $path;

    $class = collect(get_declared_classes())->first(fn (string $class): bool => str_ends_with($class, '\\ExchangeRateDataSource'));

    expect(file_get_contents($path))->toContain('extends DataSource')
        ->and(is_subclass_of((string) $class, DataSource::class))->toBeTrue();
});
