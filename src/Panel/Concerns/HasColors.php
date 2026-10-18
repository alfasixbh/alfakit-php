<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Panel\Concerns;

use AlfaSix\Alfakit\Support\Colors\Colors;
use Closure;

/**
 * The panel's semantic colours (`Panel::colors()`).
 */
trait HasColors
{
    /** @var array<int, array<string, string|array<int, string>>|Closure> */
    protected array $colors = [];

    /**
     * Semantic colours as any CSS colour (`#2563eb`, `oklch(…)`) or an 11-shade palette
     * (`Color::Indigo`, `Color::hex('#…')`). Keys: primary, secondary, success, warning,
     * danger, info, gray, or any custom name. Calls add up, later names winning.
     *
     * A closure picks colours per request (once per request, with `$user`, `$tenant`,
     * `$page`, `$request` and `$panel` injectable), like Filament's `colors(fn () => …)`:
     *
     * ```php
     * $panel->colors(fn (?Team $tenant) => ['primary' => $tenant?->brand_color ?? Color::Indigo]);
     * ```
     *
     * @param  array<string, string|array<int, string>>|Closure  $colors
     */
    public function colors(array|Closure $colors): static
    {
        $this->colors[] = $colors;

        return $this->flushThemeCache();
    }

    /**
     * The colours set with `Colors::register()` and `colors()`, closures resolved.
     *
     * @return array<string, string|array<int, string>>
     */
    public function getColors(): array
    {
        /** @var array<string, string|array<int, string>> */
        return $this->rememberThemeValue('colors', function (): array {
            $colors = Colors::all(fn (Closure $closure): mixed => $this->evaluate($closure));

            foreach ($this->colors as $entry) {
                $entry = $entry instanceof Closure ? $this->evaluate($entry) : $entry;

                if (is_array($entry)) {
                    $colors = [...$colors, ...$entry];
                }
            }

            return $colors;
        });
    }
}
