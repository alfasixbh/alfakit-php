<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Commands\Support;

use Illuminate\Support\Str;

/**
 * Turns `ModelInspector` columns into the code `alfakit:generate` writes: form fields,
 * table columns, filters and infolist entries, with the smart defaults of each column
 * kind (string → TextInput, text → Textarea / RichEditor, boolean → Toggle / IconColumn /
 * TernaryFilter, enum → Select / badge, date → DatePicker, foreign key → searchable
 * relationship Select, json → KeyValue…).
 *
 * @phpstan-import-type ColumnInfo from ModelInspector
 */
final class ResourceCodeBuilder
{
    private const NS = 'AlfaSix\\Alfakit\\';

    /** @var array<string, true> */
    private array $imports = [];

    /**
     * @param  list<ColumnInfo>  $columns
     */
    public function __construct(private readonly array $columns) {}

    /**
     * Columns a generated form edits by default.
     *
     * @return list<string>
     */
    public function defaultFormColumns(): array
    {
        return $this->names(static fn (array $column): bool => ! in_array($column['kind'], ['key', 'password'], true) && ! in_array($column['name'], ModelInspector::SKIPPED, true));
    }

    /**
     * Columns a generated table lists by default: everything short, and the timestamps.
     *
     * @return list<string>
     */
    public function defaultTableColumns(): array
    {
        return $this->names(static fn (array $column): bool => ! in_array($column['kind'], ['key', 'password', 'text', 'richtext', 'json'], true)
            && ! in_array($column['name'], ['remember_token', 'deleted_at', 'email_verified_at'], true));
    }

    /**
     * @return list<string>
     */
    public function defaultFilterColumns(): array
    {
        return $this->names(static fn (array $column): bool => in_array($column['kind'], ['boolean', 'relation', 'enum'], true));
    }

    /**
     * @return list<string>
     */
    public function defaultInfolistColumns(): array
    {
        return $this->names(static fn (array $column): bool => ! in_array($column['kind'], ['key', 'password'], true) && ! in_array($column['name'], ['remember_token', 'deleted_at'], true));
    }

    /**
     * Every column that can be offered, keyed by name with its kind (`title — string`).
     *
     * @return array<string, string>
     */
    public function options(bool $filtersOnly = false): array
    {
        $options = [];

        foreach ($this->columns as $column) {
            if ($column['kind'] === 'key' || ($filtersOnly && ! in_array($column['kind'], ['boolean', 'relation', 'enum', 'date', 'datetime'], true))) {
                continue;
            }

            $options[$column['name']] = $column['name'].' — '.($column['kind'] === 'relation' ? "{$column['relationship']} (relation)" : ($column['enum'] !== null ? class_basename($column['enum']).' (enum)' : $column['kind']));
        }

        return $options;
    }

    /**
     * @param  list<string>  $names
     */
    public function formFields(array $names): string
    {
        return $this->build($names, $this->formField(...));
    }

    /**
     * @param  list<string>  $names
     */
    public function tableColumns(array $names): string
    {
        return $this->build($names, $this->tableColumn(...));
    }

    /**
     * The filters, each on its own line after a newline (the stub's `->filters([{{ filters }}`).
     *
     * @param  list<string>  $names
     */
    public function filters(array $names): string
    {
        $code = $this->build($names, $this->filter(...));

        return $code === '' ? '' : "\n".$code;
    }

    /**
     * The `infolist()` method, or an empty string when no entry is chosen.
     *
     * @param  list<string>  $names
     */
    public function infolist(array $names): string
    {
        $entries = $this->build($names, $this->entry(...));

        if ($entries === '') {
            return '';
        }

        return "\n\n    public static function infolist(Schema \$schema): Schema\n    {\n        return \$schema\n            ->components([\n{$entries}\n            ]);\n    }";
    }

    /**
     * Fully qualified classes the generated code uses.
     *
     * @return list<string>
     */
    public function imports(): array
    {
        return array_keys($this->imports);
    }

    /**
     * @param  list<string>  $names
     * @param  callable(ColumnInfo): ?string  $render
     */
    private function build(array $names, callable $render): string
    {
        $lines = [];

        foreach ($names as $name) {
            $column = $this->column($name);

            if ($column !== null && ($code = $render($column)) !== null) {
                $lines[] = '                '.$code.',';
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @param  ColumnInfo  $column
     */
    private function formField(array $column): string
    {
        $name = $column['name'];
        $required = $column['nullable'] ? '' : "\n                    ->required()";
        $unique = $column['unique'] ? "\n                    ->unique(ignoreRecord: true)" : '';
        $max = "\n                    ->maxLength(".($column['length'] ?? 255).')';

        return match ($column['kind']) {
            'relation' => $this->use('Forms\\Components\\Select')."::make('{$name}')\n                    ->relationship('{$column['relationship']}', '{$column['titleAttribute']}')\n                    ->searchable()\n                    ->preload(){$required}",
            'enum' => $this->use('Forms\\Components\\Select')."::make('{$name}')\n                    ->options(".$this->useClass((string) $column['enum'])."::class){$required}",
            'boolean' => $this->use('Forms\\Components\\Toggle')."::make('{$name}')",
            'richtext' => $this->use('Forms\\Components\\RichEditor')."::make('{$name}')\n                    ->columnSpanFull(){$required}",
            'text' => $this->use('Forms\\Components\\Textarea')."::make('{$name}')\n                    ->columnSpanFull(){$required}",
            'json' => $this->use('Forms\\Components\\KeyValue')."::make('{$name}')\n                    ->columnSpanFull()",
            'date' => $this->use('Forms\\Components\\DatePicker')."::make('{$name}'){$required}",
            'datetime' => $this->use('Forms\\Components\\DateTimePicker')."::make('{$name}'){$required}",
            'time' => $this->use('Forms\\Components\\TimePicker')."::make('{$name}'){$required}",
            'integer' => $this->use('Forms\\Components\\TextInput')."::make('{$name}')\n                    ->integer(){$required}{$unique}",
            'decimal' => $this->use('Forms\\Components\\TextInput')."::make('{$name}')\n                    ->numeric(){$required}",
            'email' => $this->use('Forms\\Components\\TextInput')."::make('{$name}')\n                    ->email(){$max}{$required}{$unique}",
            'password' => $this->use('Forms\\Components\\TextInput')."::make('{$name}')\n                    ->password()\n                    ->revealable(){$required}",
            'image' => $this->use('Forms\\Components\\FileUpload')."::make('{$name}')\n                    ->image(){$required}",
            'color' => $this->use('Forms\\Components\\ColorPicker')."::make('{$name}'){$required}",
            'url' => $this->use('Forms\\Components\\TextInput')."::make('{$name}')\n                    ->url(){$max}{$required}",
            default => $this->use('Forms\\Components\\TextInput')."::make('{$name}'){$max}{$required}{$unique}",
        };
    }

    /**
     * @param  ColumnInfo  $column
     */
    private function tableColumn(array $column): string
    {
        $name = $column['name'];
        $text = $this->use('Tables\\Columns\\TextColumn');

        return match ($column['kind']) {
            'relation' => "{$text}::make('{$column['relationship']}.{$column['titleAttribute']}')\n                    ->searchable()\n                    ->sortable()",
            'enum' => "{$text}::make('{$name}')\n                    ->badge()\n                    ->sortable()",
            'boolean' => $this->use('Tables\\Columns\\IconColumn')."::make('{$name}')\n                    ->boolean()",
            'text', 'richtext' => "{$text}::make('{$name}')\n                    ->limit(50)",
            'json', 'password' => "{$text}::make('{$name}')\n                    ->toggleable(isToggledHiddenByDefault: true)",
            'date' => "{$text}::make('{$name}')\n                    ->date()\n                    ->sortable()",
            'datetime' => "{$text}::make('{$name}')\n                    ->dateTime()\n                    ->sortable()".(in_array($name, ['created_at', 'updated_at'], true) ? "\n                    ->toggleable(isToggledHiddenByDefault: true)" : ''),
            'integer', 'decimal' => "{$text}::make('{$name}')\n                    ->numeric()\n                    ->sortable()",
            'image' => $this->use('Tables\\Columns\\ImageColumn')."::make('{$name}')",
            'color' => $this->use('Tables\\Columns\\ColorColumn')."::make('{$name}')",
            default => "{$text}::make('{$name}')\n                    ->searchable()".(in_array($name, ['name', 'title', 'email', 'slug'], true) ? "\n                    ->sortable()" : ''),
        };
    }

    /**
     * @param  ColumnInfo  $column
     */
    private function filter(array $column): ?string
    {
        $name = $column['name'];

        return match ($column['kind']) {
            'relation' => $this->use('Tables\\Filters\\SelectFilter')."::make('{$name}')\n                    ->relationship('{$column['relationship']}', '{$column['titleAttribute']}')\n                    ->searchable()\n                    ->preload()",
            'enum' => $this->use('Tables\\Filters\\SelectFilter')."::make('{$name}')\n                    ->options(".$this->useClass((string) $column['enum']).'::class)',
            'boolean' => $this->use('Tables\\Filters\\TernaryFilter')."::make('{$name}')",
            'date', 'datetime' => $this->dateRangeFilter($name),
            default => null,
        };
    }

    /** A from / until date range filter on a date or datetime column. */
    private function dateRangeFilter(string $name): string
    {
        $filter = $this->use('Tables\\Filters\\Filter');
        $picker = $this->use('Forms\\Components\\DatePicker');
        $builder = $this->useClass('Illuminate\\Database\\Eloquent\\Builder');
        $indent = '                    ';

        return "{$filter}::make('{$name}')\n"
            ."{$indent}->schema([\n"
            ."{$indent}    {$picker}::make('from'),\n"
            ."{$indent}    {$picker}::make('until'),\n"
            ."{$indent}])\n"
            ."{$indent}->query(fn ({$builder} \$query, array \$data): {$builder} => \$query\n"
            ."{$indent}    ->when(\$data['from'] ?? null, fn ({$builder} \$query, string \$date): {$builder} => \$query->whereDate('{$name}', '>=', \$date))\n"
            ."{$indent}    ->when(\$data['until'] ?? null, fn ({$builder} \$query, string \$date): {$builder} => \$query->whereDate('{$name}', '<=', \$date)))";
    }

    /**
     * @param  ColumnInfo  $column
     */
    private function entry(array $column): ?string
    {
        $name = $column['name'];
        $text = $this->use('Infolists\\Components\\TextEntry');

        return match ($column['kind']) {
            'password' => null,
            'relation' => "{$text}::make('{$column['relationship']}.{$column['titleAttribute']}')",
            'enum' => "{$text}::make('{$name}')\n                    ->badge()",
            'boolean' => $this->use('Infolists\\Components\\IconEntry')."::make('{$name}')\n                    ->boolean()",
            'richtext' => "{$text}::make('{$name}')\n                    ->html()\n                    ->columnSpanFull()",
            'text' => "{$text}::make('{$name}')\n                    ->columnSpanFull()",
            'json' => $this->use('Infolists\\Components\\KeyValueEntry')."::make('{$name}')\n                    ->columnSpanFull()",
            'date' => "{$text}::make('{$name}')\n                    ->date()",
            'datetime' => "{$text}::make('{$name}')\n                    ->dateTime()",
            'integer', 'decimal' => "{$text}::make('{$name}')\n                    ->numeric()",
            'image' => $this->use('Infolists\\Components\\ImageEntry')."::make('{$name}')",
            'color' => $this->use('Infolists\\Components\\ColorEntry')."::make('{$name}')",
            default => "{$text}::make('{$name}')",
        };
    }

    /**
     * @param  callable(ColumnInfo): bool  $filter
     * @return list<string>
     */
    private function names(callable $filter): array
    {
        return array_values(array_map(static fn (array $column): string => $column['name'], array_filter($this->columns, $filter)));
    }

    /**
     * @return ColumnInfo|null
     */
    private function column(string $name): ?array
    {
        foreach ($this->columns as $column) {
            if ($column['name'] === $name) {
                return $column;
            }
        }

        return null;
    }

    /** Import an AlfaKit class and return its short name. */
    private function use(string $class): string
    {
        return $this->useClass(self::NS.$class);
    }

    private function useClass(string $class): string
    {
        $this->imports[ltrim($class, '\\')] = true;

        return Str::afterLast($class, '\\');
    }
}
