<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Panel;
use AlfaSix\Alfakit\Support\Assets\Assets;
use AlfaSix\Alfakit\Support\Assets\Css;
use AlfaSix\Alfakit\Support\Assets\Js;
use AlfaSix\Alfakit\Theming\Theme;
use AlfaSix\Alfakit\Theming\Themes;
use Illuminate\Filesystem\Filesystem;

/*
 * Generators for themes, custom fields/columns/entries and plugins, plugin assets, and
 * alfakit:upgrade. Each test works in its own temporary application directory so the
 * generated files never touch the shared Testbench skeleton.
 */

beforeEach(function (): void {
    $this->sandbox = sys_get_temp_dir().'/alfakit-generators-'.uniqid();
    $files = new Filesystem;
    $files->ensureDirectoryExists($this->sandbox.'/app/Providers/Alfakit');
    $files->ensureDirectoryExists($this->sandbox.'/resources/js/alfakit');
    $files->ensureDirectoryExists($this->sandbox.'/resources/css');
    $files->ensureDirectoryExists($this->sandbox.'/public');

    $this->originalBasePath = $this->app->basePath();
    $this->app->setBasePath($this->sandbox);
    $this->app->usePublicPath($this->sandbox.'/public');
    config(['alfakit.file_generation.path' => $this->sandbox.'/app/Alfakit', 'alfakit.file_generation.namespace' => 'App\\Alfakit']);

    Assets::flush();
});

afterEach(function (): void {
    Assets::flush();
    Themes::flush();
    $this->app->setBasePath($this->originalBasePath);
    $this->app->usePublicPath($this->originalBasePath.'/public');
    (new Filesystem)->deleteDirectory($this->sandbox);
});

function writeSandbox(string $path, string $contents): void
{
    (new Filesystem)->ensureDirectoryExists(dirname(base_path($path)));
    file_put_contents(base_path($path), $contents);
}

function reactEntry(): string
{
    return <<<'TS'
import { createAlfakit } from '@alfakit/react';
import {
    something,
} from './elsewhere';

void createAlfakit({
    components: {
        // 'map-picker': MapPicker,
    },
    pages: {},
});
TS;
}

// ---------------------------------------------------------------- Fields, columns, entries

it('generates a custom field with a React component registered in the entry', function (): void {
    writeSandbox('resources/js/alfakit/app.tsx', reactEntry());

    $this->artisan('make:alfakit-field', ['name' => 'RatingInput', '--adapter' => 'react'])->assertSuccessful();

    $class = file_get_contents(app_path('Alfakit/Forms/Components/RatingInput.php'));
    $component = file_get_contents(resource_path('js/alfakit/fields/RatingInput.tsx'));
    $entry = file_get_contents(resource_path('js/alfakit/app.tsx'));

    expect($class)
        ->toContain('namespace App\\Alfakit\\Forms\\Components;')
        ->toContain('class RatingInput extends Field')
        ->toContain("protected ?string \$type = 'RatingInput';")
        ->and($component)
        ->toContain("import { FieldWrapper, useField, type FieldProps } from '@alfakit/react'")
        ->toContain('export default function RatingInput(')
        ->toContain('App\\Alfakit\\Forms\\Components\\RatingInput')
        // The theme's input design (filled, underline) styles it like the built-in inputs.
        ->toContain('data-slot="input"')
        ->and($entry)
        ->toContain("} from './elsewhere';\nimport RatingInput from './fields/RatingInput';")
        ->toContain("components: {\n        'RatingInput': RatingInput,");

    // The generated class works as a field.
    require_once app_path('Alfakit/Forms/Components/RatingInput.php');

    expect(App\Alfakit\Forms\Components\RatingInput::make('rating')->toSchema())
        ->toMatchArray(['type' => 'RatingInput', 'statePath' => 'rating']);

    // Running it again doesn't register the component twice.
    $this->artisan('make:alfakit-field', ['name' => 'RatingInput', '--adapter' => 'react', '--force' => true])->assertSuccessful();

    expect(substr_count(file_get_contents(resource_path('js/alfakit/app.tsx')), "'RatingInput'"))->toBe(1);
});

it('generates a custom column for Vue under its column registry key', function (): void {
    writeSandbox('resources/js/alfakit/app.ts', str_replace('@alfakit/react', '@alfakit/vue', reactEntry()));

    $this->artisan('make:alfakit-column', ['name' => 'ProgressBar', '--adapter' => 'vue'])->assertSuccessful();

    require_once app_path('Alfakit/Tables/Columns/ProgressBar.php');

    expect(App\Alfakit\Tables\Columns\ProgressBar::make('progress')->getType())->toBe('ProgressBar')
        ->and(file_get_contents(resource_path('js/alfakit/columns/ProgressBar.vue')))
        ->toContain("from '@alfakit/vue'")
        ->toContain('column: BaseColumnSchema')
        ->and(file_get_contents(resource_path('js/alfakit/app.ts')))
        ->toContain("import ProgressBar from './columns/ProgressBar.vue';")
        ->toContain("'column:ProgressBar': ProgressBar,");
});

it('generates a custom entry for the adapter in package.json, or only the PHP class', function (): void {
    writeSandbox('package.json', json_encode(['dependencies' => ['@alfakit/svelte' => '^1.0']]));

    $this->artisan('make:alfakit-entry', ['name' => 'StarRating'])
        ->expectsOutputToContain("'StarRating'")
        ->assertSuccessful();

    require_once app_path('Alfakit/Infolists/Components/StarRating.php');

    expect(App\Alfakit\Infolists\Components\StarRating::make('stars')->toSchema()['type'])->toBe('StarRating')
        ->and(file_get_contents(resource_path('js/alfakit/entries/StarRating.svelte')))
        ->toContain("import { EntryWrapper, type EntryProps } from '@alfakit/svelte'");

    $this->artisan('make:alfakit-entry', ['name' => 'PlainEntry', '--no-frontend' => true])->assertSuccessful();

    expect(file_exists(app_path('Alfakit/Infolists/Components/PlainEntry.php')))->toBeTrue()
        ->and(file_exists(resource_path('js/alfakit/entries/PlainEntry.svelte')))->toBeFalse();
});

it('formats the generated component and the edited entry with the app formatter', function (): void {
    Illuminate\Support\Facades\Process::fake();
    writeSandbox('resources/js/alfakit/app.tsx', reactEntry());
    writeSandbox('node_modules/.bin/vp', '');
    writeSandbox('vite.config.ts', "export default defineConfig({ fmt: { tabWidth: 4, semi: true } })\n");

    $this->artisan('make:alfakit-field', ['name' => 'RatingInput', '--adapter' => 'react'])->assertSuccessful();

    $formatted = fn (string $path) => fn (Illuminate\Process\PendingProcess $process): bool => $process->command === [base_path('node_modules/.bin/vp'), 'fmt', $path];

    Illuminate\Support\Facades\Process::assertRan($formatted(resource_path('js/alfakit/fields/RatingInput.tsx')));
    Illuminate\Support\Facades\Process::assertRan($formatted(resource_path('js/alfakit/app.tsx')));
    // PHP classes are Pint's business.
    Illuminate\Support\Facades\Process::assertDidntRun(fn (Illuminate\Process\PendingProcess $process): bool => str_ends_with((string) end($process->command), '.php'));
});

it('writes auto-registered components without touching an entry that discovers them', function (): void {
    $entry = file_get_contents(dirname(__DIR__, 2).'/stubs/frontend/react/app.stub');
    writeSandbox('resources/js/alfakit/app.tsx', $entry);

    $this->artisan('make:alfakit-field', ['name' => 'ColorWheel', '--adapter' => 'react'])
        ->expectsOutputToContain("Auto-registered 'ColorWheel' from resources/js/alfakit/fields/ColorWheel.tsx")
        ->assertSuccessful();
    $this->artisan('make:alfakit-column', ['name' => 'ProgressBar', '--adapter' => 'react'])->assertSuccessful();
    $this->artisan('make:alfakit-widget', ['name' => 'RevenueCard', '--type' => 'custom', '--adapter' => 'react'])->assertSuccessful();

    // Under the default panel's folder when the test app has several panels.
    $widget = collect([app_path('Alfakit/Widgets/RevenueCard.php'), ...glob(app_path('Alfakit/*/Widgets/RevenueCard.php')) ?: []])
        ->first(static fn (string $path): bool => file_exists($path));

    expect(file_get_contents(resource_path('js/alfakit/app.tsx')))->toBe($entry)
        ->and(file_get_contents(resource_path('js/alfakit/fields/ColorWheel.tsx')))
        ->toContain('export default function ColorWheel({ component }: FieldProps)')
        ->and(file_get_contents(resource_path('js/alfakit/columns/ProgressBar.tsx')))->toContain('({ cell }: ColumnProps)')
        ->and(file_get_contents(resource_path('js/alfakit/widgets/RevenueCard.tsx')))
        ->toContain('export default function RevenueCard({ message }: WidgetProps<{ message: string }>)')
        ->and(file_get_contents((string) $widget))
        ->toContain("protected static ?string \$component = 'RevenueCard';")
        ->toContain('@return array{message: string}');
});

it('scaffolds a field around an installed shadcn component', function (string $adapter, string $control, string $rule): void {
    $installed = $adapter === 'react' ? 'resources/js/components/ui/slider.tsx' : 'resources/js/components/ui/slider/index.ts';
    writeSandbox($installed, "export const Slider = {}\n");

    $this->artisan('make:alfakit-field', ['name' => 'Volume', '--adapter' => $adapter, '--from-shadcn' => 'slider'])
        ->doesntExpectOutputToContain('is not installed')
        ->assertSuccessful();

    $extension = ['react' => 'tsx', 'vue' => 'vue', 'svelte' => 'svelte'][$adapter];

    expect(file_get_contents(resource_path("js/alfakit/fields/Volume.{$extension}")))
        ->toContain("import { Slider } from '@/components/ui/slider'")
        ->toContain($control)
        ->toContain('useField<number | null>')
        ->and(file_get_contents(app_path('Alfakit/Forms/Components/Volume.php')))
        ->toContain("return ['{$rule}'];")
        ->toContain("protected ?string \$type = 'Volume';");
})->with([
    'react' => ['react', 'onValueChange={([next]) => setValue(next ?? null)}', 'numeric'],
    'vue' => ['vue', '@update:model-value="(next) => setValue(next?.[0] ?? null)"', 'numeric'],
    'svelte' => ['svelte', 'bind:value={() => field.value ?? 0, (value) => field.setValue(value)}', 'numeric'],
]);

it('warns about shadcn components that are missing or have no ready-made binding', function (): void {
    $this->artisan('make:alfakit-field', ['name' => 'Rating', '--adapter' => 'react', '--from-shadcn' => 'rating-stars'])
        ->expectsOutputToContain('resources/js/components/ui/rating-stars is not installed')
        ->expectsOutputToContain("No ready-made binding for 'rating-stars'")
        ->assertSuccessful();

    expect(file_get_contents(resource_path('js/alfakit/fields/Rating.tsx')))
        ->toContain("import { RatingStars } from '@/components/ui/rating-stars'")
        ->toContain('<RatingStars id={id}');
});

it('generates TypeScript props types from the array shapes of custom components', function (): void {
    writeSandbox('app/Alfakit/Forms/Components/SwatchPicker.php', <<<'PHP'
<?php

namespace App\Alfakit\Forms\Components;

use AlfaSix\Alfakit\Forms\Components\Field;

class SwatchPicker extends Field
{
    /**
     * @return array{
     *     swatches: list<string>,
     *     size?: int|null,
     *     'data-mode': 'light'|'dark',
     * } The options.
     */
    protected function getSchemaProps(): array
    {
        return ['swatches' => [], 'data-mode' => 'light'];
    }
}
PHP);
    writeSandbox('app/Alfakit/Tables/Columns/Meter.php', <<<'PHP'
<?php

namespace App\Alfakit\Tables\Columns;

use AlfaSix\Alfakit\Tables\Columns\Column;

class Meter extends Column
{
    /** @return array{max: int} */
    protected function getSchemaProps(): array
    {
        return ['max' => 100];
    }

    /** @return array{percent: float} */
    protected function getCellProps(): array
    {
        return ['percent' => 0.5];
    }
}
PHP);
    writeSandbox('app/Alfakit/Tables/Columns/StatusColumn.php', <<<'PHP'
<?php

namespace App\Alfakit\Tables\Columns;

use AlfaSix\Alfakit\Tables\Columns\IconColumn;

class StatusColumn extends IconColumn {}
PHP);
    writeSandbox('app/Alfakit/Widgets/Welcome.php', <<<'PHP'
<?php

namespace App\Alfakit\Widgets;

use AlfaSix\Alfakit\Widgets\Widget;

class Welcome extends Widget
{
    protected static ?string $component = 'welcome-card';

    /** @return array{name: string, tips: array<string, bool>} */
    protected function getViewData(): array
    {
        return ['name' => 'Ada', 'tips' => []];
    }
}
PHP);
    writeSandbox('app/Models/Post.php', "<?php\n\nnamespace App\\Models;\n\nclass Post {}\n");

    $this->artisan('alfakit:types', ['--adapter' => 'vue'])
        ->expectsOutputToContain('Wrote 3 component types to resources/js/alfakit/types.d.ts')
        ->assertSuccessful();

    expect(file_get_contents(resource_path('js/alfakit/types.d.ts')))
        ->toContain("import type { ColumnProps, FieldProps, WidgetProps } from '@alfakit/vue'")
        ->toContain('export type SwatchPickerProps = FieldProps<{ swatches: string[]; size?: number | null; "data-mode": "light" | "dark" }>')
        ->toContain("export type SwatchPickerSchema = SwatchPickerProps['component']")
        ->toContain('/** App\\Alfakit\\Tables\\Columns\\Meter (registry key `column:Meter`) */')
        ->toContain('export type MeterProps = ColumnProps<{ max: number }, { percent: number }>')
        ->toContain('export type WelcomeProps = WidgetProps<{ name: string; tips: Record<string, boolean> }>')
        ->toContain('  "welcome-card": WelcomeProps')
        ->toContain('  SwatchPicker: SwatchPickerProps')
        ->not->toContain('StatusColumn');
});

it('rejects unknown adapters', function (): void {
    $this->artisan('make:alfakit-field', ['name' => 'Odd', '--adapter' => 'angular'])->assertFailed();
});

// ---------------------------------------------------------------- Themes

it('generates a theme, imports its CSS and sets it on the panel', function (): void {
    writeSandbox('resources/css/alfakit.css', "@import 'tailwindcss';\n@import '@alfakit/react/styles.css';\n\n@source '../js/alfakit';\n");
    writeSandbox('app/Providers/Alfakit/AdminPanelProvider.php', "<?php\n\nclass AdminPanelProvider\n{\n    public function panel(\$panel)\n    {\n        return \$panel\n            ->id('admin');\n    }\n}\n");

    $this->artisan('make:alfakit-theme', ['name' => 'Acme', '--extends' => 'alfakit', '--panel' => 'admin'])->assertSuccessful();

    expect(file_get_contents(resource_path('css/alfakit/themes/acme.css')))
        ->toContain(":root[data-alfakit-theme~='acme']")
        ->toContain(":root.dark[data-alfakit-theme~='acme']")
        ->and(file_get_contents(resource_path('css/alfakit.css')))
        ->toContain("@import '@alfakit/react/styles.css';\n@import './alfakit/themes/acme.css';")
        ->and(file_get_contents(app_path('Providers/Alfakit/AdminPanelProvider.php')))
        ->toContain("return \$panel\n            ->theme(\\App\\Alfakit\\Themes\\AcmeTheme::make())\n            ->id('admin');");

    require_once app_path('Alfakit/Themes/AcmeTheme.php');

    $theme = App\Alfakit\Themes\AcmeTheme::make();
    $panel = Panel::make()->id('themed')->theme($theme);

    expect($theme)->toBeInstanceOf(Theme::class)
        ->and($theme->getExtends())->toBe('alfakit')
        ->and($panel->getTheme()->getName())->toBe('acme')
        ->and(Themes::lineage('acme'))->toHaveCount(2);

    // Idempotent: a second run imports and registers nothing twice.
    $this->artisan('make:alfakit-theme', ['name' => 'acme', '--extends' => 'alfakit', '--panel' => 'admin', '--force' => true])->assertSuccessful();

    expect(substr_count(file_get_contents(resource_path('css/alfakit.css')), 'themes/acme.css'))->toBe(1)
        ->and(substr_count(file_get_contents(app_path('Providers/Alfakit/AdminPanelProvider.php')), '->theme('))->toBe(1);
});

it('refuses unknown base themes and built-in names', function (): void {
    $this->artisan('make:alfakit-theme', ['name' => 'acme', '--extends' => 'nope'])->assertFailed();
    $this->artisan('make:alfakit-theme', ['name' => 'minimal', '--extends' => 'neutral'])->assertFailed();
});

// ---------------------------------------------------------------- Plugins and assets

it('generates a plugin package with a frontend', function (): void {
    $this->artisan('make:alfakit-plugin', ['name' => 'BlogTools', '--vendor' => 'acme', '--frontend' => 'react'])->assertSuccessful();

    $root = base_path('packages/blog-tools');
    $composer = json_decode(file_get_contents("{$root}/composer.json"), true);

    expect($composer['name'])->toBe('acme/blog-tools')
        ->and($composer['autoload']['psr-4'])->toBe(['Acme\\BlogTools\\' => 'src/'])
        ->and($composer['extra']['laravel']['providers'])->toBe(['Acme\\BlogTools\\BlogToolsServiceProvider'])
        ->and(json_decode(file_get_contents("{$root}/package.json"), true)['name'])->toBe('@acme/blog-tools')
        ->and(file_get_contents("{$root}/resources/js/index.ts"))->toContain("'blog-tools:example': Example")
        ->and(file_exists("{$root}/resources/js/components/example.tsx"))->toBeTrue()
        ->and(file_get_contents("{$root}/README.md"))
        ->toContain('$panel->plugin(BlogToolsPlugin::make());')
        ->toContain("import { components as blogToolsComponents } from '@acme/blog-tools'");

    require_once "{$root}/src/BlogToolsPlugin.php";
    require_once "{$root}/src/BlogToolsServiceProvider.php";

    $panel = Panel::make()->id('plugged')->plugin(Acme\BlogTools\BlogToolsPlugin::make());

    expect($panel->getPlugin('blog-tools'))->toBeInstanceOf(Acme\BlogTools\BlogToolsPlugin::class);

    (new Acme\BlogTools\BlogToolsServiceProvider($this->app))->boot();

    expect(Assets::getStyles('plugged'))->toHaveCount(1)
        ->and(Assets::getStyles()[0]->getPublicPath())->toBe('vendor/alfakit/acme/blog-tools/blog-tools.css');

    $this->artisan('alfakit:assets')->assertSuccessful();

    expect(file_exists(public_path('vendor/alfakit/acme/blog-tools/blog-tools.css')))->toBeTrue();
});

it('generates a PHP-only plugin', function (): void {
    $this->artisan('make:alfakit-plugin', ['name' => 'Audit', '--vendor' => 'acme', '--frontend' => 'none', '--path' => 'plugins'])->assertSuccessful();

    expect(file_exists(base_path('plugins/audit/src/AuditPlugin.php')))->toBeTrue()
        ->and(file_exists(base_path('plugins/audit/package.json')))->toBeFalse()
        ->and(file_get_contents(base_path('plugins/audit/README.md')))->not->toContain('Frontend components');
});

it('renders registered assets in the panel head, scoped to panels', function (): void {
    writeSandbox('dist/blog.css', 'body{}');

    Assets::register([
        Css::make('blog', base_path('dist/blog.css')),
        Js::make('blog', 'https://cdn.example.com/blog.js')->module(),
        Js::make('legacy', 'https://cdn.example.com/legacy.js'),
    ], package: 'acme/blog');
    Assets::register([Css::make('other-only', 'https://cdn.example.com/other.css')], panel: 'other');

    $this->artisan('alfakit:assets')->assertSuccessful();

    $html = Assets::renderTags('admin', 'n0nce');

    expect($html)
        ->toMatch('#<link rel="stylesheet" href="http://localhost/vendor/alfakit/acme/blog/blog\.css\?v=[0-9a-f]{8}" data-alfakit-asset="acme/blog/blog" nonce="n0nce">#')
        ->toContain('<script src="https://cdn.example.com/blog.js" type="module" data-alfakit-asset="acme/blog/blog" nonce="n0nce"></script>')
        ->toContain('<script src="https://cdn.example.com/legacy.js" defer data-alfakit-asset="acme/blog/legacy" nonce="n0nce"></script>')
        ->not->toContain('other.css')
        ->and(Assets::renderTags('other'))->toContain('other.css');

    // The Blade root view includes them.
    $this->app->setBasePath($this->originalBasePath);
    $user = AlfaSix\Alfakit\Tests\Fixtures\Models\User::query()->create(['name' => 'A', 'email' => 'assets@example.com', 'password' => 'password']);

    $this->actingAs($user)->get('/admin/posts')->assertOk()->assertSee('https://cdn.example.com/legacy.js', false);
});

it('validates asset ids and packages', function (): void {
    expect(fn () => Css::make('../evil', 'x.css'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Assets::register([Css::make('ok', 'x.css')], package: '../../etc'))->toThrow(InvalidArgumentException::class);

    Assets::register([Css::make('missing', base_path('dist/missing.css'))]);

    $this->artisan('alfakit:assets')->assertFailed();
});

it('runs the upgrade command', function (): void {
    writeSandbox('package.json', json_encode(['dependencies' => ['@alfakit/react' => '^0.1.0']]));

    $this->artisan('alfakit:upgrade')
        ->expectsOutputToContain('@alfakit/react is ^0.1.0')
        ->assertSuccessful();
});

it('gives the Vue and Svelte field stubs the input slot the themes style', function (string $adapter, string $extension): void {
    $this->artisan('make:alfakit-field', ['name' => 'SlotProbe', '--adapter' => $adapter, '--force' => true])->assertSuccessful();

    expect(file_get_contents(resource_path("js/alfakit/fields/SlotProbe.{$extension}")))->toContain('data-slot="input"');

    @unlink(resource_path("js/alfakit/fields/SlotProbe.{$extension}"));
    @unlink(app_path('Alfakit/Forms/Components/SlotProbe.php'));
})->with([['vue', 'vue'], ['svelte', 'svelte']]);

it('points starter kit apps at the host page instead of a createAlfakit() they do not have', function (): void {
    writeSandbox('resources/js/pages/Alfakit/Page.tsx', "import { createStarterKitPage } from '@alfakit/react';\nimport AppLayout from '@/layouts/app-layout';\n\nexport default createStarterKitPage({ layout: AppLayout });\n");

    expect(Illuminate\Support\Facades\Artisan::call('make:alfakit-field', ['name' => 'RatingInput', '--adapter' => 'react']))->toBe(0);
    $output = preg_replace('/\s+/', ' ', Illuminate\Support\Facades\Artisan::output());

    expect($output)
        ->toContain("resources/js/pages/Alfakit/Page.tsx doesn't auto-register components")
        ->toContain("Add `discover: import.meta.glob('../../alfakit/{fields,columns,entries,widgets,pages,components,blocks}/**/*.tsx')` to createStarterKitPage()")
        ->toContain("import RatingInput from '../../alfakit/fields/RatingInput' createStarterKitPage({ layout: AppLayout, components: { 'RatingInput': RatingInput } })")
        ->not->toContain('No resources/js/alfakit/app entry found')
        ->not->toContain('Skip this step next time');

    writeSandbox('resources/js/pages/Alfakit/Page.tsx', "import { createStarterKitPage } from '@alfakit/react';\nimport AppLayout from '@/layouts/app-layout';\n\nexport default createStarterKitPage({\n    layout: AppLayout,\n    discover: import.meta.glob('../../alfakit/{fields,columns,entries,widgets,pages,components,blocks}/**/*.tsx'),\n});\n");

    $this->artisan('make:alfakit-column', ['name' => 'ProgressBar', '--adapter' => 'react'])
        ->expectsOutputToContain("Auto-registered 'column:ProgressBar'")
        ->assertSuccessful();
});
