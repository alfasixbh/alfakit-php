<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Support\Enums;

enum Operation: string
{
    case Create = 'create';
    case Edit = 'edit';
    case View = 'view';

    /**
     * Operation names (or other strings such as page classes) from strings, cases or a
     * list of both, for `hiddenOn()` / `visibleOn()` / `disabledOn()`.
     *
     * @param  string|Operation|array<string|Operation>  $operations
     * @return array<string>
     */
    public static function normalize(string|Operation|array $operations): array
    {
        return array_values(array_map(
            static fn (string|Operation $operation): string => $operation instanceof Operation ? $operation->value : $operation,
            is_array($operations) ? $operations : [$operations],
        ));
    }
}
