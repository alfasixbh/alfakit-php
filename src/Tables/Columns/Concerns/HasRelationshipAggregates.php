<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tables\Columns\Concerns;

use Closure;
use Illuminate\Contracts\Database\Query\Expression as ExpressionContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\Expression;

/**
 * Relationship aggregates (`withCount`, `withExists`, `withAvg`, …) added to the table
 * query for a column. Name the column after the attribute Eloquent produces:
 *
 * ```php
 * TextColumn::make('comments_count')->counts('comments')
 * TextColumn::make('comments_avg_rating')->avg('comments', 'rating')
 * TextColumn::make('order_total')->sum('items as order_total', DB::raw('price * quantity'))
 * IconColumn::make('comments_exists')->exists('comments')->boolean()
 * TextColumn::make('approved_comments_count')->counts(['comments as approved_comments_count' => fn (Builder $query) => $query->where('approved', true)])
 * ```
 *
 * Aggregate columns sort by their select alias and search through the same sub-select
 * (`where (select count(*) …) like ?`), since an alias can't be used in `WHERE`.
 */
trait HasRelationshipAggregates
{
    /** @var array{function: string, relationships: string|array<string|int, Closure|string>, column: string|ExpressionContract|null}|null */
    protected ?array $relationshipAggregate = null;

    /**
     * @param  string|array<string|int, Closure|string>  $relationships
     */
    public function counts(string|array $relationships): static
    {
        return $this->aggregateRelationship('count', $relationships);
    }

    /**
     * @param  string|array<string|int, Closure|string>  $relationships
     */
    public function exists(string|array $relationships): static
    {
        return $this->aggregateRelationship('exists', $relationships);
    }

    /**
     * @param  string|array<string|int, Closure|string>  $relationships
     */
    public function avg(string|array $relationships, string|ExpressionContract $column): static
    {
        return $this->aggregateRelationship('avg', $relationships, $column);
    }

    /**
     * @param  string|array<string|int, Closure|string>  $relationships
     */
    public function sum(string|array $relationships, string|ExpressionContract $column): static
    {
        return $this->aggregateRelationship('sum', $relationships, $column);
    }

    /**
     * @param  string|array<string|int, Closure|string>  $relationships
     */
    public function min(string|array $relationships, string|ExpressionContract $column): static
    {
        return $this->aggregateRelationship('min', $relationships, $column);
    }

    /**
     * @param  string|array<string|int, Closure|string>  $relationships
     */
    public function max(string|array $relationships, string|ExpressionContract $column): static
    {
        return $this->aggregateRelationship('max', $relationships, $column);
    }

    /**
     * @param  string|array<string|int, Closure|string>  $relationships
     */
    protected function aggregateRelationship(string $function, string|array $relationships, string|ExpressionContract|null $column = null): static
    {
        $this->relationshipAggregate = ['function' => $function, 'relationships' => $relationships, 'column' => $column];

        return $this;
    }

    public function hasRelationshipAggregate(): bool
    {
        return $this->relationshipAggregate !== null;
    }

    /**
     * Add the aggregate sub-select to the table query. Constraint closures receive the
     * relationship query, so tenant and other global scopes of the related model apply.
     */
    public function applyRelationshipAggregate(Builder $query): Builder
    {
        if ($this->relationshipAggregate === null) {
            return $query;
        }

        ['function' => $function, 'relationships' => $relationships, 'column' => $column] = $this->relationshipAggregate;

        return match ($function) {
            'count' => $query->withCount($relationships),
            'exists' => $query->withExists($relationships),
            default => $query->withAggregate($relationships, $column ?? '*', $function),
        };
    }

    /**
     * The aggregate as a correlated sub-select on `$query`'s table (what `withCount()`
     * selects under the alias), for searching and sorting without the alias. Null for
     * `exists()` (a boolean isn't searched) and for relationships the model doesn't have.
     */
    public function getRelationshipAggregateQuery(Builder $query): ?QueryBuilder
    {
        if ($this->relationshipAggregate === null || $this->relationshipAggregate['function'] === 'exists') {
            return null;
        }

        ['function' => $function, 'relationships' => $relationships, 'column' => $column] = $this->relationshipAggregate;

        // The first entry names the relationship (`comments` or `comments as alias`), with
        // an optional constraint closure; the column reads that one aggregate.
        $relationships = is_array($relationships) ? $relationships : [$relationships];
        $name = null;
        $constraints = null;

        foreach ($relationships as $key => $value) {
            [$name, $constraints] = is_string($key) ? [$key, $value instanceof Closure ? $value : null] : [$value, null];

            break;
        }

        if (! is_string($name)) {
            return null;
        }

        $name = preg_split('/\s+as\s+/i', $name)[0] ?? $name;
        $model = $query->getModel();

        if (! method_exists($model, $name)) {
            return null;
        }

        $relation = Relation::noConstraints(static fn (): mixed => $model->{$name}());

        if (! $relation instanceof Relation) {
            return null;
        }

        // Unqualified, the column binds to the sub-select's own table first, which also
        // keeps self-relationships (aliased by Eloquent) unambiguous.
        // A raw expression (`DB::raw('price * quantity')`) is used as written.
        $grammar = $query->getQuery()->getGrammar();
        $aggregated = $column instanceof ExpressionContract ? (string) $grammar->getValue($column) : $grammar->wrap((string) $column);
        $expression = $function === 'count' ? 'count(*)' : sprintf('%s(%s)', $function, $aggregated);

        $subQuery = $relation->getRelationExistenceQuery($relation->getRelated()->newQuery(), $query)
            ->select(new Expression($expression))
            ->setBindings([], 'select');

        if ($constraints !== null) {
            $constraints($subQuery);
        }

        $subQuery = $subQuery->mergeConstraintsFrom($relation->getQuery())->toBase();
        $subQuery->orders = null;
        $subQuery->setBindings([], 'order');

        if (count($subQuery->columns ?? []) > 1) {
            $subQuery->columns = [$subQuery->columns[0]];
            $subQuery->bindings['select'] = [];
        }

        return $subQuery;
    }
}
