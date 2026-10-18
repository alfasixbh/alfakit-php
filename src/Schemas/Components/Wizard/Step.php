<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Schemas\Components\Wizard;

use AlfaSix\Alfakit\Schemas\Components\Concerns\HasIcon;
use AlfaSix\Alfakit\Schemas\Components\Group;
use AlfaSix\Alfakit\Support\Icons;
use BackedEnum;
use Closure;

class Step extends Group
{
    use HasIcon;

    protected string|Closure|null $description = null;

    protected string|BackedEnum|\Illuminate\Contracts\Support\Htmlable|Closure|null $completedIcon = null;

    /** @var array<Closure> */
    protected array $afterValidation = [];

    /** @var array<Closure> */
    protected array $beforeValidation = [];

    public function __construct(string|Closure|null $label = null)
    {
        $this->label($label);
    }

    public static function make(mixed ...$arguments): static
    {
        return parent::make(...$arguments);
    }

    protected function setUp(): void
    {
        $this->columns(['default' => 1, 'lg' => 2]);
    }

    public function description(string|Closure|null $description): static
    {
        $this->description = $description;

        return $this;
    }

    /**
     * The icon shown once the user has completed this step (default: the wizard's
     * `completedIcon()`, else a check mark).
     */
    public function completedIcon(string|BackedEnum|\Illuminate\Contracts\Support\Htmlable|Closure|null $icon): static
    {
        $this->completedIcon = $icon;

        return $this;
    }

    public function getCompletedIcon(): ?string
    {
        return Icons::resolve($this->evaluate($this->completedIcon));
    }

    public function afterValidation(Closure $callback): static
    {
        $this->afterValidation[] = $callback;

        return $this;
    }

    public function beforeValidation(Closure $callback): static
    {
        $this->beforeValidation[] = $callback;

        return $this;
    }

    public function callAfterValidation(): void
    {
        foreach ($this->afterValidation as $callback) {
            $this->evaluate($callback);
        }
    }

    public function callBeforeValidation(): void
    {
        foreach ($this->beforeValidation as $callback) {
            $this->evaluate($callback);
        }
    }

    /**
     * Deferred by its own `deferLoading()` or by the wizard's.
     */
    public function isLoadingDeferred(): bool
    {
        if (parent::isLoadingDeferred()) {
            return true;
        }

        $parent = $this->hasContainer() ? $this->getContainer()->getParentComponent() : null;

        return $parent instanceof \AlfaSix\Alfakit\Schemas\Components\Wizard && $parent->isLoadingDeferred();
    }

    protected function getDefaultLabel(): ?string
    {
        return null;
    }

    protected function getSchemaProps(): array
    {
        return [
            'description' => $this->evaluate($this->description),
            'icon' => $this->getIcon(),
            'completedIcon' => $this->getCompletedIcon(),
        ];
    }
}
