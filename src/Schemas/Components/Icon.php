<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Schemas\Components;

use AlfaSix\Alfakit\Schemas\Components\Concerns\HasColor;
use AlfaSix\Alfakit\Schemas\Components\Concerns\HasIcon;
use AlfaSix\Alfakit\Support\Enums\Size;
use Closure;

/**
 * A single icon (prime component), e.g. next to a `Text` inside a `Flex`.
 */
class Icon extends Component
{
    use HasColor;
    use HasIcon;

    protected Size|string|Closure|null $size = null;

    protected string|Closure|null $tooltip = null;

    final public function __construct(string|Closure $icon)
    {
        $this->icon($icon);
    }

    public static function make(mixed ...$arguments): static
    {
        return parent::make(...$arguments);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->hiddenLabel();
    }

    /**
     * `xs`, `sm`, `md` (default), `lg` or `xl`.
     */
    public function size(Size|string|Closure|null $size): static
    {
        $this->size = $size;

        return $this;
    }

    public function tooltip(string|Closure|null $tooltip): static
    {
        $this->tooltip = $tooltip;

        return $this;
    }

    protected function getSchemaProps(): array
    {
        $size = $this->evaluate($this->size);

        return [
            'icon' => $this->getIcon(),
            'color' => $this->getColor(),
            'size' => $size instanceof Size ? $size->value : $size,
            'tooltip' => $this->evaluate($this->tooltip),
        ];
    }
}
