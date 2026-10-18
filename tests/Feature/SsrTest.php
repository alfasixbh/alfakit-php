<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Commands\InstallCommand;
use Inertia\Testing\AssertableInertia;

// ---------------------------------------------------------------- Sidebar state

it('shares the stored sidebar state so a server render starts with the same sidebar', function (): void {
    $this->get('/admin/login')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('alfakitSidebarOpen', null));

    $this->withUnencryptedCookie('alfakit_sidebar_state', 'false')->get('/admin/login')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('alfakitSidebarOpen', false));

    $this->withUnencryptedCookie('alfakit_sidebar_state', 'true')->get('/admin/login')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('alfakitSidebarOpen', true));

    $this->withUnencryptedCookie('alfakit_sidebar_state', '<script>')->get('/admin/login')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('alfakitSidebarOpen', null));
});

it('keeps the sidebar cookie readable through the encrypted-cookie middleware', function (): void {
    $this->withCredentials()->call('GET', '/admin/login', [], ['alfakit_sidebar_state' => 'false'])
        ->assertInertia(fn (AssertableInertia $page) => $page->where('alfakitSidebarOpen', false));
});

// ---------------------------------------------------------------- Installer

it('points the Vite config at the SSR entry', function (): void {
    $config = <<<'JS'
        export default defineConfig({
            plugins: [
                laravel({
                    input: ['resources/css/app.css', 'resources/js/app.tsx'],
                    refresh: true,
                }),
                inertia(),
                react(),
            ],
        })
        JS;

    $updated = InstallCommand::addViteSsrEntry($config, 'resources/js/alfakit/ssr.tsx');

    expect($updated)
        ->toContain("input: ['resources/css/app.css', 'resources/js/app.tsx'],\n            ssr: 'resources/js/alfakit/ssr.tsx',")
        ->toContain("inertia({ ssr: 'resources/js/alfakit/ssr.tsx' })")
        ->and(InstallCommand::addViteSsrEntry((string) $updated, 'resources/js/alfakit/ssr.tsx'))->toBe($updated)
        ->and(InstallCommand::addViteSsrEntry('export default {}', 'resources/js/alfakit/ssr.tsx'))->toBeNull();
});

it('ships an SSR entry stub for every adapter', function (string $adapter, string $inertia): void {
    $stub = file_get_contents(dirname(__DIR__, 2)."/stubs/frontend/{$adapter}/ssr.stub");

    expect($stub)
        ->toContain("import createServer from '{$inertia}/server';")
        ->toContain("import { createAlfakitServer } from '@alfakit/{$adapter}/server';")
        ->toContain('createServer(');
})->with([
    ['react', '@inertiajs/react'],
    ['vue', '@inertiajs/vue3'],
    ['svelte', '@inertiajs/svelte'],
]);
