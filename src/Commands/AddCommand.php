<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Commands;

use AlfaSix\Alfakit\Commands\Concerns\ManagesPlugins;
use AlfaSix\Alfakit\Commands\Support\AppEnvironment;
use AlfaSix\Alfakit\Commands\Support\PanelProviderEditor;
use AlfaSix\Alfakit\Commands\Support\PluginCatalog;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Symfony\Component\Console\Attribute\AsCommand;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\multiselect;

/**
 * Add first-party plugins when you need them: `composer require alfasix/alfakit-<slug>`
 * (after a confirmation), the registration on the panel provider and the plugin's
 * migrations. `--list` shows every plugin with its description and status;
 * `--list kanban` shows a plugin's highlights, a tour of the live demo and related plugins.
 */
#[AsCommand(name: 'alfakit:add', description: 'Add first-party AlfaKit plugins to a panel (--list shows them)')]
class AddCommand extends Command
{
    use ManagesPlugins;

    protected $signature = 'alfakit:add
        {plugin?* : Plugin slugs (shield, comments, activity-log, …)}
        {--panel=admin : The panel to register the plugins on}
        {--list : List the first-party plugins and whether they are installed (with plugin slugs: their highlights and demo)}
        {--migrate : Run the migrations without asking}
        {--no-install : Do not run composer; print the command instead}';

    public function handle(Filesystem $files): int
    {
        $panelId = Str::slug((string) ($this->option('panel') ?: 'admin'));

        if ($this->option('list')) {
            /** @var array<int, string> $details */
            $details = (array) $this->argument('plugin');

            if ($details === []) {
                static::renderList($this, $files, $panelId);

                return self::SUCCESS;
            }

            $slugs = $this->validatePluginSlugs($details);

            if ($slugs === null) {
                return self::FAILURE;
            }

            foreach ($slugs as $slug) {
                static::renderDetails($this, $slug);
            }

            return self::SUCCESS;
        }

        /** @var array<int, string> $requested */
        $requested = (array) $this->argument('plugin');

        if ($requested === [] && $this->input->isInteractive()) {
            /** @var array<int, string> $requested */
            $requested = multiselect('Which plugins do you want to add?', PluginCatalog::options(), scroll: 12, required: true, hint: 'Space to select. Add more later with php artisan alfakit:add.');
        }

        $slugs = $this->validatePluginSlugs($requested);

        if ($slugs === null) {
            return self::FAILURE;
        }

        if ($slugs === []) {
            $this->components->error('Name the plugins to add, e.g. php artisan alfakit:add comments (see --list).');

            return self::FAILURE;
        }

        $notes = [];
        $migrate = false;
        $failed = false;

        foreach ($slugs as $slug) {
            $result = $this->addPluginTo($files, $slug, $panelId);
            $notes = [...$notes, ...$result['notes']];
            $migrate = $migrate || $result['migrate'];
            $failed = $failed || $result['status'] === 'failed';

            $this->components->twoColumnDetail($slug, static::statusLabel($result['status']).($result['detail'] !== '' ? " <fg=gray>({$result['detail']})</>" : ''));
        }

        if ($migrate) {
            if ($this->option('migrate') || ($this->input->isInteractive() && confirm('Run the database migrations now?', true))) {
                $ok = AppEnvironment::artisan(['migrate', '--no-interaction'])->successful();
                $this->components->twoColumnDetail('migrations', $ok ? '<fg=green>done</>' : '<fg=red>failed</> (php artisan migrate)');
                $failed = $failed || ! $ok;
            } else {
                $notes[] = 'Run the migrations: php artisan migrate';
            }
        }

        if ($notes !== []) {
            $this->newLine();
            $this->components->bulletList(array_values(array_unique($notes)));
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Every first-party plugin: slug, one line on what it does, installed and registered.
     */
    public static function renderList(Command $command, Filesystem $files, string $panelId): void
    {
        $provider = PanelProviderEditor::path($panelId);
        $contents = $files->exists($provider) ? $files->get($provider) : '';
        $rows = [];

        foreach (PluginCatalog::all() as $slug => $plugin) {
            $installed = AppEnvironment::hasComposerPackage($plugin['package']);
            $registered = $contents !== '' && PanelProviderEditor::hasPlugin($contents, $plugin['class']);

            $rows[] = [$slug, $plugin['description'], match (true) {
                $registered && $installed => "<fg=green>on {$panelId}</>",
                $registered => '<fg=yellow>registered, not installed</>',
                $installed => 'installed',
                default => '<fg=gray>—</>',
            }];
        }

        $command->table(['Plugin', 'What it does', 'Status'], $rows);
        $command->line('  Add one: <fg=cyan>php artisan alfakit:add comments</>  ·  details and demo: <fg=cyan>php artisan alfakit:add --list comments</>  ·  remove one: <fg=cyan>php artisan alfakit:remove comments</>');
    }

    /**
     * One plugin's showcase (`extra.alfakit.showcase`): highlights, a tour of the live demo
     * and the plugins it works well with.
     */
    public static function renderDetails(Command $command, string $slug): void
    {
        $plugin = PluginCatalog::get($slug);
        $showcase = PluginCatalog::showcase($slug);

        $command->newLine();
        $command->line("  <options=bold>{$slug}</>  <fg=gray>{$plugin['package']}</>");

        if ($plugin['description'] !== '') {
            $command->line("  {$plugin['description']}");
        }

        if ($showcase === null) {
            $command->line('  <fg=cyan>php artisan alfakit:add '.$slug.'</>');

            return;
        }

        $command->newLine();

        foreach ($showcase['highlights'] as $highlight) {
            $command->line("  <fg=green>✓</> {$highlight}");
        }

        $command->newLine();
        $command->line('  <fg=gray>Try it live:</> '.PluginCatalog::demoUrl($slug, AppEnvironment::adapter() ?? 'react').' <fg=gray>(demo@alfakit.dev / password)</>');

        foreach ($showcase['tour'] as $index => $step) {
            $command->line('    '.($index + 1).". {$step}");
        }

        if ($showcase['worksWith'] !== []) {
            $command->line('  <fg=gray>Works great with:</> '.implode(', ', $showcase['worksWith']));
        }

        $command->line('  <fg=gray>Install:</> <fg=cyan>php artisan alfakit:add '.$slug.'</>');
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            'done' => '<fg=green>done</>',
            'already' => '<fg=gray>already set up</>',
            'failed' => '<fg=red>failed</>',
            default => '<fg=yellow>skipped</>',
        };
    }
}
