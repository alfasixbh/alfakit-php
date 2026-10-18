<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Actions\Contracts;

use AlfaSix\Alfakit\Actions\Action;

/**
 * A schema component that needs to do something when the action whose modal form it sits
 * in is mounted (its modal opens), e.g. `MultiFactorChallenge` emailing a code.
 */
interface MountsWithAction
{
    public function mountedWithAction(Action $action): void;
}
