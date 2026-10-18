<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema as DbSchema;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Writes the PHP code of form fields and table columns for a model, read from its
 * table's columns (`Schema::getColumns()`): what generators with `--generate` put in the
 * classes they create (`make:alfakit-form`, `make:alfakit-table`). The runtime equivalent
 * for forms is `Schema::fromModel()` (`ModelSchema`).
 *
 * - `*_id` columns become a relationship `Select` (and a `relationship.name` column).
 * - Booleans → `Toggle` / boolean `IconColumn`; text → `Textarea`; json → `KeyValue`;
 *   date/time types → pickers and `date()` / `dateTime()` columns; integers and decimals
 *   → numeric inputs and `numeric()` columns; names with `email`, `image`/`avatar`/`photo`
 *   or `color` → email input, image upload / `ImageColumn`, colour picker / `ColorColumn`.
 * - Non-nullable columns without a default are `required()`.
 * - The primary key, timestamps, soft-delete and auth bookkeeping columns are skipped;
 *   tables get a hidden-by-default `created_at` column when the table has one.
 */
final class SchemaGenerator
{
    /** @var array<int, string> */
    public const SKIPPED = ['created_at', 'updated_at', 'deleted_at', 'remember_token', 'email_verified_at', 'password', 'two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at'];

    private const FORMS = 'AlfaSix\\Alfakit\\Forms\\Components\\';

    private const COLUMNS = 'AlfaSix\\Alfakit\\Tables\\Columns\\';

    /**
     * The form components for `$model`, one per line group, indented by `$indent` spaces.
     *
     * @param  class-string<Model>|Model  $model
     * @return array{code: string, imports: list<string>}
     */
    public static function formComponents(string|Model $model, int $indent = 16): array
    {
        $lines = [];
        $imports = [];

        foreach (self::columns($model) as $column) {
            [$code, $import] = self::field($column);
            $lines[] = self::indent($code, $indent);
            $imports[] = $import;
        }

        return ['code' => implode("\n", $lines), 'imports' => self::sortImports($imports)];
    }

    /**
     * The table columns for `$model`, indented by `$indent` spaces.
     *
     * @param  class-string<Model>|Model  $model
     * @return array{code: string, imports: list<string>}
     */
    public static function tableColumns(string|Model $model, int $indent = 16): array
    {
        $lines = [];
        $imports = [];

        foreach (self::columns($model) as $column) {
            $generated = self::column($column);

            if ($generated === null) {
                continue;
            }

            $lines[] = self::indent($generated[0], $indent);
            $imports[] = $generated[1];
        }

        if (in_array('created_at', array_column(self::columns($model, skip: false), 'name'), true)) {
            $lines[] = self::indent("TextColumn::make('created_at')\n    ->dateTime()\n    ->sortable()\n    ->toggleable(isToggledHiddenByDefault: true),", $indent);
            $imports[] = self::COLUMNS.'TextColumn';
        }

        return ['code' => implode("\n", $lines), 'imports' => self::sortImports($imports)];
    }

    /**
     * The model table's columns that become fields, as `{name, type, nullable, default}`.
     *
     * @param  class-string<Model>|Model  $model
     * @return list<array{name: string, type: string, nullable: bool, default: mixed}>
     */
    public static function columns(string|Model $model, bool $skip = true): array
    {
        if (is_string($model) && ! is_subclass_of($model, Model::class)) {
            throw new InvalidArgumentException("{$model} is not an Eloquent model.");
        }

        $instance = $model instanceof Model ? $model : new $model;
        $skipped = [$instance->getKeyName(), ...self::SKIPPED];
        $columns = [];

        foreach (DbSchema::connection($instance->getConnectionName())->getColumns($instance->getTable()) as $column) {
            $name = (string) $column['name'];

            if ($skip && in_array($name, $skipped, true)) {
                continue;
            }

            $columns[] = [
                'name' => $name,
                'type' => Str::lower((string) $column['type_name']),
                'nullable' => (bool) $column['nullable'],
                'default' => $column['default'] ?? null,
            ];
        }

        return $columns;
    }

    /**
     * @param  array{name: string, type: string, nullable: bool, default: mixed}  $column
     * @return array{0: string, 1: string} Code and the class to import
     */
    public static function field(array $column): array
    {
        $name = $column['name'];
        $type = $column['type'];
        $required = ! $column['nullable'] && $column['default'] === null ? "\n    ->required()" : '';

        if (Str::endsWith($name, '_id')) {
            $relationship = Str::camel(Str::beforeLast($name, '_id'));

            return ["Select::make('{$name}')\n    ->relationship('{$relationship}', 'name')\n    ->searchable()\n    ->preload(){$required},", self::FORMS.'Select'];
        }

        return match (true) {
            self::isBoolean($name, $type) => ["Toggle::make('{$name}'),", self::FORMS.'Toggle'],
            in_array($type, ['text', 'longtext', 'mediumtext'], true) => ["Textarea::make('{$name}')\n    ->columnSpanFull(){$required},", self::FORMS.'Textarea'],
            in_array($type, ['json', 'jsonb'], true) => ["KeyValue::make('{$name}')\n    ->columnSpanFull(){$required},", self::FORMS.'KeyValue'],
            $type === 'date' => ["DatePicker::make('{$name}'){$required},", self::FORMS.'DatePicker'],
            in_array($type, ['datetime', 'timestamp', 'timestamptz', 'datetimetz'], true) => ["DateTimePicker::make('{$name}'){$required},", self::FORMS.'DateTimePicker'],
            $type === 'time' => ["TimePicker::make('{$name}'){$required},", self::FORMS.'TimePicker'],
            self::isInteger($type) => ["TextInput::make('{$name}')\n    ->integer(){$required},", self::FORMS.'TextInput'],
            self::isDecimal($type) => ["TextInput::make('{$name}')\n    ->numeric(){$required},", self::FORMS.'TextInput'],
            str_contains($name, 'email') => ["TextInput::make('{$name}')\n    ->email()\n    ->maxLength(255){$required},", self::FORMS.'TextInput'],
            self::isImage($name) => ["FileUpload::make('{$name}')\n    ->image(){$required},", self::FORMS.'FileUpload'],
            str_contains($name, 'color') => ["ColorPicker::make('{$name}'){$required},", self::FORMS.'ColorPicker'],
            default => ["TextInput::make('{$name}')\n    ->maxLength(255){$required},", self::FORMS.'TextInput'],
        };
    }

    /**
     * @param  array{name: string, type: string, nullable: bool, default: mixed}  $column
     * @return array{0: string, 1: string}|null Code and the class to import; null for long text and json
     */
    public static function column(array $column): ?array
    {
        $name = $column['name'];
        $type = $column['type'];

        if (Str::endsWith($name, '_id')) {
            $relationship = Str::camel(Str::beforeLast($name, '_id'));

            return ["TextColumn::make('{$relationship}.name')\n    ->sortable(),", self::COLUMNS.'TextColumn'];
        }

        return match (true) {
            self::isBoolean($name, $type) => ["IconColumn::make('{$name}')\n    ->boolean(),", self::COLUMNS.'IconColumn'],
            in_array($type, ['text', 'longtext', 'mediumtext', 'json', 'jsonb'], true) => null,
            $type === 'date' => ["TextColumn::make('{$name}')\n    ->date()\n    ->sortable(),", self::COLUMNS.'TextColumn'],
            in_array($type, ['datetime', 'timestamp', 'timestamptz', 'datetimetz'], true) => ["TextColumn::make('{$name}')\n    ->dateTime()\n    ->sortable(),", self::COLUMNS.'TextColumn'],
            self::isInteger($type), self::isDecimal($type) => ["TextColumn::make('{$name}')\n    ->numeric()\n    ->sortable(),", self::COLUMNS.'TextColumn'],
            self::isImage($name) => ["ImageColumn::make('{$name}'),", self::COLUMNS.'ImageColumn'],
            str_contains($name, 'color') => ["ColorColumn::make('{$name}'),", self::COLUMNS.'ColorColumn'],
            default => ["TextColumn::make('{$name}')\n    ->searchable(),", self::COLUMNS.'TextColumn'],
        };
    }

    private static function isBoolean(string $name, string $type): bool
    {
        return in_array($type, ['boolean', 'bool'], true)
            || ($type === 'tinyint' && (str_starts_with($name, 'is_') || str_starts_with($name, 'has_')));
    }

    private static function isInteger(string $type): bool
    {
        return in_array($type, ['integer', 'int', 'bigint', 'smallint', 'int4', 'int8', 'int2', 'mediumint', 'tinyint'], true);
    }

    private static function isDecimal(string $type): bool
    {
        return in_array($type, ['decimal', 'numeric', 'float', 'double', 'real', 'float8', 'float4', 'money'], true);
    }

    private static function isImage(string $name): bool
    {
        return str_contains($name, 'image') || str_contains($name, 'avatar') || str_contains($name, 'photo');
    }

    private static function indent(string $code, int $spaces): string
    {
        $prefix = str_repeat(' ', $spaces);

        return implode("\n", array_map(static fn (string $line): string => $prefix.$line, explode("\n", $code)));
    }

    /**
     * @param  array<int, string>  $imports
     * @return list<string>
     */
    private static function sortImports(array $imports): array
    {
        $imports = array_values(array_unique($imports));
        sort($imports);

        return $imports;
    }
}
