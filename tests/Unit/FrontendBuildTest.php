<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Commands\Support\AppEnvironment;
use AlfaSix\Alfakit\Support\ViteBuildHint;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\ViteException;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Illuminate\View\ViewException;

/*
 * The installer builds the frontend after adding AlfaKit's Vite entries, and a missing
 * AlfaKit entry explains how to build it, also from a starter kit's own layout.
 */

beforeEach(function (): void {
    $this->sandbox = sys_get_temp_dir().'/alfakit-build-'.uniqid();
    (new Filesystem)->ensureDirectoryExists($this->sandbox.'/public/build');
    $this->originalBasePath = app()->basePath();
    app()->setBasePath($this->sandbox);
    app()->usePublicPath($this->sandbox.'/public');
});

afterEach(function (): void {
    app()->setBasePath($this->originalBasePath);
    app()->usePublicPath($this->originalBasePath.'/public');
    (new Filesystem)->deleteDirectory($this->sandbox);
});

it('builds with the app\'s package manager', function (): void {
    expect(AppEnvironment::npmBuildCommand())->toBe(['npm', 'run', 'build']);

    touch($this->sandbox.'/pnpm-lock.yaml');
    expect(AppEnvironment::npmBuildCommand())->toBe(['pnpm', 'run', 'build']);

    unlink($this->sandbox.'/pnpm-lock.yaml');
    touch($this->sandbox.'/yarn.lock');
    expect(AppEnvironment::npmBuildCommand())->toBe(['yarn', 'build']);

    Process::fake();
    expect(AppEnvironment::npmBuild()->successful())->toBeTrue();
    Process::assertRan(fn (PendingProcess $process): bool => $process->command === ['yarn', 'build'] && $process->path === base_path());
});

it('knows whether the frontend serves AlfaKit\'s entries', function (): void {
    expect(AppEnvironment::frontendIncludesAlfakit())->toBeFalse();

    // A starter kit build from before the install.
    file_put_contents(public_path('build/manifest.json'), json_encode(['resources/js/app.tsx' => ['file' => 'app.js']]));
    expect(AppEnvironment::frontendIncludesAlfakit())->toBeFalse();

    file_put_contents(public_path('build/manifest.json'), json_encode(['resources/js/pages/Alfakit/Page.tsx' => ['file' => 'page.js']]));
    expect(AppEnvironment::frontendIncludesAlfakit())->toBeTrue();

    unlink(public_path('build/manifest.json'));
    touch(public_path('hot'));
    expect(AppEnvironment::viteDevServerRunning())->toBeTrue()
        ->and(AppEnvironment::frontendIncludesAlfakit())->toBeTrue();
});

it('treats a public/hot whose dev server does not answer as stale', function (): void {
    // A port nothing listens on: the dev server that wrote the file is gone.
    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) substr((string) stream_socket_get_name($socket, false), strrpos((string) stream_socket_get_name($socket, false), ':') + 1);
    file_put_contents(public_path('hot'), "http://127.0.0.1:{$port}");

    expect(AppEnvironment::viteHotFileIsStale())->toBeFalse()
        ->and(AppEnvironment::viteDevServerRunning())->toBeTrue();

    fclose($socket);

    expect(AppEnvironment::viteHotFileIsStale())->toBeTrue()
        ->and(AppEnvironment::viteDevServerRunning())->toBeFalse()
        ->and(AppEnvironment::frontendIncludesAlfakit())->toBeFalse();

    unlink(public_path('hot'));
});

it('explains a missing AlfaKit Vite entry, also when a view wraps it', function (): void {
    $vite = new ViteException('Unable to locate file in Vite manifest: resources/js/pages/Alfakit/Page.tsx.');
    $other = new ViteException('Unable to locate file in Vite manifest: resources/js/app.tsx.');

    expect(ViteBuildHint::explain($vite)->getMessage())->toContain('npm run build')->toContain('alfakit:doctor')
        // Not an AlfaKit entry, or already explained: unchanged.
        ->and(ViteBuildHint::explain($other))->toBe($other)
        ->and(ViteBuildHint::explain(ViteBuildHint::explain($vite))->getMessage())->toBe(ViteBuildHint::explain($vite)->getMessage());

    // A local app shows the message (debug mode); production hides it anyway.
    config(['app.debug' => true]);
    $handler = app(ExceptionHandler::class);
    $rendered = $handler->render(request(), new ViewException('Unable to locate file in Vite manifest: resources/js/pages/Alfakit/Page.tsx. (View: app.blade.php)', 0, 1, '', 0, $vite));

    expect((string) $rendered->getContent())->toContain('npm run build');
});
