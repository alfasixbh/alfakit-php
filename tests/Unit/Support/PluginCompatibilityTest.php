<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Support\PluginCompatibility;
use Illuminate\Filesystem\Filesystem;

beforeEach(function (): void {
    $this->directory = sys_get_temp_dir().'/alfakit-compat-'.uniqid();
    (new Filesystem)->ensureDirectoryExists($this->directory);
});

afterEach(function (): void {
    (new Filesystem)->deleteDirectory($this->directory);
});

/**
 * @param  array<int, array<string, mixed>>  $packages
 * @param  array<string, mixed>|null  $packageJson
 */
function compatibilityReport(string $directory, ?string $version, array $packages, ?array $packageJson = null, bool $composerOne = false): PluginCompatibility
{
    file_put_contents("{$directory}/installed.json", json_encode($composerOne ? $packages : ['dev' => true, 'packages' => $packages]));

    if ($packageJson !== null) {
        file_put_contents("{$directory}/package.json", json_encode($packageJson));
    }

    return new PluginCompatibility($version, "{$directory}/installed.json", "{$directory}/package.json");
}

it('reports plugins declaring extra.alfakit against the installed version', function (): void {
    $report = compatibilityReport($this->directory, '1.4.0', [
        ['name' => 'acme/blog', 'version' => '1.2.0', 'extra' => ['alfakit' => ['version' => '^1.0']]],
        ['name' => 'acme/shop', 'version' => '2.0.0', 'extra' => ['alfakit' => ['version' => '^2.0']]],
        ['name' => 'acme/legacy', 'version' => '0.5.0', 'extra' => ['alfakit' => []]],
        ['name' => 'acme/unrelated', 'version' => '3.0.0', 'extra' => ['laravel' => []]],
        ['name' => 'acme/no-extra', 'version' => '3.0.0'],
    ]);

    $plugins = $report->plugins();

    expect(array_column($plugins, 'package'))->toBe(['acme/blog', 'acme/shop', 'acme/legacy'])
        ->and(array_column($plugins, 'status'))->toBe([PluginCompatibility::COMPATIBLE, PluginCompatibility::INCOMPATIBLE, PluginCompatibility::UNKNOWN])
        ->and($plugins[1]['reason'])->toContain('requires alfasix/alfakit ^2.0, but 1.4.0 is installed')
        ->and($plugins[2]['reason'])->toContain('extra.alfakit.version is not declared')
        ->and($report->hasIncompatible())->toBeTrue();
});

it('cannot decide for a development version of Alfakit', function (): void {
    $report = compatibilityReport($this->directory, 'dev-main', [
        ['name' => 'acme/blog', 'version' => '1.2.0', 'extra' => ['alfakit' => ['version' => '^1.0']]],
    ], ['dependencies' => ['@alfakit/react' => '^1.0.0']]);

    expect($report->plugins()[0]['status'])->toBe(PluginCompatibility::UNKNOWN)
        ->and($report->renderers()[0]['status'])->toBe(PluginCompatibility::UNKNOWN)
        ->and($report->hasIncompatible())->toBeFalse();
});

it('cannot decide when Alfakit is not installed through Composer', function (): void {
    $report = compatibilityReport($this->directory, null, [
        ['name' => 'acme/blog', 'version' => '1.2.0', 'extra' => ['alfakit' => ['version' => '^1.0']]],
    ]);

    expect($report->plugins()[0]['status'])->toBe(PluginCompatibility::UNKNOWN)
        ->and($report->plugins()[0]['reason'])->toContain('not installed through Composer');
});

it('checks the declared frontend package against the plugin version', function (): void {
    $report = compatibilityReport($this->directory, '1.4.0', [
        ['name' => 'acme/blog', 'version' => '1.2.0', 'extra' => ['alfakit' => ['version' => '^1.0', 'frontend' => '@acme/blog']]],
        ['name' => 'acme/shop', 'version' => '2.1.0', 'extra' => ['alfakit' => ['version' => '^1.0', 'frontend' => '@acme/shop']]],
        ['name' => 'acme/crm', 'version' => '1.0.0', 'extra' => ['alfakit' => ['version' => '^1.0', 'frontend' => '@acme/crm']]],
        ['name' => 'acme/zero', 'version' => '0.3.0', 'extra' => ['alfakit' => ['version' => '^1.0', 'frontend' => '@acme/zero']]],
    ], ['dependencies' => ['@acme/blog' => '^1.2.0', '@acme/shop' => '^1.0.0'], 'devDependencies' => ['@acme/zero' => '~0.2.0']]);

    $plugins = $report->plugins();

    expect($plugins[0]['status'])->toBe(PluginCompatibility::COMPATIBLE)
        ->and($plugins[0]['frontend'])->toBe('@acme/blog')
        ->and($plugins[1]['status'])->toBe(PluginCompatibility::INCOMPATIBLE)
        ->and($plugins[1]['reason'])->toContain('@acme/shop ^1.0.0 does not match the plugin version 2.1.0')
        ->and($plugins[2]['status'])->toBe(PluginCompatibility::UNKNOWN)
        ->and($plugins[2]['reason'])->toContain('@acme/crm is not in package.json')
        // 0.x releases must match on the minor too.
        ->and($plugins[3]['status'])->toBe(PluginCompatibility::INCOMPATIBLE);
});

it('compares @alfakit npm packages with the PHP major', function (): void {
    $report = compatibilityReport($this->directory, 'v1.4.2', [], [
        'dependencies' => ['@alfakit/react' => '^1.2.0', '@alfakit/core' => '2.0.0', 'react' => '^19.0.0'],
        'devDependencies' => ['@alfakit/vue' => 'workspace:*'],
    ]);

    $renderers = $report->renderers();

    expect(array_column($renderers, 'package'))->toBe(['@alfakit/react', '@alfakit/core', '@alfakit/vue'])
        ->and(array_column($renderers, 'status'))->toBe([PluginCompatibility::COMPATIBLE, PluginCompatibility::INCOMPATIBLE, PluginCompatibility::UNKNOWN])
        ->and($renderers[1]['reason'])->toContain('npm install @alfakit/core@^1.4')
        ->and($report->hasIncompatible())->toBeTrue();
});

it('treats 0.x npm packages as compatible only on the same minor', function (): void {
    $report = compatibilityReport($this->directory, '0.1.3', [], ['dependencies' => ['@alfakit/react' => '^0.1.0', '@alfakit/vue' => '^0.2.0']]);

    expect(array_column($report->renderers(), 'status'))->toBe([PluginCompatibility::COMPATIBLE, PluginCompatibility::INCOMPATIBLE]);
});

it('reads Composer 1 style installed.json and survives missing files', function (): void {
    $report = compatibilityReport($this->directory, '1.0.0', [
        ['name' => 'acme/blog', 'version' => '1.0.0', 'extra' => ['alfakit' => ['version' => '^1.0']]],
    ], null, composerOne: true);

    expect($report->plugins())->toHaveCount(1)
        ->and($report->renderers())->toBe([]);

    $missing = new PluginCompatibility('1.0.0', "{$this->directory}/nope.json", "{$this->directory}/nope-package.json");

    expect($missing->plugins())->toBe([])
        ->and($missing->renderers())->toBe([])
        ->and($missing->hasIncompatible())->toBeFalse();
});

it('extracts the base version of an npm constraint', function (): void {
    expect(PluginCompatibility::constraintBase('^1.2.0'))->toBe([1, 2, 0])
        ->and(PluginCompatibility::constraintBase('~0.1.0'))->toBe([0, 1, 0])
        ->and(PluginCompatibility::constraintBase('>=2.0.0 <3.0.0'))->toBe([2, 0, 0])
        ->and(PluginCompatibility::constraintBase('1.0.0 || 2.0.0'))->toBe([1, 0, 0])
        ->and(PluginCompatibility::constraintBase('*'))->toBeNull()
        ->and(PluginCompatibility::constraintBase('workspace:*'))->toBeNull()
        ->and(PluginCompatibility::constraintBase('file:../packages/react'))->toBeNull()
        ->and(PluginCompatibility::constraintBase('latest'))->toBeNull();
});
