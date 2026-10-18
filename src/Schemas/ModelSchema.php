<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Schemas;

use AlfaSix\Alfakit\Forms\Components\ColorPicker;
use AlfaSix\Alfakit\Forms\Components\DatePicker;
use AlfaSix\Alfakit\Forms\Components\DateTimePicker;
use AlfaSix\Alfakit\Forms\Components\Field;
use AlfaSix\Alfakit\Forms\Components\FileUpload;
use AlfaSix\Alfakit\Forms\Components\KeyValue;
use AlfaSix\Alfakit\Forms\Components\Select;
use AlfaSix\Alfakit\Forms\Components\Textarea;
use AlfaSix\Alfakit\Forms\Components\TextInput;
use AlfaSix\Alfakit\Forms\Components\TimePicker;
use AlfaSix\Alfakit\Forms\Components\Toggle;
use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Schema as DbSchema;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Infers form fields for a model from its casts and table columns, the same rules the
 * resource generator (`make:alfakit-resource --generate`) uses to write code, applied at
 * runtime: `Schema::fromModel(Post::class)` returns real components you can tweak.
 *
 * - `*_id` columns become a `Select` for the matching relationship, when the model has it.
 * - Casts win over column types: `boolean` → Toggle, `integer`/`decimal` → numeric input,
 *   `date` → DatePicker, `datetime` → DateTimePicker, `array`/`json`/`collection` → KeyValue,
 *   a backed enum → Select with its cases.
 * - Column types then decide: text → Textarea, json → KeyValue, date/time types → pickers,
 *   integers and decimals → numeric inputs; names containing `email`, `image`/`avatar`/
 *   `photo` or `color` get an email input, an image upload or a colour picker.
 * - Non-nullable columns without a default are `required()`; string columns get
 *   `maxLength(255)`.
 * - The primary key, timestamps, soft-delete and auth bookkeeping columns are skipped, and
 *   `$fillable` (when set) limits the fields to what the model accepts.
 */
final class ModelSchema
{
    /** @var array<int, string> */
    public const SKIPPED = ['created_at', 'updated_at', 'deleted_at', 'remember_token', 'email_verified_at', 'password', 'two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at'];

    /**
     * @param  class-string<Model>|Model  $model
     * @param  array<int, string>  $except  Attributes to leave out
     * @param  array<int, string>|null  $only  Attributes to include (in this order), or null for every column
     * @return array<Field>
     */
    public static function fields(string|Model $model, array $except = [], ?array $only = null): array
    {
        $instance = $model instanceof Model ? $model : self::instance($model);
        $fields = [];

        foreach (self::attributes($instance, $except, $only) as $column) {
            $fields[] = self::field($instance, $column);
        }

        return $fields;
    }

    /**
     * The columns a form for this model edits.
     *
     * @param  array<int, string>  $except
     * @param  array<int, string>|null  $only
     * @return array<int, array{name: string, type: string, nullable: bool, default: mixed}>
     */
    public static function attributes(Model $model, array $except = [], ?array $only = null): array
    {
        $skip = [...self::SKIPPED, $model->getKeyName(), ...$except];
        $fillable = $model->getFillable();
        $columns = [];

        foreach (self::columns($model) as $column) {
            $name = $column['name'];

            if (in_array($name, $skip, true) || ($only !== null && ! in_array($name, $only, true)) || ($only === null && $fillable !== [] && ! in_array($name, $fillable, true))) {
                continue;
            }

            $columns[$name] = $column;
        }

        if ($only !== null) {
            // Keep the caller's order; unknown attributes still get a text input.
            return array_values(array_map(static fn (string $name): array => $columns[$name] ?? ['name' => $name, 'type' => 'string', 'nullable' => true, 'default' => null], array_values(array_diff($only, $skip))));
        }

        return array_values($columns);
    }

    /**
     * @param  array{name: string, type: string, nullable: bool, default: mixed}  $column
     */
    public static function field(Model $model, array $column): Field
    {
        $name = $column['name'];
        $type = Str::lower($column['type']);
        $required = ! $column['nullable'] && $column['default'] === null;
        $rawCast = $model->getCasts()[$name] ?? null;
        $rawCast = is_string($rawCast) ? $rawCast : null;
        // `decimal:2` → `decimal`; cast classes (`AsArrayObject`) keep their base name.
        $cast = $rawCast === null ? null : Str::lower(class_basename(Str::before($rawCast, ':')));

        if (Str::endsWith($name, '_id')) {
            $relationship = Str::camel(Str::beforeLast($name, '_id'));

            if (method_exists($model, $relationship) && $model->{$relationship}() instanceof BelongsTo) {
                return self::finish(Select::make($name)->relationship($relationship, 'name')->searchable()->preload(), $required);
            }
        }

        if ($rawCast !== null && enum_exists($rawCast) && is_subclass_of($rawCast, BackedEnum::class)) {
            $options = [];

            foreach ($rawCast::cases() as $case) {
                $options[(string) $case->value] = Str::headline((string) $case->name);
            }

            return self::finish(Select::make($name)->options($options), $required);
        }

        $field = match (true) {
            in_array($cast, ['boolean', 'bool'], true), in_array($type, ['boolean', 'bool'], true), $type === 'tinyint' && (str_starts_with($name, 'is_') || str_starts_with($name, 'has_')) => Toggle::make($name),
            in_array($cast, ['array', 'json', 'collection', 'object', 'asarrayobject', 'ascollection', 'asencryptedarrayobject', 'asencryptedcollection'], true), in_array($type, ['json', 'jsonb'], true) => KeyValue::make($name)->columnSpanFull(),
            in_array($cast, ['date', 'immutable_date'], true), $type === 'date' => DatePicker::make($name),
            in_array($cast, ['datetime', 'immutable_datetime', 'timestamp'], true), in_array($type, ['datetime', 'timestamp', 'timestamptz', 'datetimetz'], true) => DateTimePicker::make($name),
            $type === 'time' => TimePicker::make($name),
            in_array($cast, ['int', 'integer'], true), in_array($type, ['integer', 'int', 'bigint', 'smallint', 'int4', 'int8', 'int2', 'mediumint', 'tinyint'], true) => TextInput::make($name)->integer(),
            in_array($cast, ['real', 'float', 'double', 'decimal'], true), in_array($type, ['decimal', 'numeric', 'float', 'double', 'real', 'float8', 'float4', 'money'], true) => TextInput::make($name)->numeric(),
            in_array($type, ['text', 'longtext', 'mediumtext', 'tinytext'], true) => Textarea::make($name)->columnSpanFull(),
            str_contains($name, 'email') => TextInput::make($name)->email()->maxLength(255),
            str_contains($name, 'image') || str_contains($name, 'avatar') || str_contains($name, 'photo') => FileUpload::make($name)->image(),
            str_contains($name, 'color') || str_contains($name, 'colour') => ColorPicker::make($name),
            default => TextInput::make($name)->maxLength(255),
        };

        return self::finish($field, $required);
    }

    private static function finish(Field $field, bool $required): Field
    {
        return $required ? $field->required() : $field;
    }

    /**
     * @return array<int, array{name: string, type: string, nullable: bool, default: mixed}>
     */
    private static function columns(Model $model): array
    {
        try {
            $columns = DbSchema::connection($model->getConnectionName())->getColumns($model->getTable());
        } catch (Throwable) {
            return [];
        }

        return array_map(static fn (array $column): array => [
            'name' => (string) $column['name'],
            'type' => Str::lower((string) $column['type_name']),
            'nullable' => (bool) $column['nullable'],
            'default' => $column['default'] ?? null,
        ], $columns);
    }

    /**
     * @param  class-string<Model>  $model
     */
    private static function instance(string $model): Model
    {
        if (! class_exists($model) || ! is_subclass_of($model, Model::class)) {
            throw new InvalidArgumentException("[{$model}] is not an Eloquent model.");
        }

        return new $model;
    }
}
