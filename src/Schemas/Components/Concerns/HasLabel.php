<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Schemas\Components\Concerns;

use AlfaSix\Alfakit\Support\Labels;
use Closure;

trait HasLabel
{
    protected string|Closure|null $label = null;

    protected bool|Closure $isLabelHidden = false;

    protected bool $shouldTranslateLabel = false;

    public function label(string|Closure|null $label): static
    {
        $this->label = $label;

        return $this;
    }

    public function translateLabel(bool $shouldTranslateLabel = true): static
    {
        $this->shouldTranslateLabel = $shouldTranslateLabel;

        return $this;
    }

    public function hiddenLabel(bool|Closure $condition = true): static
    {
        $this->isLabelHidden = $condition;

        return $this;
    }

    public function getLabel(): ?string
    {
        $label = $this->evaluate($this->label) ?? $this->getDefaultLabel();

        if ($label === null) {
            return null;
        }

        return $this->shouldTranslateLabel ? __($label) : $label;
    }

    protected function getDefaultLabel(): ?string
    {
        if (! method_exists($this, 'getName')) {
            return null;
        }

        $name = $this->getName();

        if ($name === null) {
            return null;
        }

        // Infolist entries read records (dots are relationships); fields nest form state.
        return Labels::fromName($name, isRelationshipPath: $this instanceof \AlfaSix\Alfakit\Infolists\Components\Entry);
    }

    public function isLabelHidden(): bool
    {
        return (bool) $this->evaluate($this->isLabelHidden);
    }
}
