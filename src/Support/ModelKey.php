<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * Values from a URL, a posted form or an imported file, compared with a model's columns.
 */
class ModelKey
{
    /**
     * Whether `$value` can be compared with `$column`. An integer column (the model's
     * integer key, or an `int` cast) only takes whole numbers: PostgreSQL refuses
     * `id = 'abc'` with an error, and MySQL reads `'12abc'` as 12 and finds the wrong record.
     */
    public static function isComparable(Model $model, string $column, mixed $value): bool
    {
        $integer = ($column === $model->getKeyName() && in_array($model->getKeyType(), ['int', 'integer'], true))
            || in_array($model->getCasts()[$column] ?? null, ['int', 'integer'], true);

        if (! $integer) {
            return is_scalar($value);
        }

        return is_int($value) || (is_string($value) && preg_match('/^-?\d{1,18}$/', $value) === 1);
    }
}
