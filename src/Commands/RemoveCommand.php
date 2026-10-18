<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Commands;

use AlfaSix\Alfakit\Commands\Concerns\ManagesPlugins;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Remove first-party plugins: unregister them from the panel provider, then
 * `composer remove alfasix/alfakit-<slug>` (after a confirmation; kept while another
 * panel still uses it). Database tables are never dropped.
 */
#[AsCommand(name: 'alfakit:remove', description: 'Remove first-party AlfaKit plugins from a panel (data tables are kept)')]
class RemoveCommand extends Command
{
    use ManagesPlugins;

    protected $signature = 'alfakit:remove
        {plugin* : Plugin slugs}
        {--panel=admin : The panel to unregister the plugins from}
        {--no-install : Do not run composer; print the command instead}';

    public function handle(Filesystem $files): int
    {
        $panelId = Str::slug((string) ($this->option('panel') ?: 'admin'));

        /** @var array<int, string> $requested */
        $requested = (array) $this->argument('plugin');
        $slugs = $this->validatePluginSlugs($requested);

        if ($slugs === null) {
            return self::FAILURE;
        }

        $notes = [];
        $failed = false;

        foreach ($slugs as $slug) {
            $result = $this->removePluginFrom($files, $slug, $panelId);
            $notes = [...$notes, ...$result['notes']];
            $failed = $failed || $result['status'] === 'failed';

            $this->components->twoColumnDetail($slug, AddCommand::statusLabel($result['status']).($result['detail'] !== '' ? " <fg=gray>({$result['detail']})</>" : ''));
        }

        if ($notes !== []) {
            $this->newLine();
            $this->components->bulletList(array_values(array_unique($notes)));
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
