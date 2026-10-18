<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Schemas\Components;

use AlfaSix\Alfakit\Schemas\Components\Concerns\HasColor;
use AlfaSix\Alfakit\Schemas\Components\Concerns\HasIcon;
use Closure;

/**
 * A line of styled text (prime component).
 */
class Text extends Component
{
    use HasColor;
    use HasIcon;

    protected string|Closure $content;

    protected string|Closure|null $size = null;

    protected string|Closure|null $weight = null;

    protected bool|Closure $isBadge = false;

    protected string|Closure|null $fontFamily = null;

    protected string|Closure|null $tooltip = null;

    protected bool|Closure $isCopyable = false;

    protected string|Closure|null $copyMessage = null;

    protected int|Closure|null $copyMessageDuration = null;

    public function __construct(string|Closure $content)
    {
        $this->content = $content;
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

    public function size(string|Closure|null $size): static
    {
        $this->size = $size;

        return $this;
    }

    public function weight(string|Closure|null $weight): static
    {
        $this->weight = $weight;

        return $this;
    }

    public function badge(bool|Closure $condition = true): static
    {
        $this->isBadge = $condition;

        return $this;
    }

    /**
     * `sans` (default), `serif` or `mono`.
     */
    public function fontFamily(string|Closure|null $family): static
    {
        $this->fontFamily = $family;

        return $this;
    }

    public function getFontFamily(): ?string
    {
        $family = $this->evaluate($this->fontFamily);

        return $family === null ? null : strtolower((string) $family);
    }

    public function tooltip(string|Closure|null $tooltip): static
    {
        $this->tooltip = $tooltip;

        return $this;
    }

    public function getTooltip(): ?string
    {
        $tooltip = $this->evaluate($this->tooltip);

        return $tooltip === null ? null : (string) $tooltip;
    }

    /**
     * A button that copies the text to the clipboard, with a confirmation shown for
     * `$copyMessageDuration` milliseconds.
     */
    public function copyable(bool|Closure $condition = true, string|Closure|null $copyMessage = null, int|Closure|null $copyMessageDuration = null): static
    {
        $this->isCopyable = $condition;

        if ($copyMessage !== null) {
            $this->copyMessage = $copyMessage;
        }

        if ($copyMessageDuration !== null) {
            $this->copyMessageDuration = $copyMessageDuration;
        }

        return $this;
    }

    public function copyMessage(string|Closure|null $message): static
    {
        $this->copyMessage = $message;

        return $this;
    }

    public function copyMessageDuration(int|Closure|null $duration): static
    {
        $this->copyMessageDuration = $duration;

        return $this;
    }

    public function isCopyable(): bool
    {
        return (bool) $this->evaluate($this->isCopyable);
    }

    protected function getSchemaProps(): array
    {
        $copyable = $this->isCopyable();

        return [
            'fontFamily' => $this->getFontFamily(),
            'tooltip' => $this->getTooltip(),
            'copyable' => $copyable ?: null,
            'copyMessage' => $copyable ? $this->evaluate($this->copyMessage) : null,
            'copyMessageDuration' => $copyable ? $this->evaluate($this->copyMessageDuration) : null,
            'content' => (string) $this->evaluate($this->content),
            'color' => $this->getColor(),
            'icon' => $this->getIcon(),
            'size' => $this->evaluate($this->size),
            'weight' => $this->evaluate($this->weight),
            'badge' => $this->evaluate($this->isBadge) ?: null,
        ];
    }
}
