<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Support\Concerns;

use AlfaSix\Alfakit\Support\EnumState;
use Closure;
use Illuminate\Support\Collection;
use UnitEnum;

/**
 * Display components (text and icon columns and entries) read the label, colour and icon of
 * an enum state from the `Support\Contracts` interfaces. The state is an enum when the model
 * casts the attribute; when the raw value is stored (a JSON path, an uncast column, an array
 * row) name the enum with `->enum(Status::class)` so the value is turned back into its case.
 */
trait HasEnumState
{
    /** @var class-string<UnitEnum>|Closure|null */
    protected string|Closure|null $enum = null;

    /**
     * @param  class-string<UnitEnum>|Closure|null  $enum
     */
    public function enum(string|Closure|null $enum): static
    {
        $this->enum = $enum;

        return $this;
    }

    /**
     * @return class-string<UnitEnum>|null
     */
    public function getEnum(): ?string
    {
        $enum = $this->evaluate($this->enum);

        return is_string($enum) && enum_exists($enum) ? $enum : null;
    }

    /**
     * The enum case a single state stands for, if any.
     */
    public function resolveEnumCase(mixed $state): ?UnitEnum
    {
        if (! $state instanceof UnitEnum && $this->enum === null) {
            return null;
        }

        return EnumState::resolve($state, $this->getEnum());
    }

    /**
     * Read one property (colour, icon) from the enum state. A single state gives one value;
     * a list gives one value when every item agrees, and the per-item values otherwise.
     *
     * @param  Closure(UnitEnum): ?string  $read
     * @return array{0: ?string, 1: array<int, ?string>|null}
     */
    protected function resolveEnumStateProperty(mixed $state, Closure $read): array
    {
        if ($state instanceof Collection) {
            $state = $state->all();
        }

        if (! is_array($state)) {
            $case = $this->resolveEnumCase($state);

            return [$case === null ? null : $read($case), null];
        }

        $items = array_values(array_filter($state, static fn (mixed $item): bool => $item !== null && $item !== ''));
        $values = array_map(function (mixed $item) use ($read): ?string {
            $case = $this->resolveEnumCase($item);

            return $case === null ? null : $read($case);
        }, $items);

        $distinct = array_values(array_unique(array_map(static fn (?string $value): string => (string) $value, $values)));

        if ($values === [] || $distinct === ['']) {
            return [null, null];
        }

        return count($distinct) === 1 ? [$values[0], null] : [null, $values];
    }

    /**
     * @return array{0: ?string, 1: array<int, ?string>|null}
     */
    protected function getEnumColor(mixed $state): array
    {
        return $this->resolveEnumStateProperty($state, EnumState::color(...));
    }

    /**
     * @return array{0: ?string, 1: array<int, ?string>|null}
     */
    protected function getEnumIcon(mixed $state): array
    {
        return $this->resolveEnumStateProperty($state, EnumState::icon(...));
    }
}
