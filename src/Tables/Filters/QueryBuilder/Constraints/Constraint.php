<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tables\Filters\QueryBuilder\Constraints;

use AlfaSix\Alfakit\Support\Concerns\Configurable;
use AlfaSix\Alfakit\Support\Concerns\EvaluatesClosures;
use AlfaSix\Alfakit\Support\Icons;
use AlfaSix\Alfakit\Support\Labels;
use AlfaSix\Alfakit\Support\Serializable;
use AlfaSix\Alfakit\Tables\Filters\QueryBuilder\Operators\Operator;
use BackedEnum;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * One kind of rule the query builder offers: an attribute with a set of operators.
 *
 * The attribute may live on a relationship, either dotted (`TextConstraint::make('author.name')`)
 * or explicit (`->relationship('author', 'name')`); rules then match records with at least
 * one related record that satisfies them.
 *
 * Everything a rule carries comes from the query string, so operators are matched against
 * the constraint's own list and values are validated before they touch the query.
 */
abstract class Constraint
{
    use Configurable;
    use EvaluatesClosures;

    protected string $name;

    protected string|Closure|null $label = null;

    protected string|Closure|null $attribute = null;

    protected string|BackedEnum|\Illuminate\Contracts\Support\Htmlable|Closure|null $icon = null;

    protected string|Closure|null $relationshipName = null;

    protected ?Closure $modifyRelationshipQueryUsing = null;

    /** @var list<string|Operator>|null Replaces the built-in operators (`operators()`). */
    protected ?array $operatorsOverride = null;

    /** @var list<string|Operator> */
    protected array $pushedOperators = [];

    /** @var list<string|Operator> */
    protected array $unshiftedOperators = [];

    protected bool|Closure|null $isNullable = null;

    final public function __construct(string $name)
    {
        $this->name = $name;
    }

    public static function make(string $name): static
    {
        $static = new static($name);
        $static->configure();

        return $static;
    }

    protected function getEvaluationIdentifier(): string
    {
        return 'constraint';
    }

    /**
     * The value input the renderer shows: `text`, `number`, `date`, `select` or `none`.
     */
    abstract public function getType(): string;

    /**
     * Operator name => label.
     *
     * @return array<string, string>
     */
    abstract public function getOperators(): array;

    /**
     * Operators that take no value (`is_filled`, `is_true`, …).
     *
     * @return array<string>
     */
    protected function getValuelessOperators(): array
    {
        return ['is_filled', 'is_blank'];
    }

    /**
     * Normalise a value from the query string, or return null when it is not acceptable
     * for the operator (the rule is then ignored).
     *
     * @return array{value: mixed}|null
     */
    abstract protected function validateValue(string $operator, mixed $value): ?array;

    /**
     * Constrain `$query` (already wrapped in its own `where` group) with the rule.
     */
    abstract protected function applyOperator(Builder $query, string $column, string $operator, mixed $value): void;

    public function getName(): string
    {
        return $this->name;
    }

    public function label(string|Closure|null $label): static
    {
        $this->label = $label;

        return $this;
    }

    public function getLabel(): string
    {
        return $this->evaluate($this->label) ?? Labels::fromName($this->name);
    }

    public function icon(string|BackedEnum|\Illuminate\Contracts\Support\Htmlable|Closure|null $icon): static
    {
        $this->icon = $icon;

        return $this;
    }

    /**
     * The icon, with registered aliases (`Icons::register()`, `alfakit::*`) resolved.
     */
    public function getIcon(): ?string
    {
        return Icons::resolve($this->evaluate($this->icon));
    }

    public function attribute(string|Closure|null $attribute): static
    {
        $this->attribute = $attribute;

        return $this;
    }

    /**
     * Read the attribute from a relationship: `->relationship('author', 'name')`.
     */
    public function relationship(string|Closure $name, string|Closure|null $titleAttribute = null, ?Closure $modifyQueryUsing = null): static
    {
        $this->relationshipName = $name;
        $this->modifyRelationshipQueryUsing = $modifyQueryUsing;

        if ($titleAttribute !== null) {
            $this->attribute = $titleAttribute;
        }

        return $this;
    }

    /**
     * Offer the "is filled" / "is blank" operators. AlfaKit offers them unless
     * `nullable(false)` (Filament leaves them out unless `nullable()`).
     */
    public function nullable(bool|Closure $condition = true): static
    {
        $this->isNullable = $condition;

        return $this;
    }

    public function isNullable(): bool
    {
        return $this->isNullable === null || (bool) $this->evaluate($this->isNullable);
    }

    public function getAttribute(): string
    {
        $attribute = $this->evaluate($this->attribute) ?? $this->name;

        return $this->evaluate($this->relationshipName) === null ? $attribute : Str::afterLast($attribute, '.');
    }

    public function getRelationshipName(): ?string
    {
        $relationship = $this->evaluate($this->relationshipName);

        if ($relationship !== null) {
            return $relationship;
        }

        $attribute = $this->evaluate($this->attribute) ?? $this->name;

        return str_contains($attribute, '.') ? Str::beforeLast($attribute, '.') : null;
    }

    /**
     * The operators offered, in order: built-in operator names (`'contains'`, `'equals'`,
     * …) to keep and custom `Operator`s. Unknown names are ignored.
     *
     * @param  array<string|Operator>|null  $operators
     */
    public function operators(?array $operators): static
    {
        $this->operatorsOverride = $operators === null ? null : array_values($operators);

        return $this;
    }

    /**
     * Add operators after the built-in ones.
     *
     * @param  array<string|Operator>  $operators
     */
    public function pushOperators(array $operators): static
    {
        $this->pushedOperators = [...$this->pushedOperators, ...array_values($operators)];

        return $this;
    }

    /**
     * Add operators before the built-in ones.
     *
     * @param  array<string|Operator>  $operators
     */
    public function unshiftOperators(array $operators): static
    {
        $this->unshiftedOperators = [...array_values($operators), ...$this->unshiftedOperators];

        return $this;
    }

    /**
     * The operators offered: name => label, built-in and custom, in display order.
     *
     * @return array<string, string>
     */
    public function getAvailableOperators(): array
    {
        $builtIn = $this->getOperators();

        if (! $this->isNullable()) {
            unset($builtIn['is_filled'], $builtIn['is_blank']);
        }

        $operators = [];

        foreach ([...$this->unshiftedOperators, ...($this->operatorsOverride ?? array_keys($builtIn)), ...$this->pushedOperators] as $operator) {
            if ($operator instanceof Operator) {
                $operators[$operator->getName()] ??= $operator->getLabel();
            } elseif (is_string($operator) && isset($builtIn[$operator])) {
                $operators[$operator] ??= $builtIn[$operator];
            }
        }

        return $operators;
    }

    /**
     * A custom operator by name, or null for built-in (or unknown) ones.
     */
    public function getCustomOperator(string $name): ?Operator
    {
        foreach ([...$this->unshiftedOperators, ...($this->operatorsOverride ?? []), ...$this->pushedOperators] as $operator) {
            if ($operator instanceof Operator && $operator->getName() === $name) {
                return $operator;
            }
        }

        return null;
    }

    public function hasOperator(string $operator): bool
    {
        return array_key_exists($operator, $this->getAvailableOperators());
    }

    public function takesValue(string $operator): bool
    {
        if (($custom = $this->getCustomOperator($operator)) !== null) {
            return $custom->takesValue();
        }

        return ! in_array($operator, $this->getValuelessOperators(), true);
    }

    /**
     * Validate a rule from the query string.
     *
     * @return array{operator: string, value: mixed}|null
     */
    public function resolveRule(mixed $operator, mixed $value): ?array
    {
        if (! is_string($operator) || ! $this->hasOperator($operator)) {
            return null;
        }

        if (! $this->takesValue($operator)) {
            return ['operator' => $operator, 'value' => null];
        }

        if (($custom = $this->getCustomOperator($operator)) !== null) {
            $validated = $custom->validateValue($value);

            return $validated === null ? null : ['operator' => $operator, 'value' => $validated];
        }

        $validated = $this->validateValue($operator, $value);

        return $validated === null ? null : ['operator' => $operator, 'value' => $validated['value']];
    }

    /**
     * Apply a validated rule.
     */
    public function apply(Builder $query, string $operator, mixed $value): void
    {
        $relationship = $this->getRelationshipName();
        $attribute = Str::afterLast($this->getAttribute(), '.');

        if (($custom = $this->getCustomOperator($operator)) !== null) {
            if ($relationship === null) {
                $custom->apply($query, $query->qualifyColumn($attribute), $this, $value);

                return;
            }

            $query->whereHas($relationship, function (Builder $related) use ($custom, $attribute, $value): void {
                $this->modifyRelationshipQuery($related);
                $custom->apply($related, $related->qualifyColumn($attribute), $this, $value);
            });

            return;
        }

        if ($relationship === null) {
            $this->applyOperator($query, $query->qualifyColumn($attribute), $operator, $value);

            return;
        }

        // "Is blank" on a relationship also matches records without any related record.
        if ($operator === 'is_blank') {
            $query->whereDoesntHave($relationship, function (Builder $related) use ($attribute): void {
                $this->modifyRelationshipQuery($related);
                $related->whereNotNull($related->qualifyColumn($attribute));
            });

            return;
        }

        $query->whereHas($relationship, function (Builder $related) use ($attribute, $operator, $value): void {
            $this->modifyRelationshipQuery($related);
            $this->applyOperator($related, $related->qualifyColumn($attribute), $operator, $value);
        });
    }

    protected function modifyRelationshipQuery(Builder $query): void
    {
        if ($this->modifyRelationshipQueryUsing !== null) {
            $this->evaluate($this->modifyRelationshipQueryUsing, ['query' => $query], [Builder::class => $query]);
        }
    }

    /**
     * The chip shown for an active rule: "Title contains “news”".
     */
    public function getIndicator(string $operator, mixed $value): string
    {
        $label = $this->getLabel().' '.($this->getAvailableOperators()[$operator] ?? $operator);

        if (! $this->takesValue($operator)) {
            return $label;
        }

        $formatted = ($custom = $this->getCustomOperator($operator)) !== null
            ? $custom->formatValue($value)
            : $this->formatIndicatorValue($operator, $value);

        return $label.' '.__('alfakit::tables.query_builder.value', ['value' => $formatted]);
    }

    protected function formatIndicatorValue(string $operator, mixed $value): string
    {
        return is_array($value) ? implode(', ', array_map(strval(...), $value)) : (string) $value;
    }

    /**
     * Operator definitions for the renderer.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function getOperatorsSchema(): array
    {
        $operators = [];

        foreach ($this->getAvailableOperators() as $name => $label) {
            $operators[] = $this->getOperatorSchema($name, $label);
        }

        return $operators;
    }

    /**
     * One operator for the renderer; custom operators bring their own input and options.
     *
     * @return array<string, mixed>
     */
    protected function getOperatorSchema(string $name, string $label): array
    {
        if (($custom = $this->getCustomOperator($name)) !== null) {
            return Serializable::compact([
                'name' => $name,
                'label' => $label,
                'input' => $custom->getInput() ?? 'none',
                'options' => $custom->getOptionsSchema(),
            ]);
        }

        return [
            'name' => $name,
            'label' => $label,
            'input' => $this->takesValue($name) ? $this->getOperatorInput($name) : 'none',
        ];
    }

    /**
     * The value input of a built-in operator that takes a value (default: `getType()`).
     */
    protected function getOperatorInput(string $operator): string
    {
        return $this->getType();
    }

    /**
     * Extra props for the value input (options, `multiple`, `step`).
     *
     * @return array<string, mixed>
     */
    protected function getInputSchemaProps(): array
    {
        return [];
    }

    /**
     * @return array<string, mixed>
     */
    public function toSchema(): array
    {
        return Serializable::compact([
            'name' => $this->getName(),
            'label' => $this->getLabel(),
            'type' => $this->getType(),
            'icon' => $this->getIcon(),
            'operators' => $this->getOperatorsSchema(),
            ...$this->getInputSchemaProps(),
        ]);
    }
}
