<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Schemas\Components;

use Closure;

/**
 * Embed a custom frontend component registered in the renderer's component registry.
 *
 * ```php
 * View::make('MapPicker')->props(['zoom' => 12])
 * ```
 */
class View extends Component
{
    /** @var array<string, mixed>|Closure */
    protected array|Closure $props = [];

    public function __construct(string $component)
    {
        $this->type('View');
        $this->meta(['component' => $component]);
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
     * @param  array<string, mixed>|Closure  $props
     */
    public function props(array|Closure $props): static
    {
        $this->props = $props;

        return $this;
    }

    protected function getSchemaProps(): array
    {
        return [
            'component' => $this->getMeta()['component'] ?? null,
            'props' => $this->evaluate($this->props) ?: null,
        ];
    }
}
