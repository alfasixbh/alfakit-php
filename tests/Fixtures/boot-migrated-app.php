<?php

declare(strict_types=1);

/*
 * Boots an application migrated by `alfakit:migrate-from-filament` in its own PHP process
 * (its `App\` classes would collide with other fixtures in the Pest process) and renders
 * every GET page of its panels as an Inertia request.
 *
 *   php boot-migrated-app.php <migrated-app-path> [plugin service provider …]
 *
 * Prints JSON: {"pages": {"/admin/acme/products": {"status": 200, "component": "…"}, …}}.
 * The app's providers come from its bootstrap/providers.php, its migrations from
 * database/migrations (after the package's fixture tables, which include `users`).
 */

use AlfaSix\Alfakit\AlfakitServiceProvider;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Inertia\ServiceProvider as InertiaServiceProvider;
use Orchestra\Testbench\Foundation\Application;

$root = dirname(__DIR__, 4);
$appPath = rtrim((string) ($argv[1] ?? ''), '/');
$extraProviders = array_slice($argv, 2);

if ($appPath === '' || ! is_dir($appPath.'/app')) {
    fwrite(STDERR, "Usage: php boot-migrated-app.php <migrated-app-path> [providers…]\n");
    exit(2);
}

require $root.'/vendor/autoload.php';

spl_autoload_register(static function (string $class) use ($appPath): void {
    if (str_starts_with($class, 'App\\')) {
        $file = $appPath.'/app/'.str_replace('\\', '/', substr($class, 4)).'.php';

        if (is_file($file)) {
            require_once $file;
        }
    }
});

/** @var array<int, class-string> $appProviders */
$appProviders = require $appPath.'/bootstrap/providers.php';

// Its own copy of Testbench's skeleton, like the test processes (compiled views, caches).
$resolver = new class(AlfaSix\Alfakit\Tests\Support\IsolatedSkeleton::path(), null) extends Application
{
    public ?Closure $environment = null;

    protected function defineEnvironment($app)
    {
        if ($this->environment !== null) {
            ($this->environment)($app);
        }
    }
};

$resolver->environment = static function ($app) use ($appPath): void {
    // `app_path('Alfakit/Resources')` in the panel provider must point at the migrated app.
    $app->useAppPath($appPath.'/app');
    $app['config']->set('app.key', 'base64:2fl+Ktvkfl+Fuz4Qp/A75G2RTiWVA/ZoKZvp6fiiM10=');
    $app['config']->set('app.debug', true);
    $app['config']->set('database.default', 'testing');
    $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false]);
    $app['config']->set('auth.providers.users.model', 'App\\Models\\User');
    $app['config']->set('inertia.testing.ensure_pages_exist', false);
    $app['config']->set('alfakit.cache_path', sys_get_temp_dir().'/alfakit-boot-'.md5($appPath));
    $app['config']->set('view.paths', [$appPath.'/resources/views', ...(array) $app['config']->get('view.paths')]);
};

$app = $resolver
    ->configure(['extra' => ['providers' => [InertiaServiceProvider::class, AlfakitServiceProvider::class, ...$extraProviders, ...$appProviders]]])
    ->createApplication();

Artisan::call('migrate', ['--path' => $appPath.'/database/migrations', '--realpath' => true, '--force' => true]);

foreach ($extraProviders as $provider) {
    // Plugin migrations (comments, media library, …) that `alfakit:add` would run.
    $directory = dirname((string) (new ReflectionClass($provider))->getFileName(), 2).'/database/migrations';

    if (is_dir($directory)) {
        Artisan::call('migrate', ['--path' => $directory, '--realpath' => true, '--force' => true]);
    }
}

$user = App\Models\User::query()->create(['name' => 'Admin', 'email' => 'admin@acme.test', 'password' => 'password']);
$shop = App\Models\Shop::query()->create(['name' => 'Acme', 'slug' => 'acme']);
$shop->members()->attach($user);

$records = [];
$brand = App\Models\Brand::query()->create(['shop_id' => $shop->id, 'name' => 'Lumen', 'slug' => 'lumen']);
$customer = App\Models\Customer::query()->create(['shop_id' => $shop->id, 'name' => 'Ada Lovelace', 'email' => 'ada@acme.test', 'country' => 'gb']);
$product = App\Models\Product::query()->create(['shop_id' => $shop->id, 'brand_id' => $brand->id, 'name' => 'Desk lamp', 'slug' => 'desk-lamp', 'sku' => 'LAMP-1', 'price' => 49, 'qty' => 4]);
$order = App\Models\Order::query()->create(['shop_id' => $shop->id, 'customer_id' => $customer->id, 'number' => 'OR-100001', 'status' => 'processing', 'total_price' => 49]);
App\Models\OrderItem::query()->create(['order_id' => $order->id, 'product_id' => $product->id, 'qty' => 1, 'unit_price' => 49]);
App\Models\Review::query()->create(['product_id' => $product->id, 'customer_id' => $customer->id, 'title' => 'Bright', 'rating' => 5]);

foreach ([$brand, $customer, $product, $order, $user] as $record) {
    $records[$record->getTable()] = $record;
}

$app['auth']->guard('web')->setUser($user);
$app['auth']->shouldUse('web');

$kernel = $app->make(Kernel::class);
$results = [];

/** @var array<int, Route> $routes */
$routes = $app['router']->getRoutes()->getRoutes();

foreach ($routes as $route) {
    $name = (string) $route->getName();

    if (! in_array('GET', $route->methods(), true) || ! str_starts_with($name, 'alfakit.') || str_contains($name, '.internal.') || str_contains($name, '.auth.') || str_contains($route->uri(), '{id}')) {
        continue;
    }

    $uri = '/'.ltrim($route->uri(), '/');
    $missing = false;

    $uri = (string) preg_replace_callback('/\{(\w+)\??\}/', static function (array $matches) use ($shop, $records, $route, &$missing): string {
        if ($matches[1] === 'tenant') {
            return (string) $shop->slug;
        }

        if ($matches[1] === 'record') {
            // The resource's model decides which seeded record the page opens.
            $page = Str::before((string) $route->getAction('uses'), '@');
            $model = class_exists($page) && method_exists($page, 'getResource') ? $page::getResource()::getModel() : null;
            $record = $model !== null ? collect($records)->first(static fn ($record) => $record instanceof $model) : null;

            if ($record === null) {
                $missing = true;

                return '0';
            }

            return (string) $record->getRouteKey();
        }

        $missing = true;

        return '0';
    }, $uri);

    if ($missing) {
        $results[$uri] = ['status' => null, 'skipped' => 'no record for the route parameters'];

        continue;
    }

    $request = Request::create($uri, 'GET', server: ['HTTP_X_INERTIA' => 'true', 'HTTP_X_INERTIA_VERSION' => '', 'HTTP_ACCEPT' => 'text/html, application/xhtml+xml']);
    $response = $kernel->handle($request);
    $kernel->terminate($request, $response);
    $body = (string) $response->getContent();
    $json = json_decode($body, true);

    // Deferred props (lazy widgets, deferred tables): load them as the renderer does right after.
    $deferred = null;

    if (is_array($json) && is_array($json['deferredProps'] ?? null) && $response->getStatusCode() === 200) {
        $props = array_merge(...array_values(array_map(static fn ($group): array => (array) $group, $json['deferredProps'])));
        $partial = Request::create($uri, 'GET', server: [
            'HTTP_X_INERTIA' => 'true',
            'HTTP_X_INERTIA_VERSION' => '',
            'HTTP_X_INERTIA_PARTIAL_COMPONENT' => (string) ($json['component'] ?? ''),
            'HTTP_X_INERTIA_PARTIAL_DATA' => implode(',', $props),
        ]);
        $partialResponse = $kernel->handle($partial);
        $kernel->terminate($partial, $partialResponse);
        $partialJsonData = (array) json_decode((string) $partialResponse->getContent(), true);
        $types = [];
        array_walk_recursive($partialJsonData, static function ($value, $key) use (&$types): void {
            if ($key === 'type' && is_string($value)) {
                $types[$value] = true;
            }
        });
        $deferred = ['props' => $props, 'status' => $partialResponse->getStatusCode(), 'types' => array_keys($types)];

        if ($partialResponse->getStatusCode() >= 500) {
            $partialJson = json_decode((string) $partialResponse->getContent(), true);
            $deferred['error'] = Str::limit(is_array($partialJson) ? (string) ($partialJson['message'] ?? '') : strip_tags((string) $partialResponse->getContent()), 600);
        }
    }

    $types = [];

    if (is_array($json)) {
        array_walk_recursive($json, static function ($value, $key) use (&$types): void {
            if ($key === 'type' && is_string($value)) {
                $types[$value] = true;
            }
        });
    }

    $results[$uri] = array_filter([
        'types' => array_keys($types) ?: null,
        'deferred' => $deferred,
        'route' => $name,
        'status' => $response->getStatusCode(),
        'component' => is_array($json) ? ($json['component'] ?? null) : null,
        'location' => $response->headers->get('Location') ?? $response->headers->get('X-Inertia-Location'),
        'error' => $response->getStatusCode() >= 500 ? Str::limit(is_array($json) ? (string) ($json['message'] ?? '') : strip_tags($body), 600) : null,
    ], static fn ($value) => $value !== null);
}

ksort($results);

echo json_encode(['pages' => $results], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
