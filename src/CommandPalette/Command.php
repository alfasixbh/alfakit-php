<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\CommandPalette;

use AlfaSix\Alfakit\Actions\Action;
use AlfaSix\Alfakit\Support\Serializable;
use Closure;

/**
 * A panel-wide command of the command palette (`Panel::commands([...])`).
 *
 * A command is an action: `->url()` makes it a link, `->action()` runs server logic through
 * the action endpoint (with the same authorisation as every action), `->schema()` /
 * `->requiresConfirmation()` open the normal modal first. `->keywords()` help the palette
 * find it and `->shortcut()` binds a key (or a sequence such as `g p`) on every page.
 *
 * ```php
 * Command::make('clear-cache')
 *     ->label('Clear cache')
 *     ->icon('trash')
 *     ->keywords(['flush', 'reset'])
 *     ->shortcut('mod+shift+x')
 *     ->requiresConfirmation()
 *     ->visible(fn (): bool => auth()->user()?->isAdmin() ?? false)
 *     ->action(fn () => cache()->flush());
 * ```
 */
class Command extends Action
{
    /** @var array<string>|Closure */
    protected array|Closure $keywords = [];

    protected string|Closure|null $group = null;

    protected string|Closure|null $description = null;

    /**
     * Extra words the palette matches (synonyms, abbreviations).
     *
     * @param  array<string>|Closure  $keywords
     */
    public function keywords(array|Closure $keywords): static
    {
        $this->keywords = $keywords;

        return $this;
    }

    /**
     * @return array<string>
     */
    public function getKeywords(): array
    {
        $keywords = $this->evaluate($this->keywords);

        return is_array($keywords) ? array_values(array_filter(array_map(strval(...), $keywords), static fn (string $keyword): bool => $keyword !== '')) : [];
    }

    /**
     * A keyboard shortcut available on every page of the panel: `mod+shift+k` (⌘ on macOS,
     * Ctrl elsewhere) or a sequence of keys separated by spaces (`g p`). Alias of
     * `keyBindings()`; users may change it in the shortcuts overlay.
     *
     * @param  string|array<string>|Closure  $shortcut
     */
    public function shortcut(string|array|Closure $shortcut): static
    {
        return $this->keyBindings($shortcut instanceof Closure ? $shortcut : array_values((array) $shortcut));
    }

    /**
     * @return array<string>
     */
    public function getShortcuts(): array
    {
        $bindings = $this->evaluate($this->keyBindings);

        return is_array($bindings) ? array_values(array_filter($bindings, is_string(...))) : [];
    }

    /**
     * The heading the command is listed under (default: "Commands").
     */
    public function group(string|Closure|null $group): static
    {
        $this->group = $group;

        return $this;
    }

    public function getGroup(): ?string
    {
        $group = $this->evaluate($this->group);

        return is_string($group) && $group !== '' ? $group : null;
    }

    /**
     * A second line shown under the label.
     */
    public function description(string|Closure|null $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getDescription(): ?string
    {
        $description = $this->evaluate($this->description);

        return is_string($description) && $description !== '' ? $description : null;
    }

    /**
     * The action schema plus what the palette needs to list and match the command.
     *
     * @return array<string, mixed>
     */
    public function toCommandSchema(): array
    {
        return Serializable::compact([
            ...$this->toSchema(),
            'keywords' => $this->getKeywords() ?: null,
            'group' => $this->getGroup(),
            'description' => $this->getDescription(),
        ]);
    }
}
