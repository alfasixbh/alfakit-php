<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Schemas\Components\Utilities;

use AlfaSix\Alfakit\Schemas\Components\Component;

/**
 * Writes another field's state, resolving relative paths like `../slug`.
 */
final class Set
{
    public function __construct(
        protected Component $component,
    ) {}

    public function __invoke(string $path, mixed $state, bool $isAbsolute = false, bool $shouldCallUpdatedHooks = false): mixed
    {
        $statePath = (new Get($this->component))->resolvePath($path, $isAbsolute);
        $root = $this->component->getContainer()->getRootSchema();
        $old = $root->getStateAt($statePath);

        $root->setStateAt($statePath, $state);
        $root->markAsDirty($statePath);

        if ($shouldCallUpdatedHooks) {
            $root->callAfterStateUpdated($statePath, $old);
        }

        return $state;
    }
}
