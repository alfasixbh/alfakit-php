<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tables\Columns;

use AlfaSix\Alfakit\Tables\Columns\Concerns\CanUpdateState;

class CheckboxColumn extends Column
{
    use CanUpdateState;

    protected function mutateStateForUpdate(mixed $state): mixed
    {
        return (bool) $state;
    }

    protected function getFormattedState(): mixed
    {
        return (bool) $this->getState();
    }

    protected function getSchemaProps(): array
    {
        return ['editable' => true];
    }

    protected function getCellProps(): array
    {
        return ['disabled' => $this->isDisabled() ?: null];
    }
}
