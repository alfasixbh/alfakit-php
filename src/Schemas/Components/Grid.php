<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Schemas\Components;

use Closure;

class Grid extends Component
{
    use Concerns\CanDeferLoading;

    /**
     * @param  array<string, int|null>|int|Closure|null  $columns
     */
    public function __construct(array|int|Closure|null $columns = 2)
    {
        $this->columns($columns);
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
     * @return array<string, int|null>
     */
    protected function getDefaultColumns(): array
    {
        return ['default' => 1, 'lg' => 2];
    }
}
