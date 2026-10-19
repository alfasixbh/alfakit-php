<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Commands;

use AlfaSix\Alfakit\Commands\Concerns\CanGenerateFiles;
use AlfaSix\Alfakit\Commands\Concerns\DetectsFrontendAdapter;
use AlfaSix\Alfakit\Commands\Support\AppEnvironment;
use AlfaSix\Alfakit\Commands\Support\PanelProviderEditor;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Console\Attribute\AsCommand;

use function Laravel\Prompts\confirm;

/**
 * Set up the app auth kit: a service provider that configures it, `AuthKit::routes()` in
 * routes/web.php and the two Inertia pages that render it (guest pages and the account
 * page), in the starter kit's layouts when there is one. With Laravel Fortify installed
 * it offers to keep Fortify's backend (its actions and two-factor authentication) and only
 * use AlfaKit's UI. Running it again only writes what is missing.
 */
#[AsCommand(name: 'alfakit:auth', description: 'Add AlfaKit sign-in, registration, password reset, two-step verification and account pages to your app')]
class AuthCommand extends Command
{
    use CanGenerateFiles;
    use DetectsFrontendAdapter;

    protected $signature = 'alfakit:auth
        {--adapter= : react, vue or svelte (detected from package.json)}
        {--fortify : Use Laravel Fortify\'s backend (its actions and two-factor authentication) with AlfaKit\'s UI}
        {--no-fortify : Never use Fortify, even when it is installed}
        {--mfa : Turn on two-step verification (authenticator app with recovery codes, emailed codes) and write its migration}
        {--prefix= : A path prefix for every auth route (e.g. account)}
        {--home=/dashboard : Where users go after signing in}
        {--force : Overwrite existing files}';

    public function handle(Filesystem $files): int
    {
        $adapter = $this->resolveAdapter();

        if ($adapter === null) {
            return self::FAILURE;
        }

        $force = (bool) $this->option('force');
        $fortify = $this->shouldUseFortify();
        $mfa = (bool) $this->option('mfa');
        $starterKit = AppEnvironment::starterKitLayout() !== null;

        [$guestPage, $accountPage] = $adapter === 'react' ? ['auth/alfakit', 'settings/alfakit'] : ['auth/Alfakit', 'settings/Alfakit'];
        $extension = ['react' => 'tsx', 'vue' => 'vue', 'svelte' => 'svelte'][$adapter];

        $this->writeProvider($files, $guestPage, $accountPage, $fortify, $mfa, $force);
        $this->writeRoutes($files);

        $this->writeStub("auth-kit/guest-{$adapter}", resource_path("js/pages/{$guestPage}.{$extension}"), ['guestBody' => $this->guestBody($adapter, $starterKit)], $force);
        $this->writeStub("auth-kit/account-{$adapter}", resource_path("js/pages/{$accountPage}.{$extension}"), ['accountBody' => $this->accountBody($adapter, $starterKit)], $force);

        if ($mfa) {
            $this->writeMigration($files);
        }

        // The auth kit's pages take Laravel's route names (`login`, `verification.notice`,
        // `two-factor.login`…), so Fortify's view routes must go whether or not its backend is
        // kept: two routes with one name break `route()` and Wayfinder's generated exports.
        if (AppEnvironment::hasFortify()) {
            $this->turnOffFortifyViews($files);
        }

        $base = ($prefix = trim((string) $this->option('prefix'), '/')) === '' ? '' : '/'.$prefix;

        $this->components->info("The auth kit is ready: {$base}/login, {$base}/register, {$base}/forgot-password, {$base}/account. Build your assets (npm run build) to see it.");

        if ($starterKit) {
            $this->components->bulletList([
                'Your starter kit lays out auth/* pages in its AuthLayout and settings/* pages in its settings layout.',
                'Link the account page from your settings navigation: route(\'account\').',
            ]);
        }

        return self::SUCCESS;
    }

    protected function shouldUseFortify(): bool
    {
        if ($this->option('no-fortify') || ! AppEnvironment::hasFortify()) {
            return false;
        }

        if ($this->option('fortify') || ! $this->input->isInteractive()) {
            return (bool) $this->option('fortify');
        }

        return confirm('Laravel Fortify is installed. Keep Fortify\'s backend (its actions and two-factor authentication) and use AlfaKit only for the UI?', true);
    }

    protected function writeProvider(Filesystem $files, string $guestPage, string $accountPage, bool $fortify, bool $mfa, bool $force): void
    {
        $options = [];
        $imports = '';

        if ($fortify) {
            $options[] = '            ->fortify()';
        }

        if ($mfa) {
            $imports = "use AlfaSix\\Alfakit\\Auth\\MultiFactor\\App\\AppAuthentication;\nuse AlfaSix\\Alfakit\\Auth\\MultiFactor\\Email\\EmailAuthentication;\n";
            $options[] = '            ->multiFactorAuthentication([AppAuthentication::make()->recoverable(), EmailAuthentication::make()])';
        }

        $prefix = trim((string) $this->option('prefix'), '/');

        if ($prefix !== '') {
            $options[] = "            ->routeOptions(prefix: '{$prefix}')";
        }

        $written = $this->writeStub('auth-kit/provider', app_path('Providers/AuthKitServiceProvider.php'), [
            'imports' => $imports,
            'guestPage' => $guestPage,
            'accountPage' => $accountPage,
            'home' => (string) $this->option('home'),
            'options' => $options === [] ? '' : implode("\n", $options)."\n",
        ], $force);

        if ($written || $force) {
            $this->registerProvider($files, 'App\\Providers\\AuthKitServiceProvider');
        }
    }

    protected function registerProvider(Filesystem $files, string $provider): void
    {
        $path = base_path('bootstrap/providers.php');

        if (! $files->exists($path)) {
            $this->components->warn("Register {$provider} in your providers list.");

            return;
        }

        $contents = $files->get($path);

        if (str_contains($contents, $provider)) {
            return;
        }

        $updated = PanelProviderEditor::addBootstrapProvider($contents, $provider);

        if ($updated !== null) {
            $files->put($path, $updated);
            $this->components->task("Registered {$provider} in bootstrap/providers.php");
        }
    }

    protected function writeRoutes(Filesystem $files): void
    {
        $path = base_path('routes/web.php');

        if (! $files->exists($path)) {
            $this->components->warn('Add \\AlfaSix\\Alfakit\\AuthKit\\AuthKit::routes(); to your web routes.');

            return;
        }

        $contents = $files->get($path);

        if (str_contains($contents, 'AuthKit::routes(')) {
            return;
        }

        $files->put($path, rtrim($contents)."\n\n// alfakit:auth (AlfaKit's sign-in, registration, password reset and account pages)\n\\AlfaSix\\Alfakit\\AuthKit\\AuthKit::routes();\n");
        $this->components->task('Added AuthKit::routes() to routes/web.php');
    }

    protected function writeMigration(Filesystem $files): void
    {
        $existing = $files->glob(database_path('migrations/*_add_alfakit_multi_factor_columns_to_users_table.php'));

        if ($existing !== []) {
            return;
        }

        $this->writeStub('auth-kit/migration', database_path('migrations/'.date('Y_m_d_His').'_add_alfakit_multi_factor_columns_to_users_table.php'), [], false);
        $this->components->bulletList(['Add HasAppAuthentication, HasAppAuthenticationRecovery and HasEmailAuthentication (with their InteractsWith… traits) to your User model, then run php artisan migrate.']);
    }

    /**
     * Fortify keeps its POST routes and actions; its pages (and their route names) become
     * AlfaKit's.
     */
    protected function turnOffFortifyViews(Filesystem $files): void
    {
        $path = config_path('fortify.php');

        if (! $files->exists($path)) {
            return;
        }

        $contents = $files->get($path);
        $updated = preg_replace("/'views'\s*=>\s*true/", "'views' => false", $contents, 1);

        if ($updated !== null && $updated !== $contents) {
            $files->put($path, $updated);
            $this->components->task("Set 'views' => false in config/fortify.php (AlfaKit renders the pages)");
        }

        $this->components->bulletList([
            'Remove the starter kit\'s own auth pages and routes you no longer use (resources/js/pages/auth/*, Fortify::loginView() and the other view callbacks).',
        ]);
    }

    protected function guestBody(string $adapter, bool $starterKit): string
    {
        return match ($adapter) {
            'react' => $starterKit
                ? '            <AlfakitComponent component={component} />'
                : "            <main className=\"flex min-h-svh items-center justify-center p-6 md:p-10\">\n                <div className=\"w-full max-w-sm\">\n                    <AlfakitComponent component={component} />\n                </div>\n            </main>",
            'vue' => $starterKit
                ? '    <AlfakitComponent :component="component" />'
                : "    <main class=\"flex min-h-svh items-center justify-center p-6 md:p-10\">\n        <div class=\"w-full max-w-sm\">\n            <AlfakitComponent :component=\"component\" />\n        </div>\n    </main>",
            default => $starterKit
                ? '<AlfakitComponent {component} />'
                : "<main class=\"flex min-h-svh items-center justify-center p-6 md:p-10\">\n    <div class=\"w-full max-w-sm\">\n        <AlfakitComponent {component} />\n    </div>\n</main>",
        };
    }

    protected function accountBody(string $adapter, bool $starterKit): string
    {
        return match ($adapter) {
            'react' => $starterKit
                ? "            {Object.entries(sections).map(([key, section]) => (\n                <AlfakitComponent key={key} component={section} />\n            ))}"
                : "            <main className=\"mx-auto flex w-full max-w-2xl flex-col gap-10 p-4 md:p-6\">\n                <h1 className=\"text-2xl font-semibold\">{authKit.title}</h1>\n                {Object.entries(sections).map(([key, section]) => (\n                    <AlfakitComponent key={key} component={section} />\n                ))}\n            </main>",
            'vue' => $starterKit
                ? '    <AlfakitComponent v-for="(section, key) in sections" :key="key" :component="section" />'
                : "    <main class=\"mx-auto flex w-full max-w-2xl flex-col gap-10 p-4 md:p-6\">\n        <h1 class=\"text-2xl font-semibold\">{{ authKit.title }}</h1>\n        <AlfakitComponent v-for=\"(section, key) in sections\" :key=\"key\" :component=\"section\" />\n    </main>",
            default => $starterKit
                ? "{#each Object.entries(sections) as [key, section] (key)}\n    <AlfakitComponent component={section} />\n{/each}"
                : "<main class=\"mx-auto flex w-full max-w-2xl flex-col gap-10 p-4 md:p-6\">\n    <h1 class=\"text-2xl font-semibold\">{authKit.title}</h1>\n    {#each Object.entries(sections) as [key, section] (key)}\n        <AlfakitComponent component={section} />\n    {/each}\n</main>",
        };
    }
}
