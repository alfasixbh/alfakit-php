<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Schemas\Components;

use AlfaSix\Alfakit\Support\Enums\Alignment;
use AlfaSix\Alfakit\Support\Enums\VerticalAlignment;
use Closure;

/**
 * Lays its children out in a row that shares the available width. Below the `from()`
 * breakpoint the children stack. Children keep their natural width with `grow(false)`.
 *
 * ```php
 * Flex::make([
 *     Section::make('Post')->schema([...]),
 *     Section::make('Meta')->schema([...])->grow(false),
 * ])->from('md')
 * ```
 */
class Flex extends Component
{
    public const BREAKPOINTS = ['default', 'sm', 'md', 'lg', 'xl', '2xl'];

    protected string|Closure|null $fromBreakpoint = 'default';

    protected VerticalAlignment|string|Closure|null $verticalAlignment = null;

    protected Alignment|string|Closure|null $alignment = null;

    /**
     * @param  array<Component>|Closure  $schema
     */
    final public function __construct(array|Closure $schema = [])
    {
        $this->schema($schema);
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

    /**
     * The breakpoint (`sm`, `md`, `lg`, `xl`, `2xl`) from which children sit side by side.
     */
    public function from(string|Closure|null $breakpoint): static
    {
        $this->fromBreakpoint = $breakpoint;

        return $this;
    }

    public function getFromBreakpoint(): string
    {
        $breakpoint = $this->evaluate($this->fromBreakpoint) ?? 'default';

        return in_array($breakpoint, self::BREAKPOINTS, true) ? $breakpoint : 'default';
    }

    public function verticalAlignment(VerticalAlignment|string|Closure|null $alignment): static
    {
        $this->verticalAlignment = $alignment;

        return $this;
    }

    public function verticallyAlignStart(): static
    {
        return $this->verticalAlignment(VerticalAlignment::Start);
    }

    public function verticallyAlignCenter(): static
    {
        return $this->verticalAlignment(VerticalAlignment::Center);
    }

    public function verticallyAlignEnd(): static
    {
        return $this->verticalAlignment(VerticalAlignment::End);
    }

    public function getVerticalAlignment(): ?string
    {
        $alignment = $this->evaluate($this->verticalAlignment);

        return $alignment instanceof VerticalAlignment ? $alignment->value : $alignment;
    }

    /**
     * Horizontal alignment of the children in the row: `start` (default), `center`, `end`,
     * `between` or `justify` (`left` / `right` are read as `start` / `end`). Only visible
     * when children keep their natural width (`grow(false)`).
     */
    public function alignment(Alignment|string|Closure|null $alignment): static
    {
        $this->alignment = $alignment;

        return $this;
    }

    public function alignStart(): static
    {
        return $this->alignment(Alignment::Start);
    }

    public function alignCenter(): static
    {
        return $this->alignment(Alignment::Center);
    }

    public function alignEnd(): static
    {
        return $this->alignment(Alignment::End);
    }

    public function alignBetween(): static
    {
        return $this->alignment(Alignment::Between);
    }

    public function alignJustify(): static
    {
        return $this->alignment(Alignment::Justify);
    }

    public function alignLeft(): static
    {
        return $this->alignment(Alignment::Start);
    }

    public function alignRight(): static
    {
        return $this->alignment(Alignment::End);
    }

    public function getAlignment(): ?string
    {
        $alignment = $this->evaluate($this->alignment);
        $alignment = $alignment instanceof Alignment ? $alignment->value : $alignment;

        return match ($alignment) {
            'left' => 'start',
            'right' => 'end',
            default => $alignment,
        };
    }

    protected function getSchemaProps(): array
    {
        return [
            // Children are laid out by the flex row, not by a grid.
            'columns' => null,
            'from' => $this->getFromBreakpoint(),
            'verticalAlignment' => $this->getVerticalAlignment(),
            'alignment' => $this->getAlignment(),
        ];
    }
}
