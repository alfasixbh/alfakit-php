<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Forms\Components\Repeater;

use AlfaSix\Alfakit\Support\Concerns\EvaluatesClosures;
use AlfaSix\Alfakit\Support\Enums\Alignment;
use AlfaSix\Alfakit\Support\Serializable;
use Closure;

/**
 * A column of a Repeater (or RepeatableEntry) shown as a table: `->table([...])`. Columns
 * map to the item's components in order: the first column holds the first component.
 */
class TableColumn
{
    use EvaluatesClosures;

    protected bool|Closure $isHeaderLabelHidden = false;

    protected bool|Closure|null $isMarkedAsRequired = null;

    protected string|Closure|null $width = null;

    protected Alignment|string|Closure|null $alignment = null;

    protected bool|Closure $shouldWrapHeader = false;

    final public function __construct(protected string|Closure $label) {}

    public static function make(string|Closure $label): static
    {
        return new static($label);
    }

    /**
     * Keep the header text for screen readers only.
     */
    public function hiddenHeaderLabel(bool|Closure $condition = true): static
    {
        $this->isHeaderLabelHidden = $condition;

        return $this;
    }

    /**
     * Show the required asterisk. Defaults to whether the column's field is required.
     */
    public function markAsRequired(bool|Closure $condition = true): static
    {
        $this->isMarkedAsRequired = $condition;

        return $this;
    }

    /**
     * A CSS width (`200px`, `30%`).
     */
    public function width(string|Closure|null $width): static
    {
        $this->width = $width;

        return $this;
    }

    public function alignment(Alignment|string|Closure|null $alignment): static
    {
        $this->alignment = $alignment;

        return $this;
    }

    public function wrapHeader(bool|Closure $condition = true): static
    {
        $this->shouldWrapHeader = $condition;

        return $this;
    }

    public function getLabel(): string
    {
        return (string) $this->evaluate($this->label);
    }

    public function isMarkedAsRequired(): ?bool
    {
        $required = $this->evaluate($this->isMarkedAsRequired);

        return $required === null ? null : (bool) $required;
    }

    /**
     * `start`, `center` or `end` (logical, so tables mirror in right-to-left locales).
     */
    public function getAlignment(): ?string
    {
        $alignment = $this->evaluate($this->alignment);
        $value = $alignment instanceof Alignment ? $alignment->value : $alignment;

        return match ($value) {
            'start', 'left' => 'start',
            'center' => 'center',
            'end', 'right' => 'end',
            default => null,
        };
    }

    /**
     * @param  string|null  $name  The relative state path of the component the column shows
     * @return array<string, mixed>
     */
    public function toSchema(?string $name = null, bool $isRequired = false): array
    {
        return Serializable::compact([
            'label' => $this->getLabel(),
            'name' => $name,
            'hiddenLabel' => $this->evaluate($this->isHeaderLabelHidden) ? true : null,
            'required' => ($this->isMarkedAsRequired() ?? $isRequired) ? true : null,
            'width' => $this->evaluate($this->width),
            'alignment' => $this->getAlignment(),
            'wrapHeader' => $this->evaluate($this->shouldWrapHeader) ? true : null,
        ]);
    }
}
