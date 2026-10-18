<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Commands\Concerns;

use AlfaSix\Alfakit\Support\Deprecations;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Commands that kept their old `alfakit:make-*` names as aliases of `make:alfakit-*`
 * report a deprecation when they are invoked through the alias.
 */
trait WarnsAboutDeprecatedAliases
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $invoked = $input->getFirstArgument();
        $name = (string) $this->getName();

        if (is_string($invoked) && $invoked !== $name && in_array($invoked, $this->getAliases(), true)) {
            Deprecations::warn("artisan {$invoked}", "use php artisan {$name} instead", since: '0.1');
            $this->components->warn("The {$invoked} command name is deprecated; use php artisan {$name} instead.");
        }

        return parent::execute($input, $output);
    }
}
