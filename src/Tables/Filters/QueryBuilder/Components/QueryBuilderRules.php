<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tables\Filters\QueryBuilder\Components;

use AlfaSix\Alfakit\Forms\Components\Field;
use AlfaSix\Alfakit\Tables\Filters\QueryBuilder\Constraints\Constraint;
use Closure;

/**
 * The rule editor of a `QueryBuilder` filter. Its state is the rule tree:
 * `{combinator: 'and'|'or', rules: [{type: 'constraint', constraint, operator, value}
 * | {type: 'group', combinator, rules}]}`.
 */
class QueryBuilderRules extends Field
{
    /** @var array<int|string, Constraint|array<Constraint>>|Closure */
    protected array|Closure $constraints = [];

    protected int|Closure $maxDepth = 2;

    /** @var array<string, int|null>|Closure|null */
    protected array|Closure|null $constraintPickerColumns = null;

    protected string|Closure|null $constraintPickerWidth = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->hiddenLabel();
    }

    /**
     * Constraints, optionally grouped: `['Details' => [TextConstraint::make('body')]]`.
     *
     * @param  array<int|string, Constraint|array<Constraint>>|Closure  $constraints
     */
    public function constraints(array|Closure $constraints): static
    {
        $this->constraints = $constraints;

        return $this;
    }

    public function maxDepth(int|Closure $depth): static
    {
        $this->maxDepth = $depth;

        return $this;
    }

    /**
     * @param  array<string, int|null>|Closure|null  $columns
     */
    public function constraintPickerColumns(array|Closure|null $columns): static
    {
        $this->constraintPickerColumns = $columns;

        return $this;
    }

    public function constraintPickerWidth(string|Closure|null $width): static
    {
        $this->constraintPickerWidth = $width;

        return $this;
    }

    protected function getSchemaProps(): array
    {
        return [
            'constraints' => $this->getConstraintsSchema(),
            'maxDepth' => $this->evaluate($this->maxDepth),
            'constraintPickerColumns' => $this->evaluate($this->constraintPickerColumns),
            'constraintPickerWidth' => $this->evaluate($this->constraintPickerWidth),
        ];
    }

    /**
     * Flat, with a grouped constraint's group label in `group` (ungrouped ones first).
     *
     * @return array<int, array<string, mixed>>
     */
    protected function getConstraintsSchema(): array
    {
        $ungrouped = [];
        $grouped = [];

        foreach ($this->evaluate($this->constraints) ?? [] as $group => $entries) {
            if (is_string($group) && is_array($entries)) {
                foreach ($entries as $constraint) {
                    if ($constraint instanceof Constraint) {
                        $grouped[] = [...$constraint->toSchema(), 'group' => $group];
                    }
                }
            } elseif ($entries instanceof Constraint) {
                $ungrouped[] = $entries->toSchema();
            }
        }

        return [...$ungrouped, ...$grouped];
    }
}
