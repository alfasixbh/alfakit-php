<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Schemas\Components;

use AlfaSix\Alfakit\Support\Enums\Size;
use Closure;

/**
 * A bulleted list: every child component (or plain string, which becomes a `Text`) is one
 * list item.
 *
 * ```php
 * UnorderedList::make(['Unlimited projects', Text::make('Priority support')->weight('semibold')])
 * ```
 */
class UnorderedList extends Component
{
    protected Size|string|Closure|null $size = null;

    /**
     * @param  array<Component|string>|Closure  $schema
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
    }

    /**
     * @param  array<Component|string>|Closure  $components
     */
    public function schema(array|Closure $components): static
    {
        return parent::schema(is_array($components) ? self::wrapStrings($components) : fn (): array => self::wrapStrings($this->evaluate($components) ?? []));
    }

    /**
     * `xs`, `sm` (default), `md` or `lg`.
     */
    public function size(Size|string|Closure|null $size): static
    {
        $this->size = $size;

        return $this;
    }

    /**
     * @param  array<Component|string>  $components
     * @return array<Component>
     */
    protected static function wrapStrings(array $components): array
    {
        return array_map(static fn (Component|string $item): Component => is_string($item) ? Text::make($item) : $item, array_values($components));
    }

    protected function getSchemaProps(): array
    {
        $size = $this->evaluate($this->size);

        return [
            'columns' => null,
            'size' => $size instanceof Size ? $size->value : $size,
        ];
    }
}
