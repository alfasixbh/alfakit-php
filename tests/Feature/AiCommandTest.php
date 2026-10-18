<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Commands\AiCommand;
use Illuminate\Support\Facades\File;

beforeEach(function (): void {
    $this->root = sys_get_temp_dir().'/alfakit-ai-'.bin2hex(random_bytes(4));
    File::ensureDirectoryExists($this->root);
});

afterEach(function (): void {
    File::deleteDirectory($this->root);
});

it('writes the guideline into AGENTS.md as plain Markdown', function (): void {
    $this->artisan('alfakit:ai', ['--target' => ['agents'], '--path' => $this->root, '--no-interaction' => true])->assertSuccessful();

    $agents = File::get($this->root.'/AGENTS.md');

    expect($agents)
        ->toStartWith(AiCommand::START)
        ->toContain('## AlfaKit')
        ->toContain("```php\nuse AlfaSix\\Alfakit\\")
        ->not->toContain('<code-snippet')
        ->not->toContain('@verbatim');
});

it('updates its own section and keeps the rest of the file', function (): void {
    File::put($this->root.'/AGENTS.md', "# My project\n\nOur rules.\n\n".AiCommand::START."\nold\n".AiCommand::END."\n\n## More rules\n");

    $this->artisan('alfakit:ai', ['--target' => ['agents'], '--path' => $this->root, '--no-interaction' => true])->assertSuccessful();
    $this->artisan('alfakit:ai', ['--target' => ['agents'], '--path' => $this->root, '--no-interaction' => true])->assertSuccessful();

    $agents = File::get($this->root.'/AGENTS.md');

    expect($agents)->toStartWith("# My project\n\nOur rules.")
        ->toContain('## More rules')
        ->not->toContain("\nold\n")
        ->and(substr_count($agents, AiCommand::START))->toBe(1);
});

it('installs Claude Code skills and Cursor rules', function (): void {
    $this->artisan('alfakit:ai', ['--target' => ['claude', 'cursor', 'copilot'], '--path' => $this->root, '--no-interaction' => true])->assertSuccessful();

    $skills = array_map('basename', glob(dirname(__DIR__, 2).'/resources/boost/skills/*', GLOB_ONLYDIR) ?: []);

    expect($skills)->not->toBeEmpty();

    foreach ($skills as $skill) {
        expect(File::get("{$this->root}/.claude/skills/{$skill}/SKILL.md"))->toContain("name: {$skill}")
            ->and(File::get("{$this->root}/.cursor/rules/{$skill}.mdc"))->toContain('alwaysApply: false')->not->toContain("name: {$skill}");
    }

    expect(File::get($this->root.'/CLAUDE.md'))->toContain('## AlfaKit')
        ->and(File::get($this->root.'/.cursor/rules/alfakit.mdc'))->toContain('alwaysApply: true')
        ->and(File::get($this->root.'/.github/copilot-instructions.md'))->toContain('alfakit-development/SKILL.md');
});

it('detects the agents a project already uses', function (): void {
    File::ensureDirectoryExists($this->root.'/.cursor');

    $this->artisan('alfakit:ai', ['--path' => $this->root, '--no-interaction' => true])->assertSuccessful();

    expect(File::exists($this->root.'/AGENTS.md'))->toBeTrue()
        ->and(File::exists($this->root.'/.cursor/rules/alfakit.mdc'))->toBeTrue()
        ->and(File::exists($this->root.'/CLAUDE.md'))->toBeFalse();
});

it('rejects unknown targets', function (): void {
    $this->artisan('alfakit:ai', ['--target' => ['vim'], '--path' => $this->root, '--no-interaction' => true])->assertFailed();
});
