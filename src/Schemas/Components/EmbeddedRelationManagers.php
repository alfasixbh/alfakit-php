<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Schemas\Components;

/**
 * Marks where an Edit or View page's relation managers go inside `content()`
 * (`$this->getRelationManagersContentComponent()`). Only a top-level marker is recognised.
 */
class EmbeddedRelationManagers extends Component
{
    public function getType(): string
    {
        return 'EmbeddedRelationManagers';
    }
}
