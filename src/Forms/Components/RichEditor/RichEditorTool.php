<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Forms\Components\RichEditor;

use AlfaSix\Alfakit\Support\Icons;
use Illuminate\Support\Str;

/**
 * A toolbar button contributed by a rich content plugin (`getEditorTools()`). It is
 * registered with the editor and shown wherever its name is placed in `toolbarButtons()`
 * or `floatingToolbars()`. When clicked it either runs Tiptap commands in the browser
 * (`command()`), opens the plugin's editor action of the same name (`action()`), or runs a
 * command registered by the plugin's JavaScript extension under the same name.
 *
 *     RichEditorTool::make('highlight')
 *         ->label('Highlight')
 *         ->icon('highlighter')
 *         ->command('toggleMark', 'highlight')
 *         ->activeWhen('highlight')
 *
 * There is no `jsHandler()`: JavaScript strings from PHP are never evaluated. Register a
 * frontend command instead (`createAlfakit({ richEditorExtensions })`).
 */
final class RichEditorTool
{
    private ?string $label = null;

    private ?string $icon = null;

    /** @var list<EditorCommand> */
    private array $commands = [];

    private bool $runsAction = false;

    private ?string $activeName = null;

    /** @var array<string, mixed> */
    private array $activeAttributes = [];

    public function __construct(private readonly string $name) {}

    public static function make(string $name): self
    {
        return new self($name);
    }

    public function label(?string $label): self
    {
        $this->label = $label;

        return $this;
    }

    public function icon(?string $icon): self
    {
        $this->icon = $icon;

        return $this;
    }

    /**
     * Run a Tiptap chain command when clicked. Call it more than once to chain commands.
     */
    public function command(string $name, mixed ...$arguments): self
    {
        $this->commands[] = EditorCommand::make($name, ...$arguments);

        return $this;
    }

    /**
     * Open the plugin's editor action named like the tool (`getEditorActions()`). The
     * action receives the selection as `$arguments['editorSelection']` and the current
     * attributes of the tool's active mark or node as `$arguments['attributes']`.
     */
    public function action(bool $condition = true): self
    {
        $this->runsAction = $condition;

        return $this;
    }

    /**
     * Show the button as pressed while this mark or node (with these attributes) is active.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function activeWhen(?string $name, array $attributes = []): self
    {
        $this->activeName = $name;
        $this->activeAttributes = $attributes;

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getLabel(): string
    {
        return $this->label ?? Str::headline($this->name);
    }

    public function runsAction(): bool
    {
        return $this->runsAction;
    }

    /**
     * @return array<string, mixed>
     */
    public function toSchema(): array
    {
        return array_filter([
            'name' => $this->name,
            'label' => $this->getLabel(),
            'icon' => Icons::resolve($this->icon),
            'commands' => $this->commands === [] ? null : array_map(static fn (EditorCommand $command): array => $command->toArray(), $this->commands),
            'action' => $this->runsAction ?: null,
            'active' => $this->activeName === null ? null : array_filter(['name' => $this->activeName, 'attributes' => $this->activeAttributes ?: null]),
        ], static fn (mixed $value): bool => $value !== null);
    }
}
