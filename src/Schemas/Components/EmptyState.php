<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Schemas\Components;

use AlfaSix\Alfakit\Schemas\Components\Concerns\HasColor;
use AlfaSix\Alfakit\Schemas\Components\Concerns\HasEmbeddedActions;
use AlfaSix\Alfakit\Schemas\Components\Concerns\HasIcon;
use AlfaSix\Alfakit\Schemas\Components\Concerns\HasMixedContent;
use AlfaSix\Alfakit\Support\Html;
use Closure;
use Illuminate\Contracts\Support\Htmlable;

/**
 * A centred placeholder for content that does not exist yet: icon, heading, description
 * and call-to-action buttons.
 *
 * ```php
 * EmptyState::make('No orders yet')
 *     ->description('Orders appear here once customers check out.')
 *     ->icon('shopping-cart')
 *     ->actions([Action::make('import')->url(...)])
 * ```
 */
class EmptyState extends Component
{
    use HasColor;
    use HasEmbeddedActions {
        getAllActions as getEmbeddedActions;
    }
    use HasIcon;
    use HasMixedContent;

    protected string|Closure|null $heading = null;

    protected string|Htmlable|Closure|null $description = null;

    protected bool|Closure $isContained = true;

    protected mixed $footer = null;

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
        $this->icon('alfakit::schemas.empty-state');
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
     * Actions and other components (text, links, …) under the description. Actions run
     * like `actions()` and are shown with them.
     */
    public function footer(mixed $content): static
    {
        $this->footer = $content;

        return $this;
    }

    /**
     * `actions()` and the actions placed in `footer()`.
     *
     * @return array<\AlfaSix\Alfakit\Actions\Action>
     */
    public function getAllActions(): array
    {
        return [
            ...$this->getEmbeddedActions(),
            ...$this->getMixedContentActions($this->normalizeMixedContent($this->footer)),
        ];
    }

    /**
     * Draw the dashed card around the empty state (on by default).
     */
    public function contained(bool|Closure $condition = true): static
    {
        $this->isContained = $condition;

        return $this;
    }

    /**
     * @return array<array<string, mixed>>
     */
    protected function serializeEmbeddedActionList(): array
    {
        return $this->serializeEmbeddedActions();
    }

    protected function getSchemaProps(): array
    {
        return [
            'heading' => ($heading = $this->evaluate($this->heading)) === null ? null : (string) $heading,
            'description' => Html::from($this->evaluate($this->description)),
            'icon' => $this->getIcon(),
            'color' => $this->getColor(),
            'contained' => (bool) $this->evaluate($this->isContained),
            'actions' => $this->serializeEmbeddedActionList() ?: null,
            'footer' => $this->serializeMixedContent($this->normalizeMixedContent($this->footer), 'footer') ?: null,
        ];
    }
}
