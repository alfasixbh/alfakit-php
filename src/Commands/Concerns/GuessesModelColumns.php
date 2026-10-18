<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Commands\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema as DbSchema;
use Illuminate\Support\Str;
use Throwable;

/**
 * Reads a model's table to guess importer and exporter columns.
 */
trait GuessesModelColumns
{
    /**
     * Resolve `Post`, `App\Models\Post` or `Models/Post` to a class name and its base name.
     *
     * @return array{0: string, 1: string}
     */
    protected function resolveModelClass(string $model): array
    {
        $model = str_replace('/', '\\', trim($model));
        $class = Str::contains($model, '\\') ? ltrim($model, '\\') : "App\\Models\\{$model}";

        return [$class, class_basename($class)];
    }

    /**
     * The model table's columns, or null when the model or table can't be read.
     *
     * @return array<int, array{name: string, type: string, nullable: bool, default: mixed}>|null
     */
    protected function getModelTableColumns(string $modelClass): ?array
    {
        if (! class_exists($modelClass) || ! is_subclass_of($modelClass, Model::class)) {
            $this->components->warn("Model {$modelClass} not found; add the columns yourself.");

            return null;
        }

        try {
            $model = new $modelClass;

            return array_map(static fn (array $column): array => [
                'name' => (string) $column['name'],
                'type' => Str::lower((string) $column['type_name']),
                'nullable' => (bool) $column['nullable'],
                'default' => $column['default'] ?? null,
            ], DbSchema::connection($model->getConnectionName())->getColumns($model->getTable()));
        } catch (Throwable $exception) {
            $this->components->warn("Could not read the table of {$modelClass} ({$exception->getMessage()}); add the columns yourself.");

            return null;
        }
    }

    protected static function isBooleanColumn(string $name, string $type): bool
    {
        return in_array($type, ['boolean', 'bool'], true)
            || ($type === 'tinyint' && (str_starts_with($name, 'is_') || str_starts_with($name, 'has_')));
    }

    protected static function isIntegerColumn(string $type): bool
    {
        return in_array($type, ['integer', 'int', 'bigint', 'smallint', 'int4', 'int8', 'int2', 'mediumint', 'tinyint'], true);
    }

    protected static function isDecimalColumn(string $type): bool
    {
        return in_array($type, ['decimal', 'numeric', 'float', 'double', 'real', 'float8', 'float4', 'money'], true);
    }

    protected static function isDateColumn(string $type): bool
    {
        return in_array($type, ['date', 'datetime', 'timestamp', 'timestamptz', 'datetimetz'], true);
    }
}
