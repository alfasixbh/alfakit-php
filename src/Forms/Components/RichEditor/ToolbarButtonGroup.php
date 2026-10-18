<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Forms\Components\RichEditor;

/**
 * Toolbar buttons collapsed into one dropdown, placed in `toolbarButtons()` or
 * `floatingToolbars()` like a button name:
 *
 *     RichEditor::make('content')->toolbarButtons([
 *         ToolbarButtonGroup::make('Format', ['h1', 'h2', 'h3'])->icon('heading'),
 *         'bold', 'italic',
 *     ])
 *
 * Without an icon the trigger shows the icon of the active button (or the first one).
 * `textualButtons()` lists the buttons with their labels instead of a row of icons.
 */
final class ToolbarButtonGroup
{
    private ?string $icon = null;

    private bool $hasTextualButtons = false;

    /**
     * @param  array<string>  $buttons
     */
    public function __construct(private readonly string $label, private readonly array $buttons) {}

    /**
     * @param  array<string>  $buttons
     */
    public static function make(string $label, array $buttons): self
    {
        return new self($label, $buttons);
    }

    public function icon(?string $icon): self
    {
        $this->icon = $icon;

        return $this;
    }

    public function textualButtons(bool $condition = true): self
    {
        $this->hasTextualButtons = $condition;

        return $this;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    /**
     * @return array<string>
     */
    public function getButtons(): array
    {
        return array_values(array_filter($this->buttons, static fn (mixed $button): bool => is_string($button) && $button !== '|'));
    }

    public function getIcon(): ?string
    {
        return $this->icon;
    }

    public function hasTextualButtons(): bool
    {
        return $this->hasTextualButtons;
    }
}
