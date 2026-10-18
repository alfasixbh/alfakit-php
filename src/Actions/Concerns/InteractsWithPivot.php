<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Actions\Concerns;

use AlfaSix\Alfakit\Resources\RelationManagers\ApiRelationManager;
use AlfaSix\Alfakit\Resources\RelationManagers\RelationManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Arr;

/**
 * Pivot attributes of BelongsToMany relation managers: form fields named after the
 * relationship's `withPivot()` columns are filled from, and saved to, the pivot row.
 */
trait InteractsWithPivot
{
    /**
     * The BelongsToMany relationship of the relation manager that owns this action.
     *
     * @return BelongsToMany<Model, Model>|null
     */
    public function getPivotRelationship(): ?BelongsToMany
    {
        $relationship = $this->getRelationManagerRelationship();

        return $relationship instanceof BelongsToMany ? $relationship : null;
    }

    /**
     * The Eloquent relationship of the relation manager that owns this action (null
     * outside relation managers and for API relation managers, which have none).
     *
     * @return Relation<Model, Model, mixed>|null
     */
    public function getRelationManagerRelationship(): ?Relation
    {
        $context = $this->getContext();

        if (! $context instanceof RelationManager || $context instanceof ApiRelationManager) {
            return null;
        }

        return $context->getRelationship();
    }

    /**
     * The `withPivot()` columns a form may write (keys and timestamps excluded).
     *
     * @return array<int, string>
     */
    public function getPivotColumns(): array
    {
        $relationship = $this->getPivotRelationship();

        if ($relationship === null) {
            return [];
        }

        $excluded = [
            $relationship->getForeignPivotKeyName(),
            $relationship->getRelatedPivotKeyName(),
            $relationship->createdAt(),
            $relationship->updatedAt(),
            // The pivot row's own key, and anything that would shadow the related key.
            (new ($relationship->getPivotClass()))->getKeyName(),
            $relationship->getRelated()->getKeyName(),
        ];

        return array_values(array_diff(array_map('strval', $relationship->getPivotColumns()), $excluded));
    }

    /**
     * Split form data into the related model's attributes and the pivot attributes.
     *
     * @param  array<string, mixed>  $data
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    public function splitPivotData(array $data): array
    {
        $columns = $this->getPivotColumns();

        if ($columns === []) {
            return [$data, []];
        }

        return [Arr::except($data, $columns), Arr::only($data, $columns)];
    }

    /**
     * The pivot attributes of a related record, from its loaded pivot or the pivot table.
     *
     * @return array<string, mixed>
     */
    public function getPivotAttributes(Model $record): array
    {
        $relationship = $this->getPivotRelationship();
        $columns = $this->getPivotColumns();

        if ($relationship === null || $columns === []) {
            return [];
        }

        $accessor = $relationship->getPivotAccessor();
        $pivot = $record->relationLoaded($accessor) ? $record->getRelation($accessor) : null;

        if (! $pivot instanceof Model) {
            $related = (clone $relationship)
                ->where($relationship->getRelated()->getQualifiedKeyName(), $record->getKey())
                ->first();

            $pivot = $related?->relationLoaded($accessor) ? $related->getRelation($accessor) : null;
        }

        return $pivot instanceof Model ? Arr::only($pivot->attributesToArray(), $columns) : [];
    }
}
