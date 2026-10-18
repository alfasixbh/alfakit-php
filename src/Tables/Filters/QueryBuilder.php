<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tables\Filters;

use AlfaSix\Alfakit\Support\Enums\Width;
use AlfaSix\Alfakit\Tables\Filters\QueryBuilder\Components\QueryBuilderRules;
use AlfaSix\Alfakit\Tables\Filters\QueryBuilder\Constraints\Constraint;
use AlfaSix\Alfakit\Tables\Filters\QueryBuilder\Constraints\RelationshipConstraint;
use Closure;
use Illuminate\Database\Eloquent\Builder;

/**
 * A filter where users compose rules from constraints, combined with AND / OR and nested
 * in groups:
 *
 * ```php
 * QueryBuilder::make()->constraints([
 *     TextConstraint::make('title'),
 *     NumberConstraint::make('rating')->integer(),
 *     DateConstraint::make('published_at'),
 *     BooleanConstraint::make('is_published'),
 *     SelectConstraint::make('status')->options(['draft' => 'Draft', 'published' => 'Published'])->multiple(),
 *     RelationshipConstraint::make('tags')->titleAttribute('name')->multiple(),
 *     TextConstraint::make('author.name')->label('Author'),
 *     'Dates' => [DateConstraint::make('created_at'), DateConstraint::make('updated_at')],
 * ])
 * ```
 *
 * String keys group constraints under a label in the constraint picker.
 *
 * The rule tree lives in the query string under `filters[queryBuilder][query]`. Every rule
 * is validated on the server (known constraint, operator and value; at most `maxDepth()`
 * levels of groups and `maxRules()` rules); anything else is ignored.
 */
class QueryBuilder extends BaseFilter
{
    /** @var array<int|string, Constraint|array<Constraint>>|Closure */
    protected array|Closure $constraints = [];

    protected int|Closure $maxDepth = 2;

    protected int|Closure $maxRules = 50;

    /** @var int|array<string, int|null>|Closure|null */
    protected int|array|Closure|null $constraintPickerColumns = null;

    protected Width|string|Closure|null $constraintPickerWidth = null;

    /** @var array<string, Constraint>|null */
    protected ?array $resolvedConstraints = null;

    /** @var array<string, string> Group label by constraint name (`constraints(['Group' => [...]])`). */
    protected array $constraintGroups = [];

    public static function make(string $name = 'queryBuilder'): static
    {
        return parent::make($name);
    }

    /**
     * The constraints, optionally grouped under labels shown as sections in the picker:
     * `[TextConstraint::make('title'), 'Details' => [TextConstraint::make('body')]]`.
     *
     * @param  array<int|string, Constraint|array<Constraint>>|Closure  $constraints
     */
    public function constraints(array|Closure $constraints): static
    {
        $this->constraints = $constraints;
        $this->resolvedConstraints = null;

        return $this;
    }

    /**
     * How deep groups may nest (`0` allows no groups, only top-level rules).
     */
    public function maxDepth(int|Closure $depth): static
    {
        $this->maxDepth = $depth;

        return $this;
    }

    public function maxRules(int|Closure $rules): static
    {
        $this->maxRules = $rules;

        return $this;
    }

    /**
     * Lay the "add rule" constraint picker out in columns: `2`, or breakpoints.
     *
     * @param  int|array<string, int|null>|Closure|null  $columns
     */
    public function constraintPickerColumns(int|array|Closure|null $columns): static
    {
        $this->constraintPickerColumns = $columns;

        return $this;
    }

    /**
     * @return array<string, int|null>|null
     */
    public function getConstraintPickerColumns(): ?array
    {
        $columns = $this->evaluate($this->constraintPickerColumns);

        if ($columns === null) {
            return null;
        }

        return is_array($columns) ? $columns : ['default' => max(1, min(12, (int) $columns))];
    }

    /**
     * Width of the constraint picker (`Width::Large`, `'2xl'`, …).
     */
    public function constraintPickerWidth(Width|string|Closure|null $width): static
    {
        $this->constraintPickerWidth = $width;

        return $this;
    }

    public function getConstraintPickerWidth(): ?string
    {
        $width = $this->evaluate($this->constraintPickerWidth);

        return $width instanceof Width ? $width->value : ($width === null ? null : (string) $width);
    }

    public function getLabel(): string
    {
        return $this->evaluate($this->label) ?? __('alfakit::tables.query_builder.label');
    }

    /**
     * @return array<string, Constraint>
     */
    public function getConstraints(): array
    {
        if ($this->resolvedConstraints !== null) {
            return $this->resolvedConstraints;
        }

        $model = $this->table?->getModelInstance();
        $constraints = [];
        $this->constraintGroups = [];

        foreach ($this->evaluate($this->constraints) ?? [] as $group => $entries) {
            $grouped = is_string($group) && is_array($entries);

            foreach (is_array($entries) ? $entries : [$entries] as $constraint) {
                if (! $constraint instanceof Constraint) {
                    continue;
                }

                if ($constraint instanceof RelationshipConstraint) {
                    $constraint->model($model);
                }

                $name = $constraint->getName();
                $constraints[$name] = $constraint;
                unset($this->constraintGroups[$name]);

                if ($grouped) {
                    $this->constraintGroups[$name] = $group;
                }
            }
        }

        return $this->resolvedConstraints = $constraints;
    }

    /**
     * The constraints with their groups: ungrouped constraints first, then each group's
     * constraints under its label.
     *
     * @return array<int|string, Constraint|array<int, Constraint>>
     */
    public function getGroupedConstraints(): array
    {
        $ungrouped = [];
        $groups = [];

        foreach ($this->getConstraints() as $name => $constraint) {
            if (isset($this->constraintGroups[$name])) {
                $groups[$this->constraintGroups[$name]][] = $constraint;
            } else {
                $ungrouped[] = $constraint;
            }
        }

        return [...$ungrouped, ...$groups];
    }

    public function table(?\AlfaSix\Alfakit\Tables\Table $table): static
    {
        $this->resolvedConstraints = null;

        return parent::table($table);
    }

    public function getFormComponents(): array
    {
        return [
            QueryBuilderRules::make('query')
                ->label($this->getLabel())
                ->constraints(fn (): array => $this->getGroupedConstraints())
                ->maxDepth(fn (): int => (int) $this->evaluate($this->maxDepth))
                ->constraintPickerColumns(fn (): ?array => $this->getConstraintPickerColumns())
                ->constraintPickerWidth(fn (): ?string => $this->getConstraintPickerWidth()),
        ];
    }

    /**
     * The validated rule tree: invalid rules, empty groups and anything past the depth
     * and rule limits removed.
     *
     * @return array{combinator: string, rules: array<int, array<string, mixed>>}
     */
    public function getRuleTree(): array
    {
        $budget = (int) $this->evaluate($this->maxRules);

        return $this->resolveGroup($this->state['query'] ?? [], 0, $budget);
    }

    /**
     * @return array{combinator: string, rules: array<int, array<string, mixed>>}
     */
    protected function resolveGroup(mixed $group, int $depth, int &$budget): array
    {
        $group = is_array($group) ? $group : [];
        $rules = [];

        foreach (is_array($group['rules'] ?? null) ? $group['rules'] : [] as $rule) {
            if ($budget <= 0 || ! is_array($rule)) {
                continue;
            }

            if (($rule['type'] ?? 'constraint') === 'group') {
                if ($depth >= (int) $this->evaluate($this->maxDepth)) {
                    continue;
                }

                $nested = $this->resolveGroup($rule, $depth + 1, $budget);

                if ($nested['rules'] !== []) {
                    $rules[] = ['type' => 'group', ...$nested];
                }

                continue;
            }

            $constraint = is_string($rule['constraint'] ?? null) ? ($this->getConstraints()[$rule['constraint']] ?? null) : null;
            $resolved = $constraint?->resolveRule($rule['operator'] ?? null, $rule['value'] ?? null);

            if ($constraint === null || $resolved === null) {
                continue;
            }

            $budget--;
            $rules[] = ['type' => 'constraint', 'constraint' => $constraint->getName(), ...$resolved];
        }

        return ['combinator' => ($group['combinator'] ?? 'and') === 'or' ? 'or' : 'and', 'rules' => $rules];
    }

    public function isActive(): bool
    {
        return $this->getRuleTree()['rules'] !== [];
    }

    protected function applyToQuery(Builder $query): Builder
    {
        $tree = $this->getRuleTree();

        return $query->where(fn (Builder $query) => $this->applyGroup($query, $tree));
    }

    /**
     * @param  array{combinator: string, rules: array<int, array<string, mixed>>}  $group
     */
    protected function applyGroup(Builder $query, array $group): void
    {
        $boolean = $group['combinator'] === 'or' ? 'or' : 'and';

        foreach ($group['rules'] as $rule) {
            if ($rule['type'] === 'group') {
                /** @var array{combinator: string, rules: array<int, array<string, mixed>>} $rule */
                $query->where(fn (Builder $nested) => $this->applyGroup($nested, $rule), boolean: $boolean);

                continue;
            }

            $constraint = $this->getConstraints()[$rule['constraint']];
            $query->where(static fn (Builder $nested) => $constraint->apply($nested, $rule['operator'], $rule['value']), boolean: $boolean);
        }
    }

    /**
     * One chip per rule ("Title contains “news”"), five at most.
     *
     * @return array<string|Indicator>
     */
    public function getIndicators(): array
    {
        if (! $this->isActive() || $this->indicateUsing !== null) {
            return parent::getIndicators();
        }

        $indicators = [];
        $this->collectIndicators($this->getRuleTree(), $indicators);

        if (count($indicators) > 5) {
            $remaining = count($indicators) - 4;
            $indicators = [...array_slice($indicators, 0, 4), __('alfakit::tables.list.more', ['count' => $remaining])];
        }

        return $indicators;
    }

    /**
     * @param  array{combinator: string, rules: array<int, array<string, mixed>>}  $group
     * @param  array<string>  $indicators
     */
    protected function collectIndicators(array $group, array &$indicators): void
    {
        foreach ($group['rules'] as $rule) {
            if ($rule['type'] === 'group') {
                /** @var array{combinator: string, rules: array<int, array<string, mixed>>} $rule */
                $this->collectIndicators($rule, $indicators);

                continue;
            }

            $indicators[] = $this->getConstraints()[$rule['constraint']]->getIndicator($rule['operator'], $rule['value']);
        }
    }
}
