<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Commands\Support\FrontendFormatter;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;

/*
 * Generated frontend files take the application's own formatter: Vite+ (`vp fmt` with a
 * `fmt` section in vite.config), Oxfmt or Prettier, run from node_modules/.bin on just
 * the written files.
 */

beforeEach(function (): void {
    $this->appDir = sys_get_temp_dir().'/alfakit-formatter-'.uniqid();
    (new Filesystem)->ensureDirectoryExists($this->appDir.'/node_modules/.bin');
    (new Filesystem)->ensureDirectoryExists($this->appDir.'/resources/js');
    file_put_contents($this->appDir.'/resources/js/Field.tsx', "export const a = 1\n");
    file_put_contents($this->appDir.'/resources/js/Field.php', "<?php\n");
});

afterEach(function (): void {
    (new Filesystem)->deleteDirectory($this->appDir);
});

function formatterBin(string $app, string $name): string
{
    touch("{$app}/node_modules/.bin/{$name}");

    return "{$app}/node_modules/.bin/{$name}";
}

it('detects the app formatter from its local binary and configuration', function (): void {
    expect(FrontendFormatter::detect($this->appDir))->toBeNull();

    // A binary without configuration is not the project's formatter.
    $prettier = formatterBin($this->appDir, 'prettier');
    expect(FrontendFormatter::detect($this->appDir))->toBeNull();

    file_put_contents($this->appDir.'/package.json', json_encode(['prettier' => ['semi' => true]]));
    expect(FrontendFormatter::detect($this->appDir))->toBe(['prettier', [$prettier, '--write']]);

    $oxfmt = formatterBin($this->appDir, 'oxfmt');
    file_put_contents($this->appDir.'/.oxfmtrc.json', '{}');
    expect(FrontendFormatter::detect($this->appDir))->toBe(['oxfmt', [$oxfmt]]);

    // Vite+ formats only when vite.config has a `fmt` section (the starter kits').
    $vp = formatterBin($this->appDir, 'vp');
    file_put_contents($this->appDir.'/vite.config.ts', "export default defineConfig({ plugins: [] })\n");
    expect(FrontendFormatter::detect($this->appDir)[0])->toBe('oxfmt');

    file_put_contents($this->appDir.'/vite.config.ts', "export default defineConfig({ fmt: { tabWidth: 4, semi: true } })\n");
    expect(FrontendFormatter::detect($this->appDir))->toBe(['vp', [$vp, 'fmt']]);
});

it('runs the formatter on just the written frontend files', function (): void {
    Process::fake();
    $vp = formatterBin($this->appDir, 'vp');
    file_put_contents($this->appDir.'/vite.config.ts', "export default defineConfig({ fmt: { semi: true } })\n");

    $ran = FrontendFormatter::format([$this->appDir.'/resources/js/Field.tsx', $this->appDir.'/resources/js/Field.php', $this->appDir.'/missing.tsx'], $this->appDir);

    expect($ran)->toBe('vp');

    Process::assertRanTimes(fn (PendingProcess $process): bool => $process->command === [$vp, 'fmt', $this->appDir.'/resources/js/Field.tsx'] && $process->path === $this->appDir, 1);
});

it('leaves files alone without a formatter, when it fails or when turned off', function (): void {
    Process::fake();

    expect(FrontendFormatter::format([$this->appDir.'/resources/js/Field.tsx'], $this->appDir))->toBeNull();
    Process::assertNothingRan();

    formatterBin($this->appDir, 'prettier');
    file_put_contents($this->appDir.'/.prettierrc', '{}');
    Process::fake(['*' => Process::result(exitCode: 2)]);

    expect(FrontendFormatter::format([$this->appDir.'/resources/js/Field.tsx'], $this->appDir))->toBeNull();

    config(['alfakit.file_generation.format_frontend' => false]);

    expect(FrontendFormatter::format([$this->appDir.'/resources/js/Field.tsx'], $this->appDir))->toBeNull();
    // Only the failed run above.
    Process::assertRanTimes(fn (): bool => true, 1);
});
