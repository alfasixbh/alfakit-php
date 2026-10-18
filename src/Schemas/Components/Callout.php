<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Schemas\Components;

use AlfaSix\Alfakit\Schemas\Components\Concerns\HasColor;
use AlfaSix\Alfakit\Schemas\Components\Concerns\HasEmbeddedActions;
use AlfaSix\Alfakit\Schemas\Components\Concerns\HasIcon;
use AlfaSix\Alfakit\Schemas\Components\Concerns\HasMixedContent;
use AlfaSix\Alfakit\Support\Enums\Alignment;
use AlfaSix\Alfakit\Support\Enums\Color;
use AlfaSix\Alfakit\Support\Enums\Size;
use AlfaSix\Alfakit\Support\Html;
use Closure;
use Illuminate\Contracts\Support\Htmlable;

/**
 * A highlighted message (an alert box) with an optional icon, description and actions.
 *
 * ```php
 * Callout::make('Payment overdue')->description('Pay before Friday.')->warning()
 * ```
 */
class Callout extends Component
{
    use HasColor;
    use HasEmbeddedActions {
        getAllActions as getEmbeddedActions;
    }
    use HasIcon;
    use HasMixedContent;

    protected string|Closure|null $heading = null;

    protected string|Htmlable|Closure|null $description = null;

    protected Size|string|Closure|null $iconSize = null;

    protected Alignment|string|Closure|null $footerActionsAlignment = null;

    protected mixed $controls = null;

    protected mixed $footer = null;

    /** The status set by `status()` / `danger()`…: keeps the icon colour and alert role after `color(null)`. */
    protected ?string $status = null;

    protected string|Color|Closure|null $iconColor = null;

    /** @var array<\AlfaSix\Alfakit\Actions\Action|\AlfaSix\Alfakit\Actions\ActionGroup>|Closure */
    protected array|Closure $controlActions = [];

    final public function __construct(string|Closure|null $heading = null)
    {
        $this->heading = $heading;
    }

    public static function make(mixed ...$arguments): static
    {
        return parent::make(...$arguments);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->hiddenLabel();
        $this->columnSpanFull();
    }

    public function heading(string|Closure|null $heading): static
    {
        $this->heading = $heading;

        return $this;
    }

    /**
     * Plain strings are escaped; pass an `Htmlable` for markup you trust.
     */
    public function description(string|Htmlable|Closure|null $description): static
    {
        $this->description = $description;

        return $this;
    }

    /**
     * Colour the callout and use the status icon (unless an icon was set).
     */
    public function status(Color|string $status): static
    {
        $value = $status instanceof Color ? $status->value : $status;
        $this->status = $value;

        $this->color($value);

        if ($this->icon === null) {
            $this->icon("alfakit::schemas.callout.{$value}");
        }

        return $this;
    }

    public function danger(): static
    {
        return $this->status(Color::Danger);
    }

    public function info(): static
    {
        return $this->status(Color::Info);
    }

    public function success(): static
    {
        return $this->status(Color::Success);
    }

    public function warning(): static
    {
        return $this->status(Color::Warning);
    }

    public function getStatus(): ?string
    {
        return $this->status;
    }

    /**
     * The icon's colour; by default the callout's colour, or its status colour after
     * `color(null)`.
     */
    public function iconColor(string|Color|Closure|null $color): static
    {
        $this->iconColor = $color;

        return $this;
    }

    public function getIconColor(): ?string
    {
        $color = $this->evaluate($this->iconColor);

        return $color instanceof Color ? $color->value : $color;
    }

    /**
     * Actions and other components (text, badges, …) under the description. Actions run
     * like `actions()`.
     */
    public function footer(mixed $content): static
    {
        $this->footer = $content;

        return $this;
    }

    /**
     * `sm`, `md` or `lg` (the default).
     */
    public function iconSize(Size|string|Closure|null $size): static
    {
        $this->iconSize = $size;

        return $this;
    }

    public function getIconSize(): ?string
    {
        $size = $this->evaluate($this->iconSize);

        return $size instanceof Size ? $size->value : $size;
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

    /**
     * Components (and actions) in the top end corner.
     */
    public function controls(mixed $components): static
    {
        $this->controls = $components;

        return $this;
    }

    /**
     * Actions in the top end corner, e.g. a dismiss icon button.
     *
     * @param  array<\AlfaSix\Alfakit\Actions\Action|\AlfaSix\Alfakit\Actions\ActionGroup>|Closure  $actions
     */
    public function controlActions(array|Closure $actions): static
    {
        $this->controlActions = $actions;

        return $this;
    }

    /**
     * Footer actions, control actions and actions placed in `controls()`.
     *
     * @return array<\AlfaSix\Alfakit\Actions\Action>
     */
    public function getAllActions(): array
    {
        return [
            ...$this->getEmbeddedActions(),
            ...$this->getMixedContentActions($this->normalizeMixedContent($this->controlActions)),
            ...$this->getMixedContentActions($this->normalizeMixedContent($this->controls)),
            ...$this->getMixedContentActions($this->normalizeMixedContent($this->footer)),
        ];
    }

    /**
     * @return array<array<string, mixed>>
     */
    protected function serializeFooterActions(): array
    {
        return $this->serializeEmbeddedActions();
    }

    public function getHeading(): ?string
    {
        $heading = $this->evaluate($this->heading);

        return $heading === null ? null : (string) $heading;
    }

    public function getDescription(): ?string
    {
        return Html::from($this->evaluate($this->description));
    }

    protected function getSchemaProps(): array
    {
        return [
            'heading' => $this->getHeading(),
            'description' => $this->getDescription(),
            'icon' => $this->getIcon(),
            'color' => $this->getColor(),
            'status' => $this->getStatus(),
            'iconColor' => $this->getIconColor(),
            'iconSize' => $this->getIconSize(),
            'actions' => $this->serializeFooterActions() ?: null,
            'footerActionsAlignment' => $this->getFooterActionsAlignment(),
            'controls' => $this->serializeMixedContent([
                ...$this->normalizeMixedContent($this->controls),
                ...$this->normalizeMixedContent($this->controlActions),
            ], 'controls') ?: null,
            'footer' => $this->serializeMixedContent($this->normalizeMixedContent($this->footer), 'footer') ?: null,
        ];
    }
}
