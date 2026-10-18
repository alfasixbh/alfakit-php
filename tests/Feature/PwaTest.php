<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Notifications\Notification;
use AlfaSix\Alfakit\Panel;
use AlfaSix\Alfakit\Pwa\PushNotifications;
use AlfaSix\Alfakit\Pwa\PushSubscription;
use AlfaSix\Alfakit\Pwa\PwaIcons;
use AlfaSix\Alfakit\Pwa\ServiceWorker;
use AlfaSix\Alfakit\Pwa\Vapid;
use AlfaSix\Alfakit\Pwa\WebPush;
use AlfaSix\Alfakit\Tests\Fixtures\Models\User;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

function pwaPanel(Closure $configure): Panel
{
    $panel = $configure(Panel::make('pwa')->path('field')->login()->brandName('Field Ops')->colors(['primary' => '#2563eb'])->middleware(['web']));

    alfakit()->registerPanel($panel);
    $panel->registerRoutes(app('router'));
    app('router')->getRoutes()->refreshNameLookups();

    return $panel;
}

function pwaSubscriptionsTable(): void
{
    PushSubscription::flushAvailability();
    (require __DIR__.'/../../database/migrations/0001_01_01_000500_create_alfakit_push_subscriptions_table.php')->up();
}

/**
 * A browser's subscription keys: [public point, private scalar, auth secret].
 *
 * @return array{0: string, 1: string, 2: string}
 */
function pwaBrowserKeys(): array
{
    [$public, $private] = Vapid::generateRawKeyPair();

    return [$public, $private, random_bytes(16)];
}

/** What a browser does with an `aes128gcm` push body (RFC 8291). */
function pwaDecrypt(string $body, string $userPublic, string $userPrivate, string $auth): string
{
    $salt = substr($body, 0, 16);
    $idLength = ord($body[20]);
    $serverPublic = substr($body, 21, $idLength);
    $encrypted = substr($body, 21 + $idLength);

    $secret = openssl_pkey_derive(Vapid::publicKeyResource($serverPublic), Vapid::privateKeyResource($userPrivate, $userPublic), 32);
    $prkKey = hash_hmac('sha256', (string) $secret, $auth, true);
    $ikm = hash_hmac('sha256', "WebPush: info\0".$userPublic.$serverPublic."\x01", $prkKey, true);
    $prk = hash_hmac('sha256', $ikm, $salt, true);
    $cek = substr(hash_hmac('sha256', "Content-Encoding: aes128gcm\0\x01", $prk, true), 0, 16);
    $nonce = substr(hash_hmac('sha256', "Content-Encoding: nonce\0\x01", $prk, true), 0, 12);

    $plain = openssl_decrypt(substr($encrypted, 0, -16), 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, substr($encrypted, -16));

    return rtrim((string) $plain, "\x02");
}

beforeEach(function (): void {
    $keys = Vapid::generateKeys();
    config()->set('alfakit.pwa.vapid', ['public_key' => $keys['publicKey'], 'private_key' => $keys['privateKey'], 'subject' => 'mailto:ops@example.com']);
});

it('is off by default: no routes, head tags or schema', function (): void {
    $panel = alfakit()->getPanel('admin');

    expect($panel->hasPwa())->toBeFalse()
        ->and((string) $panel->renderPwaHeadTags())->toBe('')
        ->and(app('router')->has('alfakit.admin.pwa.manifest'))->toBeFalse();

    $this->get('/admin/manifest.webmanifest')->assertNotFound();
    $this->get('/admin/sw.js')->assertNotFound();

    $user = User::query()->create(['name' => 'Ada', 'email' => 'ada@example.com', 'password' => 'password']);

    expect(inertiaProps(inertiaGet($this->actingAs($user), '/admin'))['alfakit'])->not->toHaveKey('pwa');
});

it('serves a manifest built from the brand, colours and locale', function (): void {
    pwaPanel(fn (Panel $panel): Panel => $panel->pwa(description: 'Jobs on the go'));

    $response = $this->get('/field/manifest.webmanifest')->assertOk();

    expect($response->headers->get('Content-Type'))->toContain('application/manifest+json');

    $manifest = $response->json();

    expect($manifest)->toMatchArray([
        'id' => '/field',
        'name' => 'Field Ops',
        'short_name' => 'Field Ops',
        'description' => 'Jobs on the go',
        'start_url' => '/field',
        'scope' => '/field',
        'display' => 'standalone',
        'theme_color' => '#2563eb',
        'background_color' => '#ffffff',
        'lang' => 'en',
        'dir' => 'ltr',
    ])->and(collect($manifest['icons'])->pluck('sizes')->all())->toContain('192x192', '512x512')
        ->and(collect($manifest['icons'])->pluck('purpose')->all())->toContain('maskable');
});

it('takes icons, names, colours and extra members from pwa()', function (): void {
    pwaPanel(fn (Panel $panel): Panel => $panel->pwa(
        icons: ['192x192' => '/icons/192.png', ['src' => '/icons/mask.png', 'sizes' => '512x512', 'purpose' => 'maskable']],
        name: 'Field Operations',
        shortName: 'Ops',
        themeColor: 'oklch(0.5 0.2 30)',
        backgroundColor: '#000000',
        manifest: ['categories' => ['business']],
    ));

    $manifest = $this->get('/field/manifest.webmanifest')->json();

    expect($manifest['name'])->toBe('Field Operations')
        ->and($manifest['short_name'])->toBe('Ops')
        ->and($manifest['theme_color'])->toMatch('/^#[0-9a-f]{6}$/')
        ->and($manifest['background_color'])->toBe('#000000')
        ->and($manifest['categories'])->toBe(['business'])
        ->and($manifest['icons'])->toBe([
            ['src' => '/icons/192.png', 'sizes' => '192x192', 'type' => 'image/png'],
            ['src' => '/icons/mask.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
        ]);
});

it('uses an SVG favicon as an icon and generates PNG fallbacks', function (): void {
    $panel = pwaPanel(fn (Panel $panel): Panel => $panel->favicon('/favicon.svg')->pwa());

    expect($panel->getPwaIcons()[0])->toBe(['src' => '/favicon.svg', 'sizes' => 'any', 'type' => 'image/svg+xml', 'purpose' => 'any']);

    $png = $this->get('/field/pwa/icon-512.png')->assertOk();

    expect($png->headers->get('Content-Type'))->toBe('image/png')
        ->and(getimagesizefromstring((string) $png->getContent())[0] ?? null)->toBe(512);

    $this->get('/field/pwa/icon-maskable-512.png')->assertOk();
    $this->get('/field/pwa/icon-64.png')->assertNotFound();

    expect(PwaIcons::monogramSvg($panel))->toContain('>F</text>');
});

it('serves the service worker with its scope header and configuration', function (): void {
    pwaPanel(fn (Panel $panel): Panel => $panel->pwa());

    $response = $this->get('/field/sw.js')->assertOk()->assertHeader('Service-Worker-Allowed', '/field');

    expect($response->headers->get('Content-Type'))->toContain('application/javascript')
        ->and($response->headers->get('Cache-Control'))->toContain('no-cache');

    $script = (string) $response->getContent();
    $config = json_decode(substr($script, strlen('const CONFIG = '), strpos($script, ";\n") - strlen('const CONFIG = ')), true);

    expect($config)->toMatchArray([
        'panel' => 'pwa',
        'scope' => '/field',
        'offline' => '/field/offline',
        'internal' => '_alfakit',
        'build' => ['/build/'],
    ])->and($config['version'])->toMatch('/^[0-9a-f]{12}$/')
        ->and($config['precache'])->toContain('/field/pwa/icon-192.png')
        ->and($script)->toContain("addEventListener('fetch'")
        ->and($script)->toContain('SKIP_WAITING');
});

it('precaches the panel entries from the Vite manifest', function (): void {
    $panel = pwaPanel(fn (Panel $panel): Panel => $panel->pwa()->viteTheme(['resources/css/app.css', 'resources/js/app.ts']));

    $directory = public_path('build');
    @mkdir($directory, 0755, true);
    file_put_contents($directory.'/manifest.json', json_encode([
        'resources/js/app.ts' => ['file' => 'assets/app-1.js', 'imports' => ['_vendor.js'], 'dynamicImports' => ['_lazy.js'], 'css' => ['assets/app-1.css']],
        'resources/css/app.css' => ['file' => 'assets/app-2.css'],
        '_vendor.js' => ['file' => 'assets/vendor-3.js'],
        '_lazy.js' => ['file' => 'assets/lazy-4.js'],
    ]));

    try {
        expect(ServiceWorker::appShell($panel))->toEqualCanonicalizing([
            '/build/assets/app-2.css',
            '/build/assets/app-1.js',
            '/build/assets/app-1.css',
            '/build/assets/vendor-3.js',
        ]);
    } finally {
        @unlink($directory.'/manifest.json');
    }
});

it('renders a translated offline page', function (): void {
    pwaPanel(fn (Panel $panel): Panel => $panel->pwa());

    $this->get('/field/offline')->assertOk()
        ->assertSee('Field Ops')
        ->assertSee('lang="en"', false)
        ->assertSee('data-slot="pwa-offline"', false);
});

it('adds head tags and the panel schema when enabled', function (): void {
    $panel = pwaPanel(fn (Panel $panel): Panel => $panel->pwa(darkThemeColor: '#111111'));

    $tags = (string) $panel->renderPwaHeadTags();

    expect($tags)->toContain('<link rel="manifest" href="/field/manifest.webmanifest">')
        ->toContain('media="(prefers-color-scheme: light)" content="#2563eb"')
        ->toContain('media="(prefers-color-scheme: dark)" content="#111111"')
        ->toContain('<link rel="apple-touch-icon" href="/field/pwa/icon-512.png">');

    $user = User::query()->create(['name' => 'Ada', 'email' => 'ada@example.com', 'password' => 'password']);
    $this->actingAs($user);

    expect(alfakit()->getPanel('pwa')->toSchema()['pwa'])->toBe(['serviceWorker' => '/field/sw.js', 'scope' => '/field']);
});

it('keeps the scope at the site root for a root panel', function (): void {
    $panel = Panel::make('root-pwa')->path('')->pwa();

    expect($panel->getPwaScope())->toBe('/');
});

it('generates VAPID keys and signs tokens that verify', function (): void {
    $keys = Vapid::generateKeys();

    expect(strlen(Vapid::base64UrlDecode($keys['publicKey'])))->toBe(65)
        ->and(strlen(Vapid::base64UrlDecode($keys['privateKey'])))->toBe(32);

    $header = Vapid::authorizationHeader('https://push.example.com/send/abc');

    expect($header)->toStartWith('vapid t=')->toEndWith(', k='.Vapid::publicKey());

    preg_match('/t=([^,]+)/', $header, $matches);
    [$jwtHeader, $claims, $signature] = explode('.', $matches[1]);

    expect(json_decode(Vapid::base64UrlDecode($claims), true))->toMatchArray(['aud' => 'https://push.example.com', 'sub' => 'mailto:ops@example.com'])
        ->and(openssl_verify($jwtHeader.'.'.$claims, Vapid::rawToDerSignature(Vapid::base64UrlDecode($signature)), Vapid::publicKeyResource(Vapid::base64UrlDecode(Vapid::publicKey())), OPENSSL_ALGO_SHA256))->toBe(1);
});

it('encrypts payloads a browser can decrypt', function (): void {
    [$public, $private, $auth] = pwaBrowserKeys();

    $body = WebPush::encrypt('{"title":"Hello"}', $public, $auth);

    expect(substr($body, 16, 4))->toBe(pack('N', 4096))
        ->and(pwaDecrypt($body, $public, $private, $auth))->toBe('{"title":"Hello"}');
});

it('stores and removes push subscriptions for the signed-in user', function (): void {
    pwaSubscriptionsTable();
    pwaPanel(fn (Panel $panel): Panel => $panel->pwa(push: true));

    [$public, , $auth] = pwaBrowserKeys();
    $user = User::query()->create(['name' => 'Ada', 'email' => 'ada@example.com', 'password' => 'password']);
    $subscription = ['endpoint' => 'https://push.example.com/send/abc', 'keys' => ['p256dh' => Vapid::base64UrlEncode($public), 'auth' => Vapid::base64UrlEncode($auth)]];

    $this->postJson('/field/_alfakit/pwa/push', $subscription)->assertUnauthorized();

    $this->actingAs($user);

    expect(alfakit()->getPanel('pwa')->toSchema()['pwa']['push'])->toBe(['publicKey' => Vapid::publicKey(), 'endpoint' => 'http://localhost/field/_alfakit/pwa/push']);

    $this->postJson('/field/_alfakit/pwa/push', $subscription)->assertOk()->assertJson(['subscribed' => true]);
    $this->postJson('/field/_alfakit/pwa/push', $subscription)->assertOk();

    expect(PushSubscription::query()->count())->toBe(1)
        ->and(PushSubscription::query()->first()?->panel)->toBe('pwa');

    $this->postJson('/field/_alfakit/pwa/push', ['endpoint' => 'http://insecure.test', 'keys' => ['p256dh' => 'x', 'auth' => 'y']])->assertUnprocessable();

    $this->deleteJson('/field/_alfakit/pwa/push', ['endpoint' => $subscription['endpoint']])->assertOk();

    expect(PushSubscription::query()->count())->toBe(0);
});

it('sends database notifications as Web Push and forgets gone subscriptions', function (): void {
    pwaSubscriptionsTable();
    pwaPanel(fn (Panel $panel): Panel => $panel->pwa(push: true));
    PushNotifications::flush();
    PushNotifications::listen();

    [$public, $private, $auth] = pwaBrowserKeys();
    $user = User::query()->create(['name' => 'Ada', 'email' => 'ada@example.com', 'password' => 'password']);

    foreach (['https://push.example.com/live', 'https://push.example.com/gone'] as $endpoint) {
        PushSubscription::query()->create([
            ...PushSubscription::owner($user),
            'panel' => 'pwa',
            'endpoint' => $endpoint,
            'endpoint_hash' => PushSubscription::hashEndpoint($endpoint),
            'public_key' => Vapid::base64UrlEncode($public),
            'auth_token' => Vapid::base64UrlEncode($auth),
        ]);
    }

    Http::fake([
        'push.example.com/live' => Http::response('', 201),
        'push.example.com/gone' => Http::response('', 410),
    ]);

    Notification::make()->title('Job assigned')->body('<b>Fix</b> the pump')->sendToDatabase($user);

    Http::assertSent(function (HttpRequest $request) use ($public, $private, $auth): bool {
        if ($request->url() !== 'https://push.example.com/live') {
            return false;
        }

        $payload = json_decode(pwaDecrypt($request->body(), $public, $private, $auth), true);

        return $request->hasHeader('Content-Encoding', 'aes128gcm')
            && str_starts_with($request->header('Authorization')[0] ?? '', 'vapid t=')
            && $payload['title'] === 'Job assigned'
            && $payload['body'] === 'Fix the pump'
            && $payload['url'] === '/field';
    });

    expect(PushSubscription::query()->pluck('endpoint')->all())->toBe(['https://push.example.com/live']);
});

it('sends nothing without push or keys', function (): void {
    pwaSubscriptionsTable();
    pwaPanel(fn (Panel $panel): Panel => $panel->pwa());
    $user = User::query()->create(['name' => 'Ada', 'email' => 'ada@example.com', 'password' => 'password']);

    PushSubscription::query()->create([...PushSubscription::owner($user), 'panel' => 'pwa', 'endpoint' => 'https://push.example.com/x', 'endpoint_hash' => 'x', 'public_key' => 'k', 'auth_token' => 'a']);
    Http::fake();

    expect(PushNotifications::send($user, ['title' => 'Hi']))->toBe(0);

    config()->set('alfakit.pwa.vapid.public_key', '');

    expect(Vapid::isConfigured())->toBeFalse()
        ->and(Schema::hasTable('alfakit_push_subscriptions'))->toBeTrue();

    Http::assertNothingSent();
});

it('writes VAPID keys to .env with alfakit:pwa-keys', function (): void {
    $env = base_path('.env');
    $example = base_path('.env.example');
    $backup = is_file($env) ? file_get_contents($env) : null;
    $exampleBackup = is_file($example) ? file_get_contents($example) : null;
    file_put_contents($env, "APP_NAME=Test\n");

    try {
        $this->artisan('alfakit:pwa-keys')->assertSuccessful();

        $contents = (string) file_get_contents($env);

        expect($contents)->toMatch('/ALFAKIT_PWA_VAPID_PUBLIC_KEY=[A-Za-z0-9_-]{87}/')
            ->toMatch('/ALFAKIT_PWA_VAPID_PRIVATE_KEY=[A-Za-z0-9_-]{43}/');

        $this->artisan('alfakit:pwa-keys')->expectsOutputToContain('already set')->assertSuccessful();
        $this->artisan('alfakit:pwa-keys --show')->expectsOutputToContain('ALFAKIT_PWA_VAPID_PUBLIC_KEY=')->assertSuccessful();
    } finally {
        $backup === null ? @unlink($env) : file_put_contents($env, $backup);
        $exampleBackup === null ? @unlink($example) : file_put_contents($example, $exampleBackup);
    }
});
