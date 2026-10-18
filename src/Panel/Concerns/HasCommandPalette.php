<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Panel\Concerns;

use AlfaSix\Alfakit\Actions\Action;
use AlfaSix\Alfakit\CommandPalette\Command;
use AlfaSix\Alfakit\CommandPalette\KeyboardShortcuts;
use AlfaSix\Alfakit\Support\Serializable;
use Closure;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * The command palette (global search plus page actions, panel commands, navigation,
 * "create" links, recent records and theme switching) and keyboard shortcuts.
 */
trait HasCommandPalette
{
    /** Action endpoint component of panel commands (`Panel::commands()`). */
    public const COMMANDS_COMPONENT = 'commands';

    protected bool|Closure|null $hasCommandPalette = null;

    /** @var array<Action>|Closure */
    protected array|Closure $commands = [];

    protected int|bool|Closure $recentRecords = 5;

    protected bool|Closure $hasKeyboardShortcuts = true;

    protected bool|Closure $hasCustomizableKeyboardShortcuts = true;

    /**
     * Turn the command palette's extras (actions, commands, navigation, create links,
     * recent records, theme) on or off. By default they are on whenever the palette exists:
     * with global search or with panel commands.
     */
    public function commandPalette(bool|Closure $condition = true): static
    {
        $this->hasCommandPalette = $condition;

        return $this;
    }

    public function hasCommandPalette(): bool
    {
        $condition = $this->evaluate($this->hasCommandPalette);

        return $condition === null ? $this->hasGlobalSearch() || $this->getCommands() !== [] : (bool) $condition;
    }

    /**
     * Panel-wide commands listed in the palette (and bound to their `shortcut()`):
     * `Command::make('…')` or any `Action`.
     *
     * @param  array<Action>|Closure  $commands
     */
    public function commands(array|Closure $commands): static
    {
        $this->commands = $commands;

        return $this;
    }

    /**
     * @return array<string, Action>
     */
    public function getCommands(): array
    {
        $commands = [];

        foreach ((array) $this->evaluate($this->commands) as $command) {
            if ($command instanceof Action) {
                $commands[$command->getName()] = $command;
            }
        }

        return $commands;
    }

    /**
     * A command the action endpoint may run (the endpoint checks visibility and authorisation); never links (those
     * navigate in the browser).
     */
    public function getCommand(string $name): ?Action
    {
        $command = $this->getCommands()[$name] ?? null;

        return $command === null || $command->isLink() ? null : $command;
    }

    /**
     * How many recently opened records the palette lists (`false` or `0` turns it off).
     */
    public function recentRecords(int|bool|Closure $limit = 5): static
    {
        $this->recentRecords = $limit;

        return $this;
    }

    public function getRecentRecordsLimit(): int
    {
        $limit = $this->evaluate($this->recentRecords);

        return match (true) {
            $limit === true => 5,
            is_int($limit) => max(0, min($limit, 20)),
            default => 0,
        };
    }

    /**
     * Keyboard shortcuts: panel commands' `shortcut()`, `/` (focus the table search),
     * `g d` (dashboard) and `?` (the shortcuts overlay). Action `keyBindings()` and the
     * global search key bindings keep working when this is off.
     */
    public function keyboardShortcuts(bool|Closure $condition = true, bool|Closure $customizable = true): static
    {
        $this->hasKeyboardShortcuts = $condition;
        $this->hasCustomizableKeyboardShortcuts = $customizable;

        return $this;
    }

    public function hasKeyboardShortcuts(): bool
    {
        return (bool) $this->evaluate($this->hasKeyboardShortcuts);
    }

    public function hasCustomizableKeyboardShortcuts(): bool
    {
        return $this->hasKeyboardShortcuts() && (bool) $this->evaluate($this->hasCustomizableKeyboardShortcuts);
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function getCommandPaletteSchema(?Authenticatable $user): ?array
    {
        if ($user === null || ! $this->hasCommandPalette()) {
            return null;
        }

        $commands = [];

        foreach ($this->getCommands() as $command) {
            if (! $command->isVisible()) {
                continue;
            }

            $commands[] = $command instanceof Command ? $command->toCommandSchema() : $command->toSchema();
        }

        return Serializable::compact([
            'commands' => $commands,
            'recentRecords' => $this->getRecentRecordsLimit() > 0 ?: null,
            'placeholder' => __('alfakit::panel.command_palette.placeholder'),
        ]);
    }

    /**
     * @return array<string, mixed>|false|null
     */
    protected function getKeyboardShortcutsSchema(?Authenticatable $user): array|false|null
    {
        if ($user === null) {
            return null;
        }

        if (! $this->hasKeyboardShortcuts()) {
            return false;
        }

        $customizable = $this->hasCustomizableKeyboardShortcuts();

        return Serializable::compact([
            'overrides' => $customizable ? (KeyboardShortcuts::overridesFor($this, $user) ?: null) : null,
            'customizable' => $customizable ?: null,
        ]);
    }
}
