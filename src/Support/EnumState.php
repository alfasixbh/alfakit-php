<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Support;

use AlfaSix\Alfakit\Support\Contracts\HasColor;
use AlfaSix\Alfakit\Support\Contracts\HasDescription;
use AlfaSix\Alfakit\Support\Contracts\HasIcon;
use AlfaSix\Alfakit\Support\Contracts\HasLabel;
use AlfaSix\Alfakit\Support\Enums\Color;
use BackedEnum;
use Illuminate\Contracts\Support\Htmlable;
use TypeError;
use UnitEnum;

/**
 * Reads labels, colours, icons and descriptions from enums implementing the
 * `Support\Contracts` interfaces (or exposing the same methods), and turns stored values
 * back into enum cases.
 */
final class EnumState
{
    /**
     * The case for a state: the state itself when it is an enum case, or the case of
     * `$enum` whose value (backed enums) or name (pure enums) is the state.
     *
     * @param  class-string<UnitEnum>|null  $enum
     */
    public static function resolve(mixed $state, ?string $enum = null): ?UnitEnum
    {
        if ($state instanceof UnitEnum) {
            return $enum === null || $state instanceof $enum ? $state : null;
        }

        if ($enum === null || ! enum_exists($enum) || ! (is_string($state) || is_int($state))) {
            return null;
        }

        if (is_subclass_of($enum, BackedEnum::class)) {
            try {
                return $enum::tryFrom($state);
            } catch (TypeError) {
                // An int-backed enum given a non-numeric string, or the other way round.
                return is_numeric($state) ? $enum::tryFrom((int) $state) : null;
            }
        }

        foreach ($enum::cases() as $case) {
            if ($case->name === (string) $state) {
                return $case;
            }
        }

        return null;
    }

    /**
     * The value options and stored state use for a case: its backing value, or its name.
     */
    public static function key(UnitEnum $case): string|int
    {
        return $case instanceof BackedEnum ? $case->value : $case->name;
    }

    public static function label(UnitEnum $case): string
    {
        $label = $case instanceof HasLabel || method_exists($case, 'getLabel') ? $case->getLabel() : null;

        if ($label instanceof Htmlable) {
            $label = $label->toHtml();
        }

        return is_string($label) && $label !== '' ? $label : str($case->name)->headline()->toString();
    }

    public static function color(UnitEnum $case): ?string
    {
        $color = $case instanceof HasColor || method_exists($case, 'getColor') ? $case->getColor() : null;

        return match (true) {
            $color instanceof Color => $color->value,
            is_string($color) && $color !== '' => $color,
            default => null,
        };
    }

    public static function icon(UnitEnum $case): ?string
    {
        $icon = $case instanceof HasIcon || method_exists($case, 'getIcon') ? $case->getIcon() : null;

        if ($icon instanceof BackedEnum) {
            $icon = (string) $icon->value;
        }

        return is_string($icon) && $icon !== '' ? Icons::resolve($icon) : null;
    }

    public static function description(UnitEnum $case): ?string
    {
        $description = $case instanceof HasDescription || method_exists($case, 'getDescription') ? $case->getDescription() : null;

        if ($description instanceof Htmlable) {
            $description = $description->toHtml();
        }

        return is_string($description) && $description !== '' ? $description : null;
    }

    /**
     * `value => label` options for every case of an enum.
     *
     * @param  class-string<UnitEnum>  $enum
     * @return array<string|int, string>
     */
    public static function options(string $enum): array
    {
        $options = [];

        foreach ($enum::cases() as $case) {
            $options[self::key($case)] = self::label($case);
        }

        return $options;
    }
}
