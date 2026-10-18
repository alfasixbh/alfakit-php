<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Schemas\Components;

use AlfaSix\Alfakit\Schemas\Components\Concerns\HasIcon;
use AlfaSix\Alfakit\Schemas\Components\Concerns\HasMixedContent;
use AlfaSix\Alfakit\Schemas\Components\Concerns\PreparesActions;
use AlfaSix\Alfakit\Support\Enums\Alignment;
use Closure;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Str;

class Section extends Group
{
    use HasIcon;
    use HasMixedContent;
    use PreparesActions;

    protected string|Htmlable|Closure|null $description = null;

    protected bool|Closure $isCollapsible = false;

    protected bool|Closure $isCollapsed = false;

    protected bool|Closure $isCompact = false;

    protected bool|Closure $isAside = false;

    protected bool|Closure $isSecondary = false;

    protected bool|Closure $shouldPersistCollapsed = false;

    protected int|Closure|null $headingLevel = null;

    /** @var array<\AlfaSix\Alfakit\Actions\Action|\AlfaSix\Alfakit\Actions\ActionGroup|Closure>|Closure */
    protected array|Closure $headerActions = [];

    protected mixed $afterHeader = null;

    protected mixed $footer = null;

    protected Alignment|string|Closure|null $footerActionsAlignment = null;

    /** @var array<\AlfaSix\Alfakit\Actions\Action|\AlfaSix\Alfakit\Actions\ActionGroup|Closure>|Closure */
    protected array|Closure $footerActions = [];

    public function __construct(string|Htmlable|Closure|null $heading = null)
    {
        $this->heading($heading);
    }

    public static function make(mixed ...$arguments): static
    {
        return parent::make(...$arguments);
    }

    protected function setUp(): void
    {
        $this->columns(['default' => 1, 'lg' => 2]);
        $this->columnSpanFull();
    }

    public function heading(string|Htmlable|Closure|null $heading): static
    {
        $this->label = $heading instanceof Htmlable ? $heading->toHtml() : $heading;
        $this->isLabelHidden = false;

        return $this;
    }

    public function description(string|Htmlable|Closure|null $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function collapsible(bool|Closure $condition = true): static
    {
        $this->isCollapsible = $condition;

        return $this;
    }

    public function collapsed(bool|Closure $condition = true): static
    {
        $this->isCollapsed = $condition;

        return $this->collapsible();
    }

    /**
     * Remember whether the section is collapsed in the browser's local storage, under the
     * section's `id()` (or a key made from its heading). Implies `collapsible()`.
     */
    public function persistCollapsed(bool|Closure $condition = true): static
    {
        $this->shouldPersistCollapsed = $condition;

        if ($condition !== false) {
            $this->collapsible();
        }

        return $this;
    }

    public function shouldPersistCollapsed(): bool
    {
        return (bool) $this->evaluate($this->shouldPersistCollapsed);
    }

    /**
     * The local storage key for `persistCollapsed()`: the `id()`, or one derived from the
     * heading (set an `id()` when headings repeat).
     */
    public function getPersistCollapsedKey(): string
    {
        $id = $this->getId();

        if (filled($id)) {
            return 'alfakit.section.'.$id;
        }

        $heading = $this->getHeading();

        return 'alfakit.section.'.(filled($heading) ? Str::slug((string) $heading) : $this->getKey());
    }

    /**
     * The heading's level (`2` renders an `h2`). By default top-level sections use the
     * root schema's `rootHeadingLevel()` (2) and nested sections go one level deeper.
     */
    public function headingLevel(int|Closure|null $level): static
    {
        $this->headingLevel = $level;

        return $this;
    }

    public function getHeadingLevel(): int
    {
        $level = $this->evaluate($this->headingLevel);

        if ($level !== null) {
            return max(1, min(6, (int) $level));
        }

        if (! $this->hasContainer()) {
            return 2;
        }

        $depth = 0;
        $container = $this->getContainer();

        while (true) {
            if (method_exists($container, 'getRootHeadingLevel') && ($root = $container->getRootHeadingLevel()) !== null) {
                return min(6, $root + $depth);
            }

            $parent = $container->getParentComponent();

            if ($parent === null) {
                break;
            }

            // `Group::make()->rootHeadingLevel(3)`: sections inside start at that level.
            if (($root = $parent->getRootHeadingLevel()) !== null) {
                return min(6, $root + $depth);
            }

            if ($parent instanceof self) {
                // A parent with an explicit level anchors the levels below it.
                if ($parent->headingLevel !== null) {
                    return min(6, $parent->getHeadingLevel() + 1 + $depth);
                }

                $depth++;
            }

            if (! $parent->hasContainer()) {
                break;
            }

            $container = $parent->getContainer();
        }

        return min(6, 2 + $depth);
    }

    /**
     * Actions and other components (badges, text, icons…) at the end of the header, after
     * the heading. Actions run like `headerActions()`.
     */
    public function afterHeader(mixed $content): static
    {
        $this->afterHeader = $content;

        return $this;
    }

    /**
     * Actions and other components (text, badges, …) in the footer, after any
     * `footerActions()`. Actions run like `footerActions()`.
     */
    public function footer(mixed $content): static
    {
        $this->footer = $content;

        return $this;
    }

    public function compact(bool|Closure $condition = true): static
    {
        $this->isCompact = $condition;

        return $this;
    }

    /**
     * Render heading and description in a side column next to the content.
     */
    public function aside(bool|Closure $condition = true): static
    {
        $this->isAside = $condition;

        return $this;
    }

    public function secondary(bool|Closure $condition = true): static
    {
        $this->isSecondary = $condition;

        return $this;
    }

    /**
     * @param  array<\AlfaSix\Alfakit\Actions\Action|\AlfaSix\Alfakit\Actions\ActionGroup|Closure>|Closure  $actions
     */
    public function headerActions(array|Closure $actions): static
    {
        $this->headerActions = $actions;

        return $this;
    }

    /**
     * @param  array<\AlfaSix\Alfakit\Actions\Action|\AlfaSix\Alfakit\Actions\ActionGroup|Closure>|Closure  $actions
     */
    public function footerActions(array|Closure $actions): static
    {
        $this->footerActions = $actions;

        return $this;
    }

    /**
     * Alignment of the footer actions: `start` (default), `center`, `end` or `between`.
     */
    public function footerActionsAlignment(Alignment|string|Closure|null $alignment): static
    {
        $this->footerActionsAlignment = $alignment;

        return $this;
    }

    public function getFooterActionsAlignment(): ?string
    {
        $alignment = $this->evaluate($this->footerActionsAlignment);

        return $alignment instanceof Alignment ? $alignment->value : $alignment;
    }

    public function getHeading(): ?string
    {
        return $this->getLabel();
    }

    public function getDescription(): ?string
    {
        $description = $this->evaluate($this->description);

        return $description instanceof Htmlable ? $description->toHtml() : $description;
    }

    protected function getDefaultLabel(): ?string
    {
        return null;
    }

    /**
     * Header actions and groups, bound to this section (its field context too, so `$get` /
     * `$set` / `$component` work).
     *
     * @return array<int, \AlfaSix\Alfakit\Actions\Action|\AlfaSix\Alfakit\Actions\ActionGroup>
     */
    public function getHeaderActions(): array
    {
        return $this->prepareActions($this->headerActions, withFieldContext: true);
    }

    /**
     * @return array<int, \AlfaSix\Alfakit\Actions\Action|\AlfaSix\Alfakit\Actions\ActionGroup>
     */
    public function getFooterActions(): array
    {
        return $this->prepareActions($this->footerActions, withFieldContext: true);
    }

    /**
     * Every action (groups flattened), for pages resolving section actions by name.
     *
     * @return array<\AlfaSix\Alfakit\Actions\Action>
     */
    public function getAllActions(): array
    {
        return [
            ...$this->flattenActions([...$this->getHeaderActions(), ...$this->getFooterActions()]),
            ...$this->getMixedContentActions($this->normalizeMixedContent($this->afterHeader)),
            ...$this->getMixedContentActions($this->normalizeMixedContent($this->footer)),
        ];
    }

    /**
     * Header, footer and after-header actions of a section inside a form run through the
     * field action endpoint (`field:{schema}::{key}`), so `$get` / `$set` reach the form.
     *
     * @return array<string, \AlfaSix\Alfakit\Actions\Action>
     */
    public function getFieldActions(): array
    {
        $actions = [];

        foreach ($this->getAllActions() as $action) {
            $actions[$action->getName()] = $action;
        }

        return $actions;
    }

    public function getFieldAction(string $name): ?\AlfaSix\Alfakit\Actions\Action
    {
        return $this->getFieldActions()[$name] ?? null;
    }

    protected function getSchemaProps(): array
    {
        return [
            'heading' => $this->getHeading(),
            'headingLevel' => $this->getHeading() !== null ? $this->getHeadingLevel() : null,
            'description' => $this->getDescription(),
            'icon' => $this->getIcon(),
            'collapsible' => $this->evaluate($this->isCollapsible) ?: null,
            'collapsed' => $this->evaluate($this->isCollapsed) ?: null,
            'persistCollapsed' => $this->evaluate($this->isCollapsible) && $this->shouldPersistCollapsed() ? $this->getPersistCollapsedKey() : null,
            'afterHeader' => $this->serializeMixedContent($this->normalizeMixedContent($this->afterHeader), 'afterHeader') ?: null,
            'compact' => $this->evaluate($this->isCompact) ?: null,
            'aside' => $this->evaluate($this->isAside) ?: null,
            'secondary' => $this->evaluate($this->isSecondary) ?: null,
            'headerActions' => $this->serializePreparedActions($this->getHeaderActions()) ?: null,
            'footerActions' => $this->serializePreparedActions($this->getFooterActions()) ?: null,
            'footerActionsAlignment' => $this->getFooterActionsAlignment(),
            'footer' => $this->serializeMixedContent($this->normalizeMixedContent($this->footer), 'footer') ?: null,
        ];
    }
}
