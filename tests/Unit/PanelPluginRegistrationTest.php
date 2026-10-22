<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Contracts\Plugin;
use AlfaSix\Alfakit\Panel;

function registrationPlugin(string $id): Plugin
{
    return new class($id) implements Plugin
    {
        public function __construct(private readonly string $id) {}

        public function getId(): string
        {
            return $this->id;
        }

        public function register(Panel $panel): void {}

        public function boot(Panel $panel): void {}
    };
}

it('registers every plugin passed to one plugin() call', function (): void {
    // `->plugin(KanbanPlugin::make(), CmsPlugin::make())`: PHP dropped the second one silently.
    $panel = Panel::make()->id('plugins')->plugin(registrationPlugin('kanban'), registrationPlugin('cms'));

    expect($panel->hasPlugin('kanban'))->toBeTrue()
        ->and($panel->hasPlugin('cms'))->toBeTrue();
});
