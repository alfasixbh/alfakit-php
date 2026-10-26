<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Forms\Components\Concerns;

use AlfaSix\Alfakit\Forms\Rules\ScopedExists;
use AlfaSix\Alfakit\Forms\Rules\ScopedUnique;
use AlfaSix\Alfakit\Schemas\Components\Component;
use AlfaSix\Alfakit\Support\Labels;
use AlfaSix\Alfakit\Support\TenantScope;
use BackedEnum;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rules\Enum as EnumRule;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\Rules\Unique;
use ReflectionFunction;
use UnitEnum;

trait HasValidationRules
{
    /** @var array<mixed> */
    protected array $rules = [];

    protected bool|Closure $isRequired = false;

    protected bool|Closure $isNullable = false;

    protected bool $isMarkedAsRequired = true;

    protected string|Closure|null $validationAttribute = null;

    /** @var array<string, string|Closure> */
    protected array $validationMessages = [];

    protected bool|Closure $isValidatedWhenNotDehydrated = true;

    protected bool|Closure $areHtmlValidationMessagesAllowed = false;

    /**
     * @param  string|array<mixed>|Closure  $rules
     */
    public function rules(string|array|Closure $rules, bool|Closure $condition = true): static
    {
        $this->rules[] = [$rules, $condition];

        return $this;
    }

    public function rule(mixed $rule, bool|Closure $condition = true): static
    {
        $this->rules[] = [$rule, $condition];

        return $this;
    }

    public function required(bool|Closure $condition = true): static
    {
        $this->isRequired = $condition;

        return $this;
    }

    public function nullable(bool|Closure $condition = true): static
    {
        $this->isNullable = $condition;

        return $this;
    }

    public function markAsRequired(bool $condition = true): static
    {
        $this->isMarkedAsRequired = $condition;

        return $this;
    }

    public function validationAttribute(string|Closure|null $label): static
    {
        $this->validationAttribute = $label;

        return $this;
    }

    /**
     * @param  array<string, string|Closure>  $messages
     */
    public function validationMessages(array $messages): static
    {
        $this->validationMessages = array_merge($this->validationMessages, $messages);

        return $this;
    }

    // --- Common rule shortcuts -------------------------------------------------

    /**
     * The value must not exist yet in `$table`. When the form is bound to a record of that
     * table (edit pages), the record itself is ignored, like Filament v4+; pass
     * `ignoreRecord: false` to check against every row. Tenant-owned tables are scoped
     * to the current tenant.
     */
    public function unique(string|Closure|null $table = null, string|Closure|null $column = null, Model|Closure|null $ignorable = null, bool $ignoreRecord = true, ?Closure $modifyRuleUsing = null): static
    {
        return $this->rule(function () use ($table, $column, $ignorable, $ignoreRecord, $modifyRuleUsing): Unique {
            $table = $this->evaluate($table) ?? $this->getModelInstance()?->getTable() ?? $this->getName();
            $column = $this->evaluate($column) ?? $this->getName();

            $rule = new Unique($table, $column);

            $ignorable = $this->evaluate($ignorable);

            // A ReplicateAction form's record is the original: its copy must not take its value.
            $ignoreRecord = $ignoreRecord && ! $this->getContainer()->isForRecordCopy();

            if ($ignorable === null && $ignoreRecord && ($record = $this->getRecord()) !== null && $this->isRecordOfRuleTable($record, (string) $table)) {
                $ignorable = $record;
            }

            if ($ignorable instanceof Model) {
                $rule->ignore($ignorable->getOriginal($ignorable->getKeyName()), $ignorable->getKeyName());
            }

            // Uniqueness is per tenant on tenant-owned tables.
            TenantScope::applyToRule($rule, $this->getModelInstance(), $table);

            if ($modifyRuleUsing !== null) {
                $rule = $this->evaluate($modifyRuleUsing, ['rule' => $rule]) ?? $rule;
            }

            return $rule;
        });
    }

    /**
     * Whether `$table` (a table name, `connection.table` or model class) is the record's
     * own table, so ignoring the record's key in it is meaningful.
     */
    protected function isRecordOfRuleTable(Model $record, string $table): bool
    {
        if ($table === $record::class || is_subclass_of($table, $record::class)) {
            return true;
        }

        $table = str_contains($table, '.') ? substr($table, strrpos($table, '.') + 1) : $table;

        return $table === $record->getTable();
    }

    public function exists(string|Closure|null $table = null, string|Closure|null $column = null, ?Closure $modifyRuleUsing = null): static
    {
        return $this->rule(function () use ($table, $column, $modifyRuleUsing): Exists {
            $table = $this->evaluate($table) ?? $this->getModelInstance()?->getTable() ?? $this->getName();
            $column = $this->evaluate($column) ?? $this->getName();

            $rule = new Exists($table, $column);

            TenantScope::applyToRule($rule, $this->getModelInstance(), $table);

            if ($modifyRuleUsing !== null) {
                $rule = $this->evaluate($modifyRuleUsing, ['rule' => $rule]) ?? $rule;
            }

            return $rule;
        });
    }

    /**
     * `unique()` through the Eloquent model instead of the database table, so the model's
     * global scopes apply: soft-deleted rows don't count, tenant-owned models are scoped to
     * the current tenant. `$model` defaults to the form's model; the bound record is
     * ignored on edit forms unless `ignoreRecord: false`. `modifyQueryUsing` receives the
     * Eloquent `$query` (e.g. `->withoutGlobalScope(SoftDeletingScope::class)`).
     *
     * @param  class-string<Model>|Closure|null  $model
     */
    public function scopedUnique(string|Closure|null $model = null, string|Closure|null $column = null, Model|Closure|null $ignorable = null, bool $ignoreRecord = true, ?Closure $modifyQueryUsing = null): static
    {
        return $this->rule(function () use ($model, $column, $ignorable, $ignoreRecord, $modifyQueryUsing): ?ScopedUnique {
            $model = $this->resolveScopedRuleModel($model);

            if ($model === null) {
                return null;
            }

            $ignorable = $this->evaluate($ignorable);

            if ($ignorable === null && $ignoreRecord && ($record = $this->getRecord()) !== null && $record instanceof $model && $record->exists) {
                $ignorable = $record;
            }

            return new ScopedUnique(
                $model,
                $this->evaluate($column) ?? $this->getName(),
                $ignorable instanceof Model ? $ignorable : null,
                $this->makeScopedRuleQueryModifier($modifyQueryUsing),
            );
        });
    }

    /**
     * `exists()` through the Eloquent model instead of the database table, so the model's
     * global scopes apply (soft-deleted or other tenants' records don't count).
     * `$model` defaults to the form's model; `modifyQueryUsing` receives the Eloquent
     * `$query`.
     *
     * @param  class-string<Model>|Closure|null  $model
     */
    public function scopedExists(string|Closure|null $model = null, string|Closure|null $column = null, ?Closure $modifyQueryUsing = null): static
    {
        return $this->rule(function () use ($model, $column, $modifyQueryUsing): ?ScopedExists {
            $model = $this->resolveScopedRuleModel($model);

            return $model === null ? null : new ScopedExists(
                $model,
                $this->evaluate($column) ?? $this->getName(),
                $this->makeScopedRuleQueryModifier($modifyQueryUsing),
            );
        });
    }

    /**
     * @param  class-string<Model>|Closure|null  $model
     * @return class-string<Model>|null
     */
    protected function resolveScopedRuleModel(string|Closure|null $model): ?string
    {
        $model = $this->evaluate($model) ?? $this->getModel();

        if ($model instanceof Model) {
            return $model::class;
        }

        return is_string($model) && is_subclass_of($model, Model::class) ? $model : null;
    }

    /**
     * The query modifier of a scoped rule: the current tenant's scope (tenant-owned
     * models), then the user's `modifyQueryUsing`, with utility injection.
     */
    protected function makeScopedRuleQueryModifier(?Closure $modifyQueryUsing): Closure
    {
        return function (Builder $query) use ($modifyQueryUsing): Builder {
            $query = TenantScope::apply($query);

            if ($modifyQueryUsing === null) {
                return $query;
            }

            $modified = $this->evaluate($modifyQueryUsing, ['query' => $query], [Builder::class => $query]);

            return $modified instanceof Builder ? $modified : $query;
        };
    }

    /**
     * @param  array<mixed>|Closure  $values
     */
    public function in(array|Closure $values, bool|Closure $condition = true): static
    {
        return $this->rule(fn (): string => 'in:'.$this->formatRuleParameters((array) $this->evaluate($values)), $condition);
    }

    /**
     * @param  array<mixed>|Closure  $values
     */
    public function notIn(array|Closure $values, bool|Closure $condition = true): static
    {
        return $this->rule(fn (): string => 'not_in:'.$this->formatRuleParameters((array) $this->evaluate($values)), $condition);
    }

    public function regex(string|Closure $pattern, bool|Closure $condition = true): static
    {
        return $this->rule(fn (): string => 'regex:'.$this->evaluate($pattern), $condition);
    }

    public function confirmed(bool|Closure $condition = true): static
    {
        return $this->rule('confirmed', $condition);
    }

    public function same(string|Closure $field, bool|Closure $condition = true): static
    {
        return $this->rule(fn (): string => 'same:'.$this->resolveRuleFieldPath($this->evaluate($field)), $condition);
    }

    public function different(string|Closure $field, bool|Closure $condition = true): static
    {
        return $this->rule(fn (): string => 'different:'.$this->resolveRuleFieldPath($this->evaluate($field)), $condition);
    }

    public function requiredWith(string|array|Closure $fields, bool|Closure $condition = true): static
    {
        return $this->rule(fn (): string => 'required_with:'.implode(',', array_map($this->resolveRuleFieldPath(...), (array) $this->evaluate($fields))), $condition);
    }

    public function requiredWithout(string|array|Closure $fields, bool|Closure $condition = true): static
    {
        return $this->rule(fn (): string => 'required_without:'.implode(',', array_map($this->resolveRuleFieldPath(...), (array) $this->evaluate($fields))), $condition);
    }

    public function requiredIf(string|Closure $field, mixed $value, bool|Closure $condition = true): static
    {
        return $this->rule(fn (): string => 'required_if:'.$this->resolveRuleFieldPath($this->evaluate($field)).','.$this->formatRuleParameters((array) $this->evaluate($value)), $condition);
    }

    public function requiredUnless(string|Closure $field, mixed $value, bool|Closure $condition = true): static
    {
        return $this->rule(fn (): string => 'required_unless:'.$this->resolveRuleFieldPath($this->evaluate($field)).','.$this->formatRuleParameters((array) $this->evaluate($value)), $condition);
    }

    public function prohibited(bool|Closure $condition = true): static
    {
        return $this->rule('prohibited', $condition);
    }

    public function distinct(bool|Closure $condition = true): static
    {
        return $this->rule('distinct', $condition);
    }

    public function activeUrl(bool|Closure $condition = true): static
    {
        return $this->rule('active_url', $condition);
    }

    public function alpha(bool|Closure $condition = true): static
    {
        return $this->rule('alpha', $condition);
    }

    public function alphaDash(bool|Closure $condition = true): static
    {
        return $this->rule('alpha_dash', $condition);
    }

    public function alphaNum(bool|Closure $condition = true): static
    {
        return $this->rule('alpha_num', $condition);
    }

    public function ascii(bool|Closure $condition = true): static
    {
        return $this->rule('ascii', $condition);
    }

    public function string(bool|Closure $condition = true): static
    {
        return $this->rule('string', $condition);
    }

    public function filled(bool|Closure $condition = true): static
    {
        return $this->rule('filled', $condition);
    }

    public function hexColor(bool|Closure $condition = true): static
    {
        return $this->rule('hex_color', $condition);
    }

    public function ipv4(bool|Closure $condition = true): static
    {
        return $this->rule('ipv4', $condition);
    }

    public function ipv6(bool|Closure $condition = true): static
    {
        return $this->rule('ipv6', $condition);
    }

    public function macAddress(bool|Closure $condition = true): static
    {
        return $this->rule('mac_address', $condition);
    }

    /**
     * The value must be one of the cases of a backed enum.
     *
     * @param  class-string<BackedEnum>|Closure  $enum
     */
    public function enum(string|Closure $enum, bool|Closure $condition = true): static
    {
        return $this->rule(fn (): EnumRule => new EnumRule($this->evaluate($enum)), $condition);
    }

    public function multipleOf(int|float|Closure $value, bool|Closure $condition = true): static
    {
        return $this->rule(fn (): string => 'multiple_of:'.$this->evaluate($value), $condition);
    }

    public function notRegex(string|Closure $pattern, bool|Closure $condition = true): static
    {
        return $this->rule(fn (): string => 'not_regex:'.$this->evaluate($pattern), $condition);
    }

    /**
     * Greater than another field (resolved like `$get()`) or a literal value.
     */
    public function gt(string|int|float|Closure $value, bool|Closure $condition = true): static
    {
        return $this->rule(fn (): string => 'gt:'.$this->resolveRuleFieldPath($this->evaluate($value)), $condition);
    }

    public function gte(string|int|float|Closure $value, bool|Closure $condition = true): static
    {
        return $this->rule(fn (): string => 'gte:'.$this->resolveRuleFieldPath($this->evaluate($value)), $condition);
    }

    public function lt(string|int|float|Closure $value, bool|Closure $condition = true): static
    {
        return $this->rule(fn (): string => 'lt:'.$this->resolveRuleFieldPath($this->evaluate($value)), $condition);
    }

    public function lte(string|int|float|Closure $value, bool|Closure $condition = true): static
    {
        return $this->rule(fn (): string => 'lte:'.$this->resolveRuleFieldPath($this->evaluate($value)), $condition);
    }

    public function prohibitedIf(string|Closure $field, mixed $value, bool|Closure $condition = true): static
    {
        return $this->rule(fn (): string => 'prohibited_if:'.$this->resolveRuleFieldPath($this->evaluate($field)).','.$this->formatRuleParameters((array) $this->evaluate($value)), $condition);
    }

    public function prohibitedUnless(string|Closure $field, mixed $value, bool|Closure $condition = true): static
    {
        return $this->rule(fn (): string => 'prohibited_unless:'.$this->resolveRuleFieldPath($this->evaluate($field)).','.$this->formatRuleParameters((array) $this->evaluate($value)), $condition);
    }

    /**
     * When this field is filled, the given fields must be empty.
     *
     * @param  string|array<string>|Closure  $fields
     */
    public function prohibits(string|array|Closure $fields, bool|Closure $condition = true): static
    {
        return $this->rule(fn (): string => 'prohibits:'.$this->resolveRuleFieldPaths($fields), $condition);
    }

    public function requiredIfAccepted(string|Closure $field, bool|Closure $condition = true): static
    {
        return $this->rule(fn (): string => 'required_if_accepted:'.$this->resolveRuleFieldPath($this->evaluate($field)), $condition);
    }

    /**
     * @param  string|array<string>|Closure  $fields
     */
    public function requiredWithAll(string|array|Closure $fields, bool|Closure $condition = true): static
    {
        return $this->rule(fn (): string => 'required_with_all:'.$this->resolveRuleFieldPaths($fields), $condition);
    }

    /**
     * @param  string|array<string>|Closure  $fields
     */
    public function requiredWithoutAll(string|array|Closure $fields, bool|Closure $condition = true): static
    {
        return $this->rule(fn (): string => 'required_without_all:'.$this->resolveRuleFieldPaths($fields), $condition);
    }

    public function date(bool|Closure $condition = true): static
    {
        return $this->rule('date', $condition);
    }

    public function after(string|Closure $date, bool|Closure $condition = true): static
    {
        return $this->rule(fn (): string => 'after:'.$this->resolveRuleFieldPath($this->evaluate($date)), $condition);
    }

    public function afterOrEqual(string|Closure $date, bool|Closure $condition = true): static
    {
        return $this->rule(fn (): string => 'after_or_equal:'.$this->resolveRuleFieldPath($this->evaluate($date)), $condition);
    }

    public function before(string|Closure $date, bool|Closure $condition = true): static
    {
        return $this->rule(fn (): string => 'before:'.$this->resolveRuleFieldPath($this->evaluate($date)), $condition);
    }

    public function beforeOrEqual(string|Closure $date, bool|Closure $condition = true): static
    {
        return $this->rule(fn (): string => 'before_or_equal:'.$this->resolveRuleFieldPath($this->evaluate($date)), $condition);
    }

    public function uuid(bool|Closure $condition = true): static
    {
        return $this->rule('uuid', $condition);
    }

    public function ulid(bool|Closure $condition = true): static
    {
        return $this->rule('ulid', $condition);
    }

    public function ip(bool|Closure $condition = true): static
    {
        return $this->rule('ip', $condition);
    }

    public function json(bool|Closure $condition = true): static
    {
        return $this->rule('json', $condition);
    }

    public function startsWith(string|array|Closure $values, bool|Closure $condition = true): static
    {
        return $this->rule(fn (): string => 'starts_with:'.$this->formatRuleParameters((array) $this->evaluate($values)), $condition);
    }

    public function endsWith(string|array|Closure $values, bool|Closure $condition = true): static
    {
        return $this->rule(fn (): string => 'ends_with:'.$this->formatRuleParameters((array) $this->evaluate($values)), $condition);
    }

    public function doesntStartWith(string|array|Closure $values, bool|Closure $condition = true): static
    {
        return $this->rule(fn (): string => 'doesnt_start_with:'.$this->formatRuleParameters((array) $this->evaluate($values)), $condition);
    }

    public function doesntEndWith(string|array|Closure $values, bool|Closure $condition = true): static
    {
        return $this->rule(fn (): string => 'doesnt_end_with:'.$this->formatRuleParameters((array) $this->evaluate($values)), $condition);
    }

    /**
     * Rule closures built by the shortcuts above (`unique()`, `same()`…) capture the field
     * they were declared on. Repeater items validate cloned fields, so point such closures
     * at the clone: its state path, container and record are the item's.
     */
    protected function bindRuleToThisComponent(mixed $rule): mixed
    {
        if (! $rule instanceof Closure) {
            return $rule;
        }

        $bound = (new ReflectionFunction($rule))->getClosureThis();

        if ($bound instanceof Component && $bound !== $this && $bound::class === static::class) {
            return Closure::bind($rule, $this, static::class);
        }

        return $rule;
    }

    // --- Rule parameters ----------------------------------------------------------

    /**
     * Quote rule parameters the way Laravel's `Rule::in()` does, so values containing
     * commas or quotes survive the validator's CSV parsing.
     *
     * @param  array<mixed>  $values
     */
    protected function formatRuleParameters(array $values): string
    {
        return implode(',', array_map(static function (mixed $value): string {
            $value = $value instanceof BackedEnum ? $value->value : ($value instanceof UnitEnum ? $value->name : $value);

            if (is_bool($value)) {
                $value = $value ? 'true' : 'false';
            }

            return '"'.str_replace('"', '""', (string) $value).'"';
        }, $values));
    }

    /**
     * Resolve a field reference in a rule (`same:`, `required_if:`, `after:`…) relative to
     * this field, like `$get()`: inside a repeater item, `type` means the sibling
     * `items.0.type`. References that aren't a field of the form (dates like `today`,
     * absolute paths) are left untouched.
     */
    protected function resolveRuleFieldPath(mixed $path): string
    {
        $path = (string) $path;
        $resolved = $this->makeGetUtility()->resolvePath($path);

        if ($resolved === $path) {
            return $path;
        }

        return Arr::has($this->getContainer()->getRootSchema()->getRawState(), $resolved) ? $resolved : $path;
    }

    /**
     * @param  string|array<string>|Closure  $fields
     */
    protected function resolveRuleFieldPaths(string|array|Closure $fields): string
    {
        return implode(',', array_map($this->resolveRuleFieldPath(...), (array) $this->evaluate($fields)));
    }

    // --- Resolution -------------------------------------------------------------

    public function isRequired(): bool
    {
        return (bool) $this->evaluate($this->isRequired);
    }

    public function isMarkedAsRequired(): bool
    {
        return $this->isMarkedAsRequired && $this->isRequired();
    }

    public function isNullable(): bool
    {
        return (bool) $this->evaluate($this->isNullable);
    }

    /**
     * The full set of Laravel validation rules for this field.
     *
     * @return array<mixed>
     */
    public function getValidationRules(): array
    {
        $rules = [];

        $rules[] = $this->isRequired() ? 'required' : 'nullable';

        array_push($rules, ...$this->getTypeValidationRules());

        foreach ($this->rules as [$rule, $condition]) {
            if (! $this->evaluate($condition)) {
                continue;
            }

            $rule = $this->evaluate($this->bindRuleToThisComponent($rule));

            if (is_string($rule) && str_contains($rule, '|')) {
                array_push($rules, ...explode('|', $rule));
            } elseif (is_array($rule)) {
                array_push($rules, ...array_values(array_map($this->evaluate(...), $rule)));
            } elseif ($rule !== null) {
                $rules[] = $rule;
            }
        }

        return array_values(array_unique(array_filter($rules, static fn (mixed $rule): bool => $rule !== null && $rule !== ''), SORT_REGULAR));
    }

    /**
     * Rules keyed by absolute state path. Container fields (Repeater) override this to
     * include their children's paths.
     *
     * @return array<string, array<mixed>>
     */
    public function getValidationRulesByPath(): array
    {
        if (! $this->shouldBeValidated()) {
            return [];
        }

        return [$this->getStatePath() => $this->getValidationRules()];
    }

    /**
     * Fields are validated even when they are not dehydrated (`dehydrated(false)`,
     * `saved(false)`); `validatedWhenNotDehydrated(false)` skips their rules instead.
     */
    public function validatedWhenNotDehydrated(bool|Closure $condition = true): static
    {
        $this->isValidatedWhenNotDehydrated = $condition;

        return $this;
    }

    public function isValidatedWhenNotDehydrated(): bool
    {
        return (bool) $this->evaluate($this->isValidatedWhenNotDehydrated);
    }

    protected function shouldBeValidated(): bool
    {
        if ($this->isValidatedWhenNotDehydrated()) {
            return true;
        }

        return ! method_exists($this, 'isDehydrated') || $this->isDehydrated();
    }

    /**
     * Render this field's validation messages as HTML (custom messages with markup).
     * Laravel substitutes `:input` without escaping, so don't use `:input` in them.
     */
    public function allowHtmlValidationMessages(bool|Closure $condition = true): static
    {
        $this->areHtmlValidationMessagesAllowed = $condition;

        return $this;
    }

    public function areHtmlValidationMessagesAllowed(): bool
    {
        return (bool) $this->evaluate($this->areHtmlValidationMessagesAllowed);
    }

    /**
     * Human-readable attribute names keyed by absolute state path.
     *
     * @return array<string, string>
     */
    public function getValidationAttributesByPath(): array
    {
        return [$this->getStatePath() => $this->getValidationAttribute()];
    }

    /**
     * Custom messages keyed by `path.rule`.
     *
     * @return array<string, string>
     */
    public function getValidationMessagesByPath(): array
    {
        $messages = [];

        foreach ($this->getValidationMessages() as $rule => $message) {
            $messages[$this->getStatePath().'.'.$rule] = $message;
        }

        return $messages;
    }

    /**
     * Rules contributed by the field type itself (e.g. `email`, `numeric`).
     *
     * @return array<mixed>
     */
    protected function getTypeValidationRules(): array
    {
        return [];
    }

    public function getValidationAttribute(): string
    {
        return $this->evaluate($this->validationAttribute) ?? Labels::forSentence($this->getLabel() ?? Labels::fromName((string) $this->getName(), isRelationshipPath: false));
    }

    /**
     * @return array<string, string>
     */
    public function getValidationMessages(): array
    {
        return Arr::map($this->validationMessages, fn (string|Closure $message): string => (string) $this->evaluate($message));
    }
}
