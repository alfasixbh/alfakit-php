<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Commands\Concerns;

/**
 * The one-line "AlfaKit · by Alfa Six" banner of `alfakit:setup` and `alfakit:install`,
 * shown once per run (the setup calls the installer).
 */
trait ShowsBanner
{
    protected function banner(): void
    {
        if (app()->bound('alfakit.cli-banner')) {
            return;
        }

        app()->instance('alfakit.cli-banner', true);

        $this->newLine();
        $this->line('  <fg=#F47A22;options=bold>AlfaKit</> <fg=gray>·</> by <options=bold>Alfa Six</> <fg=gray>https://alfasixbh.com</>');
    }
}
