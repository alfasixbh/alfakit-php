<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tables\Columns;

use AlfaSix\Alfakit\Schemas\Schema;
use AlfaSix\Alfakit\Support\Concerns\HasOptions;
use AlfaSix\Alfakit\Support\Labels;
use AlfaSix\Alfakit\Tables\Columns\Concerns\CanUpdateState;
use AlfaSix\Alfakit\Tables\Columns\Support\SelectColumnOptionsField;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * An inline select. Options are static (`options()`), from a relationship
 * (`optionsRelationship()`) or searched with `getOptionsSearchResultsUsing()`.
 * `native(false)` renders a combobox, searchable with `searchableOptions()`.
 */
class SelectColumn extends Column
{
    use CanUpdateState {
        updateState as protected persistState;
        validateState as protected validateColumnRules;
    }
    use HasOptions;

    /** State path of the column option fields inside the table's filters schema. */
    public const OPTIONS_STATE_PATH = '__columnOptions';

    protected bool|Closure $canSelectPlaceholder = true;

    protected string|Closure|null $placeholder = null;

    protected bool|Closure $isNative = true;

    /** @var bool|array<string>|Closure */
    protected bool|array|Closure $searchableOptions = false;

    protected int|Closure $optionsLimit = 50;

    protected bool|Closure $allowsOptionsHtml = false;

    protected bool|Closure $isOptionsPreloaded = false;

    protected string|Closure|null $optionsRelationshipName = null;

    protected string|Closure|null $optionsRelationshipTitleAttribute = null;

    protected bool|Closure $ignoresRecord = false;

    protected ?Closure $modifyOptionsQueryUsing = null;

    protected ?Closure $getOptionsSearchResultsUsing = null;

    protected ?Closure $getOptionLabelUsing = null;

    protected ?Closure $getOptionLabelFromRecordUsing = null;

    protected string|Closure|null $loadingMessage = null;

    protected string|Closure|null $noOptionsMessage = null;

    protected string|Closure|null $noSearchResultsMessage = null;

    protected string|Closure|null $searchPrompt = null;

    protected string|Closure|null $searchingMessage = null;

    protected int|Closure|null $optionsSearchDebounce = null;

    protected bool|Closure $shouldRememberOptions = true;

    protected bool|Closure $shouldWrapOptionLabels = true;

    /** @var array<string, string|null>|null Labels of the values on the current page (remote options). */
    protected ?array $pageOptionLabels = null;

    /** The page (records collection) the labels were looked up for. */
    protected ?int $pageOptionLabelsFor = null;

    public function selectablePlaceholder(bool|Closure $condition = true): static
    {
        $this->canSelectPlaceholder = $condition;

        return $this;
    }

    public function placeholder(string|Closure|null $placeholder): static
    {
        $this->placeholder = $placeholder;

        return $this;
    }

    /**
     * `false` renders a combobox instead of the browser's select (needed for searching and
     * HTML labels).
     */
    public function native(bool|Closure $condition = true): static
    {
        $this->isNative = $condition;

        return $this;
    }

    /**
     * Add a search input to the (non-native) select. For relationships, pass the related
     * columns to search (`['name', 'email']`); the title attribute is searched by default.
     *
     * @param  bool|array<string>|Closure  $condition
     */
    public function searchableOptions(bool|array|Closure $condition = true): static
    {
        $this->searchableOptions = $condition;

        return $this;
    }

    /**
     * The most options a searchable select lists (50 by default).
     */
    public function optionsLimit(int|Closure $limit): static
    {
        $this->optionsLimit = $limit;

        return $this;
    }

    /**
     * Render option labels as HTML in the combobox. Labels are sanitised with
     * `Support\Html::sanitize()`; a native select shows them as plain text.
     */
    public function allowOptionsHtml(bool|Closure $condition = true): static
    {
        $this->allowsOptionsHtml = $condition;

        return $this;
    }

    /**
     * Load searchable relationship options with the table instead of on search.
     */
    public function preloadOptions(bool|Closure $condition = true): static
    {
        $this->isOptionsPreloaded = $condition;

        return $this;
    }

    /**
     * Options from a `BelongsTo` relationship of the record: `optionsRelationship('author', 'name')`.
     * `ignoreRecord` leaves the record itself out (a `parent` relationship);
     * `modifyQueryUsing` receives `Builder $query` (and `?string $search`).
     */
    public function optionsRelationship(string|Closure $name, string|Closure|null $titleAttribute = null, bool|Closure $ignoreRecord = false, ?Closure $modifyQueryUsing = null): static
    {
        $this->optionsRelationshipName = $name;
        $this->optionsRelationshipTitleAttribute = $titleAttribute;
        $this->ignoresRecord = $ignoreRecord;
        $this->modifyOptionsQueryUsing = $modifyQueryUsing;

        return $this;
    }

    /**
     * Custom search: `fn (string $search): array` returning `value => label`. Pair with
     * `getOptionLabelUsing()` so the current value has a label before any search.
     */
    public function getOptionsSearchResultsUsing(?Closure $callback): static
    {
        $this->getOptionsSearchResultsUsing = $callback;

        return $this;
    }

    public function getOptionLabelUsing(?Closure $callback): static
    {
        $this->getOptionLabelUsing = $callback;

        return $this;
    }

    public function getOptionLabelFromRecordUsing(?Closure $callback): static
    {
        $this->getOptionLabelFromRecordUsing = $callback;

        return $this;
    }

    /**
     * Shown while the combobox loads its options (server-searched options).
     */
    public function loadingMessage(string|Closure|null $message): static
    {
        $this->loadingMessage = $message;

        return $this;
    }

    /**
     * Shown when the combobox has no options at all (before any search).
     */
    public function noOptionsMessage(string|Closure|null $message): static
    {
        $this->noOptionsMessage = $message;

        return $this;
    }

    /**
     * Shown when a search finds no options.
     */
    public function noSearchResultsMessage(string|Closure|null $message): static
    {
        $this->noSearchResultsMessage = $message;

        return $this;
    }

    /**
     * The search input's prompt (`searchableOptions()`).
     */
    public function searchPrompt(string|Closure|null $message): static
    {
        $this->searchPrompt = $message;

        return $this;
    }

    /**
     * Shown while search results load (server-searched options).
     */
    public function searchingMessage(string|Closure|null $message): static
    {
        $this->searchingMessage = $message;

        return $this;
    }

    /**
     * Milliseconds the combobox waits after typing before it searches server-side
     * options (default 300; Filament's default is 1000).
     */
    public function optionsSearchDebounce(int|Closure|null $milliseconds): static
    {
        $this->optionsSearchDebounce = $milliseconds;

        return $this;
    }

    /**
     * Accepted for Filament compatibility. AlfaKit always sends a column's options once
     * per table page (they are part of the column schema, not of each cell), so options
     * are effectively always remembered and `rememberOptions(false)` changes nothing.
     */
    public function rememberOptions(bool|Closure $condition = true): static
    {
        $this->shouldRememberOptions = $condition;

        return $this;
    }

    public function shouldRememberOptions(): bool
    {
        return (bool) $this->evaluate($this->shouldRememberOptions);
    }

    /**
     * Long option labels in the combobox (`native(false)`) wrap onto more lines (default);
     * `wrapOptionLabels(false)` truncates them instead.
     */
    public function wrapOptionLabels(bool|Closure $condition = true): static
    {
        $this->shouldWrapOptionLabels = $condition;

        return $this;
    }

    public function isNative(): bool
    {
        return (bool) $this->evaluate($this->isNative);
    }

    public function hasSearchableOptions(): bool
    {
        $searchable = $this->evaluate($this->searchableOptions);

        return ! $this->isNative() && ($searchable === true || (is_array($searchable) && $searchable !== []));
    }

    public function allowsOptionsHtml(): bool
    {
        return (bool) $this->evaluate($this->allowsOptionsHtml);
    }

    public function hasOptionsRelationship(): bool
    {
        return $this->optionsRelationshipName !== null;
    }

    public function ignoresRecord(): bool
    {
        return $this->hasOptionsRelationship() && (bool) $this->evaluate($this->ignoresRecord);
    }

    /**
     * Whether the options are searched on the server (a searchable relationship that is not
     * preloaded, or a custom search), rather than sent with the table.
     */
    public function hasRemoteOptions(): bool
    {
        if (! $this->hasSearchableOptions()) {
            return false;
        }

        return $this->getOptionsSearchResultsUsing !== null
            || ($this->hasOptionsRelationship() && ! $this->evaluate($this->isOptionsPreloaded));
    }

    public function getOptionsStatePath(): string
    {
        return self::OPTIONS_STATE_PATH.'.'.str_replace('.', '__', $this->getName());
    }

    /**
     * The select field that lists, searches, labels and validates the options. Add it to
     * a schema with the table's model before using it.
     */
    public function makeOptionsField(): SelectColumnOptionsField
    {
        $searchable = $this->evaluate($this->searchableOptions);

        $field = SelectColumnOptionsField::make(str_replace('.', '__', $this->getName()))
            ->allowsOptionsHtml($this->allowsOptionsHtml())
            ->searchable($this->hasSearchableOptions() ? (is_array($searchable) ? $searchable : true) : false)
            ->optionsLimit(fn (): int => max(1, (int) $this->evaluate($this->optionsLimit)))
            ->preload(fn (): bool => (bool) $this->evaluate($this->isOptionsPreloaded))
            ->getSearchResultsUsing($this->getOptionsSearchResultsUsing === null ? null : fn (string $search): array => (array) $this->evaluate($this->getOptionsSearchResultsUsing, ['search' => $search]))
            ->getOptionLabelUsing($this->getOptionLabelUsing === null ? null : fn (mixed $value): ?string => $this->evaluate($this->getOptionLabelUsing, ['value' => $value]));

        if ($this->hasOptionsRelationship()) {
            $field
                ->relationship(
                    fn (): ?string => $this->evaluate($this->optionsRelationshipName),
                    fn (): ?string => $this->evaluate($this->optionsRelationshipTitleAttribute),
                    function (Builder $query, ?string $search = null): Builder {
                        // Leave the record itself out (known while a cell is shown or saved).
                        if ($this->ignoresRecord() && ($record = $this->getRecord()) instanceof Model) {
                            $query->whereKeyNot($record->getKey());
                        }

                        if ($this->modifyOptionsQueryUsing !== null) {
                            $query = $this->evaluate($this->modifyOptionsQueryUsing, ['query' => $query, 'search' => $search], [Builder::class => $query]) ?? $query;
                        }

                        return $query;
                    },
                )
                ->getOptionLabelFromRecordUsing($this->getOptionLabelFromRecordUsing === null ? null : fn (Model $record): string => (string) $this->evaluate($this->getOptionLabelFromRecordUsing, ['record' => $record], [Model::class => $record, $record::class => $record]));
        } else {
            $field->options(fn (): array => $this->getOptions());
        }

        return $field;
    }

    /**
     * The options field inside a schema for the table's model.
     */
    protected function getOptionsField(): SelectColumnOptionsField
    {
        $field = $this->makeOptionsField();
        $schema = Schema::make()->components([$field]);
        $model = $this->getTable()?->getModel() ?? ($this->getRecord() instanceof Model ? $this->getRecord()::class : null);

        if ($model !== null) {
            $schema->model($model);
        }

        return $field;
    }

    public function updateState(Model|array $record, mixed $state): mixed
    {
        return $this->persistState($record, $state === '' ? null : $state);
    }

    /**
     * The value must be one of the options (and not the record itself under
     * `ignoreRecord`), then the column's own `rules()` apply.
     *
     * @param  Model|array<mixed>  $record
     */
    public function validateState(Model|array $record, mixed $state): void
    {
        $this->record($record);

        if (filled($state)) {
            abort_unless(is_scalar($state), 422);

            $attribute = ['state' => Labels::forSentence($this->getLabel())];

            if ($this->ignoresRecord() && $record instanceof Model && (string) $state === (string) $record->getKey()) {
                throw ValidationException::withMessages(['state' => __('validation.in', ['attribute' => $attribute['state']])]);
            }

            $rule = $this->getOptionsField()->getColumnOptionRule();

            if ($rule !== null) {
                Validator::make(['state' => $state], ['state' => [$rule]], [], $attribute)->validate();
            }
        }

        $this->validateColumnRules($record, $state === '' ? null : $state);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function getColumnSerializedOptions(): array
    {
        if ($this->hasRemoteOptions()) {
            return [];
        }

        $html = $this->allowsOptionsHtml();
        $native = $this->isNative();

        if ($this->hasOptionsRelationship()) {
            $field = $this->getOptionsField();

            return collect($field->sanitizeLabels($field->getOptions()))
                ->map(static fn (string $label, int|string $value): array => ['value' => (string) $value, 'label' => $native ? strip_tags($label) : $label])
                ->values()
                ->all();
        }

        $options = $this->getSerializedOptions();

        if (! $html) {
            return $options;
        }

        $clean = static fn (array $option): array => [...$option, 'label' => $native ? strip_tags((string) $option['label']) : \AlfaSix\Alfakit\Support\Html::sanitize((string) $option['label'])];

        return array_map(static fn (array $option): array => isset($option['options'])
            ? [...$option, 'options' => array_map($clean, $option['options'])]
            : $clean($option), $options);
    }

    protected function getSchemaProps(): array
    {
        $remote = $this->hasRemoteOptions();

        return [
            'editable' => true,
            'options' => $this->getColumnSerializedOptions(),
            'selectablePlaceholder' => (bool) $this->evaluate($this->canSelectPlaceholder),
            'placeholder' => $this->evaluate($this->placeholder),
            'native' => $this->isNative() ? null : false,
            'searchableOptions' => $this->hasSearchableOptions() ?: null,
            'optionsLimit' => $this->hasSearchableOptions() ? max(1, (int) $this->evaluate($this->optionsLimit)) : null,
            'allowOptionsHtml' => ! $this->isNative() && $this->allowsOptionsHtml() ? true : null,
            'ignoreRecord' => $this->ignoresRecord() ?: null,
            // Combobox messages; the renderer falls back to its translated defaults.
            ...($this->isNative() ? [] : [
                'loadingMessage' => $this->evaluate($this->loadingMessage),
                'noOptionsMessage' => $this->evaluate($this->noOptionsMessage),
                'noSearchResultsMessage' => $this->evaluate($this->noSearchResultsMessage),
                'searchPrompt' => $this->evaluate($this->searchPrompt),
                'searchingMessage' => $this->evaluate($this->searchingMessage),
                'optionsSearchDebounce' => ($debounce = $this->evaluate($this->optionsSearchDebounce)) === null ? null : max(0, (int) $debounce),
                'wrapOptionLabels' => $this->evaluate($this->shouldWrapOptionLabels) ? null : false,
            ]),
            // Remote options are searched through the table's filters schema.
            'optionsSearchPath' => $remote ? $this->getOptionsStatePath() : null,
        ];
    }

    protected function getCellProps(): array
    {
        return [
            'disabled' => $this->isDisabledForRecord() ?: null,
            'optionLabel' => $this->hasRemoteOptions() ? $this->getPageOptionLabel($this->getState()) : null,
        ];
    }

    /**
     * The label of a remote option, looked up once for every value on the page.
     */
    protected function getPageOptionLabel(mixed $state): ?string
    {
        if (! is_scalar($state) || $state === '') {
            return null;
        }

        $table = $this->getTable();
        $page = $table === null ? null : spl_object_id($table->getRecords());

        if ($page !== $this->pageOptionLabelsFor) {
            $this->pageOptionLabels = null;
            $this->pageOptionLabelsFor = $page;
        }

        if ($this->pageOptionLabels === null || ! array_key_exists((string) $state, $this->pageOptionLabels)) {
            $record = $this->getRecord();
            $values = [$state];

            if ($this->pageOptionLabels === null && $table !== null) {
                foreach ($table->getRecords() as $pageRecord) {
                    $this->record($pageRecord);
                    $value = $this->getState();

                    if (is_scalar($value) && $value !== '') {
                        $values[] = $value;
                    }
                }

                $this->record($record);
            }

            $labels = $this->getOptionsField()->getOptionLabelsFor(array_values(array_unique(array_map(strval(...), $values))));

            $this->pageOptionLabels = array_replace($this->pageOptionLabels ?? [], array_fill_keys(array_map(strval(...), $values), null), $labels);
        }

        $label = $this->pageOptionLabels[(string) $state] ?? null;

        return $label === null ? null : ($this->isNative() ? strip_tags($label) : $label);
    }
}
