<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Support\Concerns;

use Closure;

/**
 * Lets applications register global defaults for a class.
 *
 * ```php
 * TextInput::configureUsing(fn (TextInput $input) => $input->maxLength(255));
 * ```
 *
 * Every `make()` call runs `configure()`, which applies the registered callbacks
 * for the class and all of its parents.
 */
trait Configurable
{
    /** @var array<class-string, array<Closure>> */
    protected static array $configurations = [];

    public static function configureUsing(Closure $callback, ?Closure $during = null, bool $isImportant = false): mixed
    {
        static::$configurations[static::class] ??= [];

        if ($isImportant) {
            array_unshift(static::$configurations[static::class], $callback);
        } else {
            static::$configurations[static::class][] = $callback;
        }

        if (! $during) {
            return null;
        }

        try {
            return $during();
        } finally {
            static::$configurations[static::class] = array_filter(
                static::$configurations[static::class],
                static fn (Closure $configuration): bool => $configuration !== $callback,
            );
        }
    }

    public function configure(): static
    {
        foreach (static::$configurations as $classToConfigure => $configurationCallbacks) {
            if (! $this instanceof $classToConfigure) {
                continue;
            }

            foreach ($configurationCallbacks as $configure) {
                $configure($this);
            }
        }

        $this->setUp();

        return $this;
    }

    protected function setUp(): void {}

    public static function clearConfigurations(): void
    {
        static::$configurations = [];
    }
}
