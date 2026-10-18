<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tables\Concerns;

use AlfaSix\Alfakit\Tables\Columns\Column;
use BackedEnum;
use Closure;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * `spreadsheet()`: a grid editing mode toggled from the toolbar. Users move between cells
 * with the keyboard, type over values, paste ranges from Excel or Google Sheets and fill
 * down; the edited cells are validated with each column's rules and saved in one request.
 *
 * Editable cells: the inline-editable columns (`TextInputColumn`, `SelectColumn`,
 * `ToggleColumn`, `CheckboxColumn`, unless `editable(false)`) and text columns marked
 * `editable()`. Every record is checked against the `update` policy and resolved through
 * the table's query (tenant scope included).
 *
 * By default a batch is all or nothing: one invalid or refused cell saves none. With
 * `spreadsheet(atomic: false)` the valid cells are saved and the others reported.
 *
 * @mixin \AlfaSix\Alfakit\Tables\Table
 */
trait HasSpreadsheet
{
    /** Most cells one save may change. */
    public const MAX_SPREADSHEET_CHANGES = 1000;

    protected bool|Closure $isSpreadsheet = false;

    protected bool|Closure $isSpreadsheetAtomic = true;

    public function spreadsheet(bool|Closure $condition = true, bool|Closure $atomic = true): static
    {
        $this->isSpreadsheet = $condition;
        $this->isSpreadsheetAtomic = $atomic;

        return $this;
    }

    /**
     * Whether the grid mode is offered: on, without a content layout, with at least one
     * editable column.
     */
    public function isSpreadsheet(): bool
    {
        return (bool) $this->evaluate($this->isSpreadsheet) && ! $this->hasContentLayout() && $this->getSpreadsheetColumns() !== [];
    }

    public function isSpreadsheetAtomic(): bool
    {
        return (bool) $this->evaluate($this->isSpreadsheetAtomic);
    }

    /**
     * The visible columns whose cells the grid can edit.
     *
     * @return array<string, Column>
     */
    public function getSpreadsheetColumns(): array
    {
        if (! (bool) $this->evaluate($this->isSpreadsheet)) {
            return [];
        }

        return array_filter($this->getColumns(), static fn (Column $column): bool => self::isSpreadsheetEditable($column));
    }

    protected static function isSpreadsheetEditable(Column $column): bool
    {
        return ! $column->isHidden()
            && method_exists($column, 'updateState')
            && method_exists($column, 'validateState')
            && method_exists($column, 'isEditable')
            && $column->isEditable();
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function serializeSpreadsheet(): ?array
    {
        if (! $this->isSpreadsheet()) {
            return null;
        }

        return array_filter([
            'columns' => array_keys($this->applyColumnOrder($this->getSpreadsheetColumns())),
            'atomic' => $this->isSpreadsheetAtomic() ? null : false,
            'maxChanges' => self::MAX_SPREADSHEET_CHANGES,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * Validate (and, unless `$dryRun`, save) a batch of cell changes.
     *
     * Each change is `{record, column, state}`, optionally with `original`: the value the
     * client expects the cell to hold now (an undo sends what it saved), so a cell someone
     * changed since is reported as a conflict instead of being overwritten.
     *
     * @param  array<mixed>  $changes
     * @return array{saved: list<array{record: int|string, column: string, state: mixed, previous: mixed}>, errors: list<array{record: mixed, column: mixed, messages: list<string>}>}
     */
    public function saveSpreadsheetChanges(array $changes, bool $dryRun = false): array
    {
        abort_unless($this->isSpreadsheet(), 404);
        abort_if(count($changes) > self::MAX_SPREADSHEET_CHANGES, 422, __('alfakit::tables.spreadsheet.too_many_changes', ['max' => self::MAX_SPREADSHEET_CHANGES]));

        $columns = $this->getSpreadsheetColumns();
        $normalized = [];

        foreach ($changes as $change) {
            abort_unless(is_array($change) && is_scalar($change['record'] ?? null) && is_string($change['column'] ?? null), 422);

            $state = $change['state'] ?? null;
            abort_unless($state === null || is_scalar($state), 422);

            $normalized[(string) $change['record']."\0".$change['column']] = [
                'record' => $change['record'],
                'column' => $change['column'],
                'state' => $state,
                'hasOriginal' => array_key_exists('original', $change),
                'original' => $change['original'] ?? null,
            ];
        }

        $records = $this->getSpreadsheetRecords(array_values(array_unique(array_map(static fn (array $change): string => (string) $change['record'], $normalized))));
        $authorized = [];
        $errors = [];
        $valid = [];

        foreach ($normalized as $change) {
            $key = (string) $change['record'];
            $column = $columns[$change['column']] ?? null;
            $record = $records[$key] ?? null;

            if ($column === null) {
                $message = __('alfakit::tables.spreadsheet.not_editable');
            } elseif ($record === null) {
                $message = __('alfakit::tables.spreadsheet.record_missing');
            } elseif (! ($authorized[$key] ??= $this->authorizes('update', $record))) {
                $message = __('alfakit::tables.spreadsheet.forbidden');
            } elseif ($this->isSpreadsheetCellDisabled($column, $record)) {
                $message = __('alfakit::tables.spreadsheet.not_editable');
            } elseif ($change['hasOriginal'] && ! self::spreadsheetValuesMatch($this->getSpreadsheetRawState($column, $record), $change['original'])) {
                $message = __('alfakit::tables.spreadsheet.conflict');
            } else {
                $message = null;
            }

            if ($column === null || $record === null || $message !== null) {
                $errors[] = ['record' => $change['record'], 'column' => $change['column'], 'messages' => [(string) $message]];

                continue;
            }

            try {
                if (method_exists($column, 'validateState')) {
                    $column->validateState($record, $change['state']);
                }

                $valid[] = [$change, $column, $record];
            } catch (ValidationException $exception) {
                $errors[] = ['record' => $change['record'], 'column' => $change['column'], 'messages' => array_values(array_map(strval(...), $exception->validator->errors()->all()))];
            } finally {
                $column->record(null);
            }
        }

        if ($dryRun || ($errors !== [] && $this->isSpreadsheetAtomic())) {
            return ['saved' => [], 'errors' => $errors];
        }

        $saved = [];
        $save = function () use ($valid, &$saved, &$errors): void {
            foreach ($valid as [$change, $column, $record]) {
                $previous = $this->getSpreadsheetRawState($column, $record);

                try {
                    $state = method_exists($column, 'updateState') ? $column->updateState($record, $change['state']) : null;
                } catch (ValidationException $exception) {
                    // Rules that depend on other cells of the batch (`unique`, …) may fail now.
                    $errors[] = ['record' => $change['record'], 'column' => $change['column'], 'messages' => array_values(array_map(strval(...), $exception->validator->errors()->all()))];

                    if ($this->isSpreadsheetAtomic()) {
                        throw $exception;
                    }

                    continue;
                } catch (HttpExceptionInterface $exception) {
                    $errors[] = ['record' => $change['record'], 'column' => $change['column'], 'messages' => [__('alfakit::tables.spreadsheet.not_editable')]];

                    if ($this->isSpreadsheetAtomic()) {
                        throw $exception;
                    }

                    continue;
                } finally {
                    $column->record(null);
                }

                $saved[] = ['record' => $change['record'], 'column' => $change['column'], 'state' => self::spreadsheetScalar($state), 'previous' => $previous];
            }
        };

        try {
            $this->hasCustomRecords() ? $save() : DB::transaction($save);
        } catch (ValidationException|HttpExceptionInterface) {
            // Atomic batches roll back as a whole; the failing cell is in `$errors`.
            return ['saved' => [], 'errors' => $errors];
        }

        return ['saved' => $saved, 'errors' => $errors];
    }

    /**
     * Live (not trashed) records of the table by key, through its base query.
     *
     * @param  array<string>  $keys
     * @return array<string, Model|array<mixed>>
     */
    protected function getSpreadsheetRecords(array $keys): array
    {
        if ($keys === []) {
            return [];
        }

        if ($this->hasCustomRecords()) {
            $records = $this->getCustomRecordsByKeys($keys);
        } else {
            $query = $this->getQuery();

            if ($query === null) {
                return [];
            }

            $records = $this->applyFiltersToBaseQuery($query)->whereKey($keys)->get();
        }

        $byKey = [];

        /** @var Collection<array-key, mixed> $records */
        foreach ($records as $index => $record) {
            if ($record instanceof Model) {
                if (in_array(SoftDeletes::class, class_uses_recursive($record), true) && method_exists($record, 'trashed') && $record->trashed()) {
                    continue;
                }

                $byKey[(string) $record->getKey()] = $record;
            } elseif (is_array($record)) {
                // Custom `records()` rows are keyed by their record key.
                $byKey[(string) $index] = $record;
            }
        }

        return $byKey;
    }

    /**
     * `disabled()` (per record) cells stay read-only.
     *
     * @param  Model|array<mixed>  $record
     */
    protected function isSpreadsheetCellDisabled(Column $column, Model|array $record): bool
    {
        if (! method_exists($column, 'isDisabled')) {
            return false;
        }

        $column->record($record);

        try {
            return (bool) $column->isDisabled();
        } finally {
            $column->record(null);
        }
    }

    /**
     * @param  Model|array<mixed>  $record
     */
    protected function getSpreadsheetRawState(Column $column, Model|array $record): mixed
    {
        try {
            $column->record($record);

            return self::spreadsheetScalar($column->getState());
        } catch (Throwable) {
            return null;
        } finally {
            $column->record(null);
        }
    }

    protected static function spreadsheetScalar(mixed $value): mixed
    {
        return match (true) {
            $value instanceof BackedEnum => $value->value,
            $value instanceof DateTimeInterface => $value->format('Y-m-d H:i:s'),
            is_scalar($value) || $value === null => $value,
            default => null,
        };
    }

    /**
     * Loose comparison of a cell value: booleans as 1 / 0, numbers numerically, empty
     * strings as null.
     */
    protected static function spreadsheetValuesMatch(mixed $current, mixed $expected): bool
    {
        $normalize = static function (mixed $value): ?string {
            $value = self::spreadsheetScalar($value);

            return match (true) {
                $value === null, $value === '' => null,
                is_bool($value) => $value ? '1' : '0',
                is_numeric($value) => (string) ($value + 0),
                default => (string) $value,
            };
        };

        return $normalize($current) === $normalize($expected);
    }
}
