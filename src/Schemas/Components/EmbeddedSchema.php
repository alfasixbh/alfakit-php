<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Schemas\Components;

/**
 * Marks where a resource page's own form or infolist goes inside `content()`, so other
 * components can sit around it (Filament's `EmbeddedSchema`):
 *
 * ```php
 * public function content(Schema $schema): Schema
 * {
 *     return $schema->components([
 *         Callout::make()->warning()->heading(__('This order is locked')),
 *         $this->getFormContentComponent(),          // EmbeddedSchema::make('form')
 *         $this->getRelationManagersContentComponent(),
 *     ]);
 * }
 * ```
 *
 * Only top-level markers are recognised; the page splits its content around them.
 */
class EmbeddedSchema extends Component
{
    final public function __construct(protected string $name = 'form') {}

    public static function make(mixed ...$arguments): static
    {
        return parent::make(...$arguments);
    }

    /** `form` or `infolist`. */
    public function getName(): string
    {
        return $this->name;
    }

    public function getType(): string
    {
        return 'EmbeddedSchema';
    }
}
