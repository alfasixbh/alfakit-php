<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/*
 * `alfakit:eject` copies a renderer component out of the installed `@alfakit/<adapter>`
 * package (which ships its `src/` and a build-time `dist/eject-manifest.json`), rewrites its
 * internal imports and registers the copy in the app's `createAlfakit({ components })`.
 */

beforeEach(function (): void {
    $this->files = new Filesystem;
    $this->sandbox = sys_get_temp_dir().'/alfakit-eject-'.uniqid();
    $this->files->ensureDirectoryExists($this->sandbox.'/resources/js/alfakit');
    $this->originalBasePath = $this->app->basePath();
    $this->app->setBasePath($this->sandbox);
});

afterEach(function (): void {
    $this->app->setBasePath($this->originalBasePath);
    $this->files->deleteDirectory($this->sandbox);
});

/**
 * A fake `@alfakit/react` package: a field importing public helpers (rewritten to the
 * package entry), a private helper (copied), a stylesheet, and the cell renderer.
 */
function fakeReactPackage(string $root): void
{
    $files = new Filesystem;
    $package = "{$root}/node_modules/@alfakit/react";
    $files->ensureDirectoryExists("{$package}/dist");
    $files->ensureDirectoryExists("{$package}/src/components/fields");
    $files->ensureDirectoryExists("{$package}/src/components/table");
    $files->ensureDirectoryExists("{$package}/src/components/ui");
    $files->ensureDirectoryExists("{$package}/src/lib");

    $files->put("{$package}/package.json", json_encode(['name' => '@alfakit/react', 'version' => '0.1.0']));
    $files->put("{$package}/src/components/fields/text-input.tsx", <<<'TSX'
import { applyMask, type TextInputSchema } from '@alfakit/core'
import { useState } from 'react'
import { useT } from '../../context'
import { extraProps } from '../../lib/attributes'
import { mask } from '../../lib/mask'
import type { Helper } from '../../lib/mask'
import {
  Button,
  buttonVariants,
} from '../ui/button'
import './text-input.css'

export function TextInputField() {
  return null
}
TSX);
    $files->put("{$package}/src/components/fields/text-input.css", '.x {}');
    $files->put("{$package}/src/lib/mask.ts", "import { cn } from './utils'\nimport { deep } from './deep/helper'\nexport const mask = cn\nexport type Helper = string\nexport { deep }\n");
    $files->ensureDirectoryExists("{$package}/src/lib/deep");
    $files->put("{$package}/src/lib/deep/helper.ts", "export const deep = 1\n");
    $files->put("{$package}/src/lib/utils.ts", "export const cn = (...a: string[]) => a.join(' ')\n");
    $files->put("{$package}/src/lib/attributes.ts", "export const extraProps = () => ({})\n");
    $files->put("{$package}/src/context.tsx", "export const useT = () => (key: string) => key\n");
    $files->put("{$package}/src/components/ui/button.tsx", "export const Button = () => null\nexport const buttonVariants = () => ''\n");
    $files->put("{$package}/src/components/table/cells.tsx", "export function TableCellContent() { return null }\n");

    $files->put("{$package}/dist/eject-manifest.json", json_encode([
        'package' => '@alfakit/react',
        'version' => '0.1.0',
        'adapter' => 'react',
        'components' => [
            'TextInput' => ['file' => 'src/components/fields/text-input.tsx', 'export' => 'TextInputField', 'name' => 'TextInputField'],
            'column:*' => ['file' => 'src/components/table/cells.tsx', 'export' => 'TableCellContent', 'name' => 'TableCellContent'],
        ],
        'public' => [
            'src/context.tsx' => ['useT' => 'useT'],
            'src/lib/attributes.ts' => ['extraProps' => 'extraProps'],
            'src/lib/utils.ts' => ['cn' => 'cn'],
            'src/components/ui/button.tsx' => ['Button' => 'Button', 'buttonVariants' => 'buttonVariants'],
        ],
    ], JSON_PRETTY_PRINT));

    $files->put("{$root}/package.json", json_encode(['dependencies' => ['@alfakit/react' => '^0.1.0', '@inertiajs/react' => '^3.0.0']]));
}

function ejectEntry(string $function = 'createAlfakit', string $from = '@alfakit/react'): string
{
    return <<<TS
import { {$function} } from '{$from}';
import {
    something,
} from './elsewhere';

void {$function}({
    components: {
        // 'map-picker': MapPicker,
    },
    pages: {},
});

TS;
}

it('ejects a component, rewrites imports and registers it in the app and SSR entries', function (): void {
    fakeReactPackage($this->sandbox);
    $this->files->put(resource_path('js/alfakit/app.tsx'), ejectEntry());
    $this->files->put(resource_path('js/alfakit/ssr.tsx'), ejectEntry('createAlfakitServer', '@alfakit/react/server'));

    $this->artisan('alfakit:eject', ['component' => 'TextInput'])->assertSuccessful();

    $component = file_get_contents(resource_path('js/alfakit/components/text-input.tsx'));

    expect($component)
        ->toStartWith('// Ejected from @alfakit/react@0.1.0 (src/components/fields/text-input.tsx)')
        // Public helpers come from the package entry; bare imports are untouched.
        ->toContain("import { useT } from '@alfakit/react'")
        ->toContain("import { extraProps } from '@alfakit/react'")
        ->toContain("import { Button, buttonVariants } from '@alfakit/react'")
        ->toContain("import { applyMask, type TextInputSchema } from '@alfakit/core'")
        ->toContain("import { useState } from 'react'")
        // Private helpers are copied under components/_alfakit, keeping their tree.
        ->toContain("import { mask } from './_alfakit/lib/mask'")
        ->toContain("import type { Helper } from './_alfakit/lib/mask'")
        ->toContain("import './_alfakit/components/fields/text-input.css'")
        ->not->toContain("'../");

    expect(file_get_contents(resource_path('js/alfakit/components/_alfakit/lib/mask.ts')))
        // The helper's own public import is rewritten, its private one stays relative (the tree is kept).
        ->toContain("import { cn } from '@alfakit/react'")
        ->toContain("import { deep } from './deep/helper'")
        ->and(file_exists(resource_path('js/alfakit/components/_alfakit/lib/deep/helper.ts')))->toBeTrue()
        ->and(file_exists(resource_path('js/alfakit/components/_alfakit/components/fields/text-input.css')))->toBeTrue()
        ->and(file_exists(resource_path('js/alfakit/components/_alfakit/lib/utils.ts')))->toBeFalse();

    foreach (['app', 'ssr'] as $entry) {
        $contents = file_get_contents(resource_path("js/alfakit/{$entry}.tsx"));

        expect($contents)
            ->toContain("import { TextInputField } from './components/text-input';")
            ->toContain('TextInput: TextInputField,')
            ->not->toContain("'TextInput'")
            ->and(substr_count($contents, 'TextInput:'))->toBe(1);
    }
});

it('formats the ejected files and the edited entries with the app formatter', function (): void {
    Illuminate\Support\Facades\Process::fake();
    fakeReactPackage($this->sandbox);
    $this->files->put(resource_path('js/alfakit/app.tsx'), ejectEntry());
    $this->files->ensureDirectoryExists(base_path('node_modules/.bin'));
    $this->files->put(base_path('node_modules/.bin/prettier'), '');
    $this->files->put(base_path('.prettierrc'), '{ "semi": true, "tabWidth": 4 }');

    $this->artisan('alfakit:eject', ['component' => 'TextInput'])->assertSuccessful();

    Illuminate\Support\Facades\Process::assertRanTimes(function (Illuminate\Process\PendingProcess $process): bool {
        $command = (array) $process->command;

        return array_slice($command, 0, 2) === [base_path('node_modules/.bin/prettier'), '--write']
            && in_array(resource_path('js/alfakit/components/text-input.tsx'), $command, true)
            && in_array(resource_path('js/alfakit/components/_alfakit/lib/mask.ts'), $command, true)
            && in_array(resource_path('js/alfakit/app.tsx'), $command, true)
            // CSS helpers are not formatted.
            && ! in_array(resource_path('js/alfakit/components/_alfakit/components/fields/text-input.css'), $command, true);
    }, 1);
});

it('names the direct dependencies of the copy that package.json does not list', function (): void {
    fakeReactPackage($this->sandbox);
    $this->files->put(resource_path('js/alfakit/app.tsx'), ejectEntry());

    // The sandbox package.json lists @alfakit/react only; the copy imports @alfakit/core and react.
    $this->artisan('alfakit:eject', ['component' => 'TextInput'])
        ->expectsOutputToContain('npm install @alfakit/core react')
        ->assertSuccessful();

    // A pnpm project gets the pnpm command, and nothing is reported once they are declared.
    $this->files->put($this->sandbox.'/pnpm-lock.yaml', '');
    $this->files->put($this->sandbox.'/package.json', json_encode(['dependencies' => ['@alfakit/react' => '^0.1.0', '@alfakit/core' => '^0.1.0', 'react' => '^19.0.0']]));

    $this->artisan('alfakit:eject', ['component' => 'TextInput', '--force' => true])
        ->doesntExpectOutputToContain('pnpm add')
        ->assertSuccessful();
});

it('is idempotent and only overwrites with --force', function (): void {
    fakeReactPackage($this->sandbox);
    $this->files->put(resource_path('js/alfakit/app.tsx'), ejectEntry());

    $this->artisan('alfakit:eject', ['component' => 'TextInput'])->assertSuccessful();
    $this->files->append(resource_path('js/alfakit/components/text-input.tsx'), "\n// my change\n");

    $this->artisan('alfakit:eject', ['component' => 'TextInput'])->assertSuccessful();

    expect(file_get_contents(resource_path('js/alfakit/components/text-input.tsx')))->toContain('// my change')
        ->and(substr_count(file_get_contents(resource_path('js/alfakit/app.tsx')), 'TextInput:'))->toBe(1)
        ->and(substr_count(file_get_contents(resource_path('js/alfakit/app.tsx')), 'import { TextInputField }'))->toBe(1);

    $this->artisan('alfakit:eject', ['component' => 'TextInput', '--force' => true])->assertSuccessful();

    expect(file_get_contents(resource_path('js/alfakit/components/text-input.tsx')))->not->toContain('// my change');
});

it('maps column keys to the shared cell renderer and registers each requested key', function (): void {
    fakeReactPackage($this->sandbox);
    $this->files->put(resource_path('js/alfakit/app.tsx'), ejectEntry());

    $this->artisan('alfakit:eject', ['component' => 'column:TextColumn'])->assertSuccessful();
    $this->artisan('alfakit:eject', ['component' => 'column:IconColumn'])->assertSuccessful();

    $entry = file_get_contents(resource_path('js/alfakit/app.tsx'));

    expect(file_exists(resource_path('js/alfakit/components/cells.tsx')))->toBeTrue()
        ->and($entry)->toContain("'column:TextColumn': TableCellContent,")
        ->and($entry)->toContain("'column:IconColumn': TableCellContent,")
        ->and(substr_count($entry, "import { TableCellContent } from './components/cells';"))->toBe(1);
});

it('explains unknown keys and missing manifests', function (): void {
    fakeReactPackage($this->sandbox);

    $this->artisan('alfakit:eject', ['component' => 'Nope', '--adapter' => 'react'])
        ->expectsOutputToContain('Unknown component [Nope]')
        ->assertFailed();

    $this->files->delete($this->sandbox.'/node_modules/@alfakit/react/dist/eject-manifest.json');

    $this->artisan('alfakit:eject', ['component' => 'TextInput', '--adapter' => 'react'])
        ->expectsOutputToContain('No eject manifest')
        ->assertFailed();
});

it('ejects the real React TextInput and it typechecks against the built package', function (): void {
    $monorepo = dirname(__DIR__, 3);
    $package = "{$monorepo}/react";
    $tsc = "{$package}/node_modules/.bin/tsc";

    if (! is_file("{$package}/dist/eject-manifest.json") || ! is_file("{$package}/dist/index.d.ts") || ! is_executable($tsc)) {
        $this->markTestSkipped('Build @alfakit/react first (pnpm --filter @alfakit/react build).');
    }

    // Inside packages/react so the copy resolves react, lucide-react and @alfakit/core like the package does.
    $app = "{$package}/.eject-test-".uniqid();
    $this->files->ensureDirectoryExists("{$app}/node_modules/@alfakit");
    $this->files->ensureDirectoryExists("{$app}/resources/js/alfakit");
    symlink($package, "{$app}/node_modules/@alfakit/react");
    $this->files->put("{$app}/package.json", json_encode(['dependencies' => ['@alfakit/react' => 'workspace:*']]));
    $this->files->put("{$app}/resources/js/alfakit/app.tsx", ejectEntry());
    $this->files->put("{$app}/tsconfig.json", json_encode([
        'compilerOptions' => [
            'strict' => true,
            'jsx' => 'react-jsx',
            'module' => 'ESNext',
            'moduleResolution' => 'Bundler',
            'target' => 'ES2022',
            'lib' => ['ES2022', 'DOM', 'DOM.Iterable'],
            'noEmit' => true,
            'skipLibCheck' => true,
            'verbatimModuleSyntax' => true,
            'types' => [],
        ],
        'include' => ['resources/js/alfakit/components/**/*'],
    ]));

    try {
        $this->app->setBasePath($app);

        $this->artisan('alfakit:eject', ['component' => 'TextInput', '--adapter' => 'react'])->assertSuccessful();

        $component = file_get_contents("{$app}/resources/js/alfakit/components/text-input.tsx");

        expect($component)->toContain("from '@alfakit/react'")->not->toContain("from '../");

        $process = Process::fromShellCommandline(escapeshellarg($tsc).' --noEmit -p tsconfig.json', $app, timeout: 180);
        $process->run();

        expect($process->getExitCode())->toBe(0, "tsc failed:\n".$process->getOutput().$process->getErrorOutput());
    } finally {
        $this->files->deleteDirectory($app);
    }
});
