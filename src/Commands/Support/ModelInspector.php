<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Commands\Support;

use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use Symfony\Component\Finder\Finder;
use Throwable;

/**
 * What `alfakit:generate` knows about a model: its table's columns (type, length,
 * nullability, unique indexes, foreign keys, literal defaults), enum casts, Eloquent relationships (public
 * methods declaring a `Relation` return type) and whether it soft deletes. Every column
 * gets a `kind` that decides the generated form field, table column, filter and entry.
 *
 * @phpstan-type ColumnInfo array{name: string, type: string, kind: string, nullable: bool, length: int|null, unique: bool, enum: class-string|null, relationship: string|null, related: class-string<Model>|null, titleAttribute: string|null, default?: bool|int|float|string|null}
 * @phpstan-type RelationInfo array{name: string, type: string, related: class-string<Model>, titleAttribute: string}
 */
final class ModelInspector
{
    /** Columns no generated form edits. */
    public const SKIPPED = ['created_at', 'updated_at', 'deleted_at', 'remember_token', 'email_verified_at'];

    /** Title attributes tried, in order, for relationship selects and relation managers. */
    public const TITLE_ATTRIBUTES = ['name', 'title', 'label', 'full_name', 'email', 'subject', 'slug', 'code'];

    /**
     * The given attributes the model refuses to mass assign (not in `$fillable`, or guarded).
     * A resource saves its form with `create()` / `update()`, so these would fail on save.
     *
     * @param  list<string>  $attributes
     * @return list<string>
     */
    public static function guardedAttributes(string $modelClass, array $attributes): array
    {
        if (! class_exists($modelClass) || ! is_subclass_of($modelClass, Model::class)) {
            return [];
        }

        $model = new $modelClass;

        return array_values(array_filter($attributes, static fn (string $attribute): bool => ! $model->isFillable($attribute)));
    }

    /**
     * Whether the model class uses Laravel's SoftDeletes trait; null when the class does not
     * exist (yet).
     */
    public static function softDeletes(string $modelClass): ?bool
    {
        if (! class_exists($modelClass)) {
            return null;
        }

        return in_array(SoftDeletes::class, class_uses_recursive($modelClass), true);
    }

    /**
     * Eloquent models of the application (`app/Models`, else `app/`).
     *
     * @return list<class-string<Model>>
     */
    public static function models(?string $directory = null, string $namespace = 'App\\Models'): array
    {
        $directory ??= is_dir(app_path('Models')) ? app_path('Models') : app_path();
        $namespace = $directory === app_path() ? 'App' : rtrim($namespace, '\\');

        if (! is_dir($directory)) {
            return [];
        }

        $models = [];

        foreach ((new Finder)->files()->in($directory)->name('*.php') as $file) {
            $class = $namespace.'\\'.str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname());

            try {
                if (class_exists($class) && is_subclass_of($class, Model::class) && ! (new ReflectionClass($class))->isAbstract()) {
                    $models[] = $class;
                }
            } catch (Throwable) {
                // A file that fails to load is not offered.
            }
        }

        sort($models);

        return $models;
    }

    /**
     * @param  class-string<Model>  $modelClass
     * @return array{table: string, key: string, softDeletes: bool, columns: list<ColumnInfo>, relations: list<RelationInfo>}
     */
    public static function inspect(string $modelClass): array
    {
        $model = new $modelClass;
        $schema = Schema::connection($model->getConnectionName());
        $table = $model->getTable();

        $unique = [];
        $foreign = [];

        foreach ($schema->getIndexes($table) as $index) {
            if ($index['unique'] && ! $index['primary'] && count($index['columns']) === 1) {
                $unique[$index['columns'][0]] = true;
            }
        }

        foreach ($schema->getForeignKeys($table) as $key) {
            if (count($key['columns']) === 1) {
                $foreign[$key['columns'][0]] = (string) $key['foreign_table'];
            }
        }

        $relations = self::relations($model);
        $belongsTo = [];

        foreach ($relations as $relation) {
            if ($relation['type'] === 'BelongsTo' && $relation['foreignKey'] !== null) {
                $belongsTo[$relation['foreignKey']] = $relation;
            }
        }

        $casts = $model->getCasts();
        $columns = [];

        foreach ($schema->getColumns($table) as $column) {
            $name = (string) $column['name'];
            $type = Str::lower((string) $column['type_name']);
            $cast = isset($casts[$name]) ? (string) $casts[$name] : null;
            $enum = $cast !== null && enum_exists(Str::before($cast, ':')) && is_subclass_of(Str::before($cast, ':'), BackedEnum::class) ? Str::before($cast, ':') : null;
            $relation = $belongsTo[$name] ?? null;

            if ($relation === null && (isset($foreign[$name]) || Str::endsWith($name, '_id'))) {
                // A foreign key without a BelongsTo method: guess the relationship name.
                $guess = Str::camel(Str::beforeLast($name, '_id'));

                foreach ($relations as $candidate) {
                    if ($candidate['name'] === $guess && $candidate['type'] === 'BelongsTo') {
                        $relation = $candidate;
                    }
                }
            }

            $kind = $name === $model->getKeyName() ? 'key' : self::kind($name, $type, $cast, $enum, $relation !== null);

            $columns[] = [
                'name' => $name,
                'type' => $type,
                'kind' => $kind,
                'nullable' => (bool) $column['nullable'],
                'length' => preg_match('/\((\d+)\)/', (string) $column['type'], $match) === 1 ? (int) $match[1] : null,
                'unique' => isset($unique[$name]),
                'enum' => $enum,
                'relationship' => $relation['name'] ?? null,
                'related' => $relation['related'] ?? null,
                'titleAttribute' => $relation['titleAttribute'] ?? null,
                'default' => self::defaultValue($column['default'] ?? null, $kind),
            ];
        }

        return [
            'table' => $table,
            'key' => $model->getKeyName(),
            'softDeletes' => in_array(SoftDeletes::class, class_uses_recursive($model), true),
            'columns' => $columns,
            'relations' => array_values(array_map(static fn (array $relation): array => [
                'name' => $relation['name'],
                'type' => $relation['type'],
                'related' => $relation['related'],
                'titleAttribute' => $relation['titleAttribute'],
            ], $relations)),
        ];
    }

    /**
     * The kind of a column: `relation`, `enum`, `boolean`, `richtext`, `text`, `json`,
     * `date`, `datetime`, `time`, `integer`, `decimal`, `email`, `password`, `image`,
     * `color`, `url` or `string`.
     */
    public static function kind(string $name, string $type, ?string $cast = null, ?string $enum = null, bool $relation = false): string
    {
        $cast = $cast === null ? null : Str::lower(Str::before($cast, ':'));

        return match (true) {
            $relation => 'relation',
            $enum !== null => 'enum',
            in_array($type, ['boolean', 'bool'], true) || $cast === 'boolean' || $cast === 'bool' || ($type === 'tinyint' && (str_starts_with($name, 'is_') || str_starts_with($name, 'has_'))) => 'boolean',
            in_array($type, ['json', 'jsonb'], true) || in_array($cast, ['array', 'json', 'object', 'collection', 'asarrayobject', 'ascollection'], true) => 'json',
            in_array($type, ['text', 'longtext', 'mediumtext', 'tinytext'], true) => preg_match('/(^|_)(body|content|description_html|html)$/', $name) === 1 ? 'richtext' : 'text',
            $type === 'date' || $cast === 'date' || $cast === 'immutable_date' => 'date',
            in_array($type, ['datetime', 'timestamp', 'timestamptz', 'datetimetz'], true) || in_array($cast, ['datetime', 'immutable_datetime', 'timestamp'], true) => 'datetime',
            in_array($type, ['time', 'timetz'], true) => 'time',
            in_array($type, ['integer', 'int', 'bigint', 'smallint', 'int4', 'int8', 'int2', 'mediumint', 'tinyint'], true) => 'integer',
            in_array($type, ['decimal', 'numeric', 'float', 'double', 'real', 'float8', 'float4', 'money'], true) => 'decimal',
            $name === 'password' || $cast === 'hashed' => 'password',
            str_contains($name, 'email') => 'email',
            preg_match('/(image|avatar|photo|logo|thumbnail)/', $name) === 1 => 'image',
            str_contains($name, 'color') || str_contains($name, 'colour') => 'color',
            preg_match('/(^|_)(url|website|link)$/', $name) === 1 => 'url',
            default => 'string',
        };
    }

    /**
     * The literal default of a column, as the PHP value a generated field's `->default()`
     * gets: booleans, numbers and strings. Null for no default, a `NULL` default, an empty
     * string, a database expression (`CURRENT_TIMESTAMP`, `now()`, `nextval(...)`) and kinds
     * whose fields don't take a literal (keys, relations, dates, JSON, passwords, files).
     */
    public static function defaultValue(mixed $default, string $kind): bool|int|float|string|null
    {
        if ($default === null || in_array($kind, ['key', 'relation', 'json', 'date', 'datetime', 'time', 'password', 'image'], true)) {
            return null;
        }

        if (is_bool($default) || is_int($default) || is_float($default)) {
            $value = $default;
        } else {
            $raw = trim((string) $default);

            // PostgreSQL casts (`'draft'::character varying`) and wrapping parentheses (SQLite, SQL Server).
            $raw = (string) preg_replace('/::[\w\s\[\]"]+$/', '', $raw);

            while (preg_match('/^\((.*)\)$/s', $raw, $match) === 1) {
                $raw = trim($match[1]);
            }

            if (preg_match("/^(?:N|_\w+\s*)?'(.*)'$/s", $raw, $match) === 1) {
                $value = str_replace("''", "'", $match[1]);
            } elseif ($raw === '' || strcasecmp($raw, 'null') === 0) {
                return null;
            } elseif (is_numeric($raw) || in_array(strtolower($raw), ['true', 'false'], true)) {
                $value = $raw;
            } elseif (preg_match('/[()]/', $raw) === 1 || preg_match('/^(current_(timestamp|date|time|user)|localtime(stamp)?|now|sysdate|uuid)$/i', $raw) === 1) {
                // An expression the database evaluates.
                return null;
            } else {
                // MySQL reports string defaults without quotes.
                $value = $raw;
            }
        }

        return match ($kind) {
            'boolean' => match (true) {
                is_bool($value) => $value,
                in_array(strtolower((string) $value), ['1', 'true', 't', 'yes', 'on', "b'1'"], true) => true,
                in_array(strtolower((string) $value), ['0', 'false', 'f', 'no', 'off', "b'0'"], true) => false,
                default => null,
            },
            'integer' => is_numeric($value) && (string) (int) $value === (string) $value ? (int) $value : null,
            'decimal' => is_numeric($value) ? (str_contains((string) $value, '.') || str_contains(strtolower((string) $value), 'e') ? (float) $value : (int) $value) : null,
            default => $value === '' || is_bool($value) ? null : (string) $value,
        };
    }

    /**
     * Relationships of a model: its own public methods without required parameters whose
     * declared return type is a `Relation`. Methods without a return type are not called.
     *
     * @return list<array{name: string, type: string, related: class-string<Model>, titleAttribute: string, foreignKey: string|null}>
     */
    public static function relations(Model $model): array
    {
        $relations = [];

        foreach ((new ReflectionClass($model))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $returnType = $method->getReturnType();

            if ($method->isStatic() || $method->getNumberOfRequiredParameters() > 0 || ! $returnType instanceof ReflectionNamedType || $returnType->isBuiltin()
                || ! is_a($returnType->getName(), Relation::class, true) || str_starts_with($method->getDeclaringClass()->getName(), 'Illuminate\\')) {
                continue;
            }

            try {
                $relation = $model->{$method->getName()}();
            } catch (Throwable) {
                continue;
            }

            if (! $relation instanceof Relation) {
                continue;
            }

            $related = $relation->getRelated();

            $relations[] = [
                'name' => $method->getName(),
                'type' => class_basename($relation),
                'related' => $related::class,
                'titleAttribute' => self::titleAttribute($related),
                'foreignKey' => method_exists($relation, 'getForeignKeyName') && class_basename($relation) === 'BelongsTo' ? (string) $relation->getForeignKeyName() : null,
            ];
        }

        usort($relations, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        return $relations;
    }

    /**
     * The column that names a record of `$model`: `name`, `title`, `email`… or its key.
     */
    public static function titleAttribute(Model $model): string
    {
        try {
            $columns = Schema::connection($model->getConnectionName())->getColumnListing($model->getTable());
        } catch (Throwable) {
            return $model->getKeyName();
        }

        foreach (self::TITLE_ATTRIBUTES as $attribute) {
            if (in_array($attribute, $columns, true)) {
                return $attribute;
            }
        }

        return $model->getKeyName();
    }
}
