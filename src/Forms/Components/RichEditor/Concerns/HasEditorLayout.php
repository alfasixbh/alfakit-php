<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Forms\Components\RichEditor\Concerns;

use AlfaSix\Alfakit\Forms\Components\RichEditor\ToolbarButtonGroup;
use AlfaSix\Alfakit\Support\Icons;
use Closure;

/**
 * How the RichEditor lays out its controls: floating toolbars, grouped dropdowns, sticky
 * toolbar and panels, the custom block and merge tag panels, and resizable images.
 */
trait HasEditorLayout
{
    /** @var array<string, array<string|ToolbarButtonGroup>>|Closure */
    protected array|Closure $floatingToolbars = [];

    protected bool|Closure $isToolbarSticky = false;

    protected bool|Closure $arePanelsSticky = false;

    protected string|Closure|null $stickyOffset = null;

    protected bool|Closure $hasResizableImages = false;

    protected bool|Closure $hasMinimalCustomBlockControls = false;

    protected bool|Closure $hasCustomBlocksGrid = false;

    protected bool|Closure $hasSearchableCustomBlocks = false;

    protected string|Closure|null $activePanel = null;

    /**
     * Toolbars shown next to the selection, keyed by the node they apply to: `paragraph`
     * and `heading` (shown while text is selected), `table`, `image`, `details`,
     * `customBlock`, `blockquote`, `codeBlock`, `bulletList`, `orderedList` (shown while
     * the cursor is inside). A `table` floating toolbar replaces the table row of buttons.
     *
     *     ->floatingToolbars([
     *         'paragraph' => ['bold', 'italic', 'link'],
     *         'table' => ['tableAddRowAfter', 'tableDeleteRow', 'tableDelete'],
     *     ])
     *
     * @param  array<string, array<string|ToolbarButtonGroup>>|Closure  $toolbars
     */
    public function floatingToolbars(array|Closure $toolbars): static
    {
        $this->floatingToolbars = $toolbars;

        return $this;
    }

    /**
     * Keep the toolbar in view while the content scrolls (below the panel's topbar, or
     * `stickyOffset()`).
     */
    public function stickyToolbar(bool|Closure $condition = true): static
    {
        $this->isToolbarSticky = $condition;

        return $this;
    }

    /**
     * Keep the custom block and merge tag panels in view while the content scrolls. Panels
     * only stick when the editor is wide enough to show them beside the content.
     */
    public function stickyPanels(bool|Closure $condition = true): static
    {
        $this->arePanelsSticky = $condition;

        return $this;
    }

    /**
     * Distance from the top of the viewport for the sticky toolbar and panels, as a CSS
     * length (`'5rem'`, `'64px'`). Null measures the panel's sticky topbar.
     */
    public function stickyOffset(string|Closure|null $offset): static
    {
        $this->stickyOffset = $offset;

        return $this;
    }

    /**
     * Let users resize images by dragging their handles (aspect ratio kept). The width is
     * stored on the image (`<img width="320">`).
     */
    public function resizableImages(bool|Closure $condition = true): static
    {
        $this->hasResizableImages = $condition;

        return $this;
    }

    /**
     * Compact edit and remove buttons beside custom block previews instead of a header
     * with the block's label (the label stays available to screen readers).
     */
    public function minimalCustomBlockControls(bool|Closure $condition = true): static
    {
        $this->hasMinimalCustomBlockControls = $condition;

        return $this;
    }

    /**
     * Show the custom blocks panel as a grid instead of a list.
     */
    public function customBlocksGrid(bool|Closure $condition = true): static
    {
        $this->hasCustomBlocksGrid = $condition;

        return $this;
    }

    /**
     * A search field in the custom blocks panel (matches block labels and group names).
     */
    public function searchableCustomBlocks(bool|Closure $condition = true): static
    {
        $this->hasSearchableCustomBlocks = $condition;

        return $this;
    }

    /**
     * The panel open when the editor loads: `'customBlocks'` or `'mergeTags'`.
     */
    public function activePanel(string|Closure|null $panel): static
    {
        $this->activePanel = $panel;

        return $this;
    }

    public function hasResizableImages(): bool
    {
        return (bool) $this->evaluate($this->hasResizableImages);
    }

    public function getStickyOffset(): ?string
    {
        $offset = $this->evaluate($this->stickyOffset);

        if (! is_string($offset) || trim($offset) === '') {
            return null;
        }

        $offset = trim($offset);

        // A plain CSS length (it ends up in an inline style).
        return preg_match('/^-?\d+(?:\.\d+)?(?:px|rem|em|vh|svh|dvh|%)?$/', $offset) === 1 ? $offset : null;
    }

    public function getActivePanel(): ?string
    {
        $panel = $this->evaluate($this->activePanel);

        return in_array($panel, ['customBlocks', 'mergeTags'], true) ? $panel : null;
    }

    /**
     * Toolbar entries as button names, with groups replaced by `group:{n}` keys whose
     * definitions are collected in `$groups`.
     *
     * @param  array<mixed>  $buttons
     * @param  array<string>  $disabled
     * @param  array<string, array<string, mixed>>  $groups
     * @return list<string>
     */
    protected function serializeToolbarButtons(array $buttons, array $disabled, array &$groups): array
    {
        $serialized = [];

        foreach ($buttons as $button) {
            // Filament 4+ separated groups: `[['bold', 'italic'], ['h2', 'h3']]` → `'|'` between them.
            if (is_array($button)) {
                $nested = $this->serializeToolbarButtons($button, $disabled, $groups);

                if ($nested !== [] && $serialized !== [] && end($serialized) !== '|') {
                    $serialized[] = '|';
                }

                array_push($serialized, ...$nested);

                continue;
            }

            if ($button instanceof ToolbarButtonGroup) {
                $names = array_values(array_filter($button->getButtons(), static fn (string $name): bool => ! in_array($name, $disabled, true)));

                if ($names === []) {
                    continue;
                }

                $key = 'group:'.count($groups);
                $groups[$key] = array_filter([
                    'label' => $button->getLabel(),
                    'icon' => Icons::resolve($button->getIcon()),
                    'buttons' => $names,
                    'textual' => $button->hasTextualButtons() ?: null,
                ], static fn (mixed $value): bool => $value !== null);
                $serialized[] = $key;

                continue;
            }

            if (is_string($button) && ($button === '|' || ! in_array($button, $disabled, true))) {
                $serialized[] = $button;
            }
        }

        return $serialized;
    }

    /**
     * Every button name in a toolbar definition, including those inside groups.
     *
     * @param  array<mixed>  $buttons
     * @return list<string>
     */
    protected static function flattenToolbarButtons(array $buttons): array
    {
        $names = [];

        foreach ($buttons as $button) {
            if (is_array($button)) {
                array_push($names, ...static::flattenToolbarButtons($button));
            } elseif ($button instanceof ToolbarButtonGroup) {
                array_push($names, ...$button->getButtons());
            } elseif (is_string($button)) {
                $names[] = $button;
            }
        }

        return $names;
    }

    /**
     * @param  array<string>  $disabled
     * @param  array<string, array<string, mixed>>  $groups
     * @return array<string, list<string>>|null
     */
    protected function getFloatingToolbarsSchema(array $disabled, array &$groups): ?array
    {
        $toolbars = [];

        foreach ($this->evaluate($this->floatingToolbars) ?? [] as $node => $buttons) {
            if (! is_string($node) || ! is_array($buttons)) {
                continue;
            }

            $serialized = $this->serializeToolbarButtons($buttons, $disabled, $groups);

            if ($serialized !== []) {
                $toolbars[$node] = $serialized;
            }
        }

        return $toolbars === [] ? null : $toolbars;
    }

    /**
     * @return array<string, mixed>
     */
    protected function getEditorLayoutSchemaProps(): array
    {
        $stickyToolbar = (bool) $this->evaluate($this->isToolbarSticky);
        $stickyPanels = (bool) $this->evaluate($this->arePanelsSticky);

        return [
            'stickyToolbar' => $stickyToolbar ?: null,
            'stickyPanels' => $stickyPanels ?: null,
            'stickyOffset' => $stickyToolbar || $stickyPanels ? $this->getStickyOffset() : null,
            'resizableImages' => $this->hasResizableImages() ?: null,
            'minimalCustomBlockControls' => ((bool) $this->evaluate($this->hasMinimalCustomBlockControls)) ?: null,
            'customBlocksGrid' => ((bool) $this->evaluate($this->hasCustomBlocksGrid)) ?: null,
            'searchableCustomBlocks' => ((bool) $this->evaluate($this->hasSearchableCustomBlocks)) ?: null,
            'activePanel' => $this->getActivePanel(),
        ];
    }
}
