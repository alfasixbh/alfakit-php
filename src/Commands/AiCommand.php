<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Blade;
use Symfony\Component\Console\Attribute\AsCommand;

use function Laravel\Prompts\multiselect;

/**
 * Install AlfaKit's agent guideline and skills for coding agents that don't use Laravel
 * Boost: AGENTS.md (Codex, Gemini CLI, Zed, Jules…), Claude Code, Cursor and GitHub Copilot.
 * Shared files only get a marked section, so running it again updates that section in place.
 */
#[AsCommand(name: 'alfakit:ai')]
class AiCommand extends Command
{
    public const START = '<!-- alfakit:start -->';

    public const END = '<!-- alfakit:end -->';

    /** @var array<string, string> */
    public const TARGETS = [
        'agents' => 'AGENTS.md (Codex, Gemini CLI, Zed, Jules, Aider, …)',
        'claude' => 'Claude Code (CLAUDE.md + .claude/skills)',
        'cursor' => 'Cursor (.cursor/rules)',
        'copilot' => 'GitHub Copilot (.github/copilot-instructions.md)',
    ];

    protected $signature = 'alfakit:ai
        {--target=* : agents, claude, cursor or copilot (default: detected, or asked)}
        {--path= : Project root (default: the application base path)}';

    protected $description = 'Install AlfaKit guidelines and skills for AI coding agents that don\'t use Laravel Boost';

    public function __construct(protected Filesystem $files)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $root = rtrim((string) ($this->option('path') ?: $this->laravel->basePath()), '/');
        $targets = $this->resolveTargets($root);

        if ($targets === null) {
            return self::FAILURE;
        }

        if ($targets === []) {
            $this->components->warn('Nothing to install.');

            return self::SUCCESS;
        }

        if (class_exists('Laravel\\Boost\\BoostServiceProvider')) {
            $this->components->info('Laravel Boost is installed: `php artisan boost:install` already gives Boost-aware agents AlfaKit\'s guideline and skills. This command writes plain files for other agents.');
        }

        $guideline = $this->guideline();
        $skills = $this->skills();

        foreach ($targets as $target) {
            match ($target) {
                'agents' => $this->writeSection("{$root}/AGENTS.md", $guideline),
                'claude' => $this->installClaude($root, $guideline, $skills),
                'cursor' => $this->installCursor($root, $guideline, $skills),
                'copilot' => $this->writeSection("{$root}/.github/copilot-instructions.md", $guideline."\n\n".$this->skillIndex($skills)),
                default => null,
            };
        }

        $this->components->info('AlfaKit agent files are installed. Run `php artisan alfakit:ai` again after updating AlfaKit.');

        return self::SUCCESS;
    }

    /**
     * @return array<int, string>|null Null when an unknown target was requested.
     */
    protected function resolveTargets(string $root): ?array
    {
        /** @var array<int, string> $requested */
        $requested = array_values(array_filter((array) $this->option('target')));

        if ($requested !== []) {
            $unknown = array_diff($requested, array_keys(self::TARGETS));

            if ($unknown !== []) {
                $this->components->error('Unknown target(s): '.implode(', ', $unknown).'. Use '.implode(', ', array_keys(self::TARGETS)).'.');

                return null;
            }

            return array_values(array_unique($requested));
        }

        $detected = array_values(array_filter([
            'agents',
            $this->files->exists("{$root}/CLAUDE.md") || $this->files->isDirectory("{$root}/.claude") ? 'claude' : null,
            $this->files->isDirectory("{$root}/.cursor") ? 'cursor' : null,
            $this->files->exists("{$root}/.github/copilot-instructions.md") ? 'copilot' : null,
        ]));

        if (! $this->input->isInteractive()) {
            return $detected;
        }

        /** @var array<int, string> $chosen */
        $chosen = multiselect(
            label: 'Which agents should get AlfaKit\'s guidelines?',
            options: self::TARGETS,
            default: $detected,
            required: true,
        );

        return $chosen;
    }

    /**
     * The Boost guideline as plain Markdown.
     */
    public function guideline(): string
    {
        $rendered = Blade::render((string) $this->files->get($this->boostPath('guidelines/core.blade.php')));

        $markdown = (string) preg_replace(
            ['/<code-snippet[^>]*\blang="([a-z]+)"[^>]*>\R?/', '/\R?<\/code-snippet>/'],
            ["```$1\n", "\n```"],
            $rendered,
        );

        return trim($markdown);
    }

    /**
     * @return array<string, array{description: string, body: string, contents: string}>
     */
    public function skills(): array
    {
        $skills = [];

        foreach ($this->files->glob($this->boostPath('skills/*/SKILL.md')) ?: [] as $file) {
            $contents = (string) $this->files->get($file);
            preg_match('/\A---\R(.*?)\R---\R(.*)\z/s', $contents, $parts);
            preg_match('/^description:\s*(.+)$/m', $parts[1] ?? '', $description);

            $skills[basename(dirname($file))] = [
                'description' => trim($description[1] ?? ''),
                'body' => trim($parts[2] ?? $contents),
                'contents' => $contents,
            ];
        }

        ksort($skills);

        return $skills;
    }

    /**
     * @param  array<string, array{description: string, body: string, contents: string}>  $skills
     */
    protected function installClaude(string $root, string $guideline, array $skills): void
    {
        $this->writeSection("{$root}/CLAUDE.md", $guideline);

        foreach ($skills as $name => $skill) {
            $this->writeFile("{$root}/.claude/skills/{$name}/SKILL.md", $skill['contents']);
        }
    }

    /**
     * @param  array<string, array{description: string, body: string, contents: string}>  $skills
     */
    protected function installCursor(string $root, string $guideline, array $skills): void
    {
        $this->writeFile("{$root}/.cursor/rules/alfakit.mdc", "---\ndescription: AlfaKit core rules\nalwaysApply: true\n---\n\n{$guideline}\n");

        foreach ($skills as $name => $skill) {
            $description = str_replace(["\r", "\n"], ' ', $skill['description']);

            $this->writeFile("{$root}/.cursor/rules/{$name}.mdc", "---\ndescription: {$description}\nalwaysApply: false\n---\n\n{$skill['body']}\n");
        }
    }

    /**
     * @param  array<string, array{description: string, body: string, contents: string}>  $skills
     */
    protected function skillIndex(array $skills): string
    {
        $lines = ['### AlfaKit skills', '', 'Read the matching file in `vendor/alfasix/alfakit/resources/boost/skills/` before these tasks:', ''];

        foreach ($skills as $name => $skill) {
            $lines[] = "- `{$name}/SKILL.md`: {$skill['description']}";
        }

        return implode("\n", $lines);
    }

    /**
     * Replace AlfaKit's marked section in a shared file, or append it.
     */
    public function writeSection(string $path, string $content): void
    {
        $section = self::START."\n".$content."\n".self::END;
        $existing = $this->files->exists($path) ? (string) $this->files->get($path) : '';

        $start = strpos($existing, self::START);
        $end = strpos($existing, self::END);

        $updated = $start !== false && $end !== false && $end > $start
            ? substr($existing, 0, $start).$section.substr($existing, $end + strlen(self::END))
            : ($existing === '' ? $section."\n" : rtrim($existing)."\n\n".$section."\n");

        $this->writeFile($path, $updated);
    }

    protected function writeFile(string $path, string $contents): void
    {
        $this->files->ensureDirectoryExists(dirname($path));
        $this->files->put($path, $contents);

        $this->components->twoColumnDetail(str_replace($this->laravel->basePath().'/', '', $path), '<fg=green>written</>');
    }

    protected function boostPath(string $path): string
    {
        return dirname(__DIR__, 2).'/resources/boost/'.$path;
    }
}
