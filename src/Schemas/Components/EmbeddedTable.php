<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Schemas\Components;

/**
 * Marks where a list page's table goes inside `content()`, so other components can sit
 * above and below it:
 *
 * ```php
 * public function content(Schema $schema): Schema
 * {
 *     return $schema->components([
 *         Callout::make()->info()->heading(__('Prices include VAT')),
 *         EmbeddedTable::make(),
 *         Text::make(__('Totals refresh every five minutes.')),
 *     ]);
 * }
 * ```
 *
 * Only a top-level EmbeddedTable is recognised; the page splits its content around it.
 */
class EmbeddedTable extends Component
{
    public function getType(): string
    {
        return 'EmbeddedTable';
    }
}
