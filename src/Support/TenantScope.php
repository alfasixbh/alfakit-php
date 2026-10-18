<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\Rules\Unique;

/**
 * Limits queries and database rules to the current tenant. Models without the panel's
 * ownership relationship (countries, currencies, …) are shared and left untouched.
 */
final class TenantScope
{
    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public static function apply(Builder $query): Builder
    {
        $tenant = alfakit()->getTenant();

        if ($tenant === null) {
            return $query;
        }

        $model = $query->getModel();
        $name = alfakit()->getCurrentOrDefaultPanel()->getTenantOwnershipRelationshipName();

        if ($model instanceof $tenant) {
            return $query->whereKey($tenant->getKey());
        }

        if (! method_exists($model, $name)) {
            return $query;
        }

        $relationship = $model->{$name}();

        if ($relationship instanceof BelongsTo) {
            return $query->where($relationship->getQualifiedForeignKeyName(), $tenant->getKey());
        }

        return $query->whereHas($name, static fn (Builder $q) => $q->whereKey($tenant->getKey()));
    }

    /**
     * Save a new record, owned by the current tenant when its model belongs to tenants
     * (associated through a BelongsTo, attached through a BelongsToMany), so records
     * created outside a resource page (a Select's "create option" modal, a relationship
     * group) never end up without a tenant.
     *
     * @template TModel of Model
     *
     * @param  TModel  $record
     * @return TModel
     */
    public static function associate(Model $record): Model
    {
        $tenant = alfakit()->getTenant();
        $name = $tenant === null ? null : alfakit()->getCurrentOrDefaultPanel()->getTenantOwnershipRelationshipName();

        if ($tenant === null || $name === null || $record instanceof $tenant || ! method_exists($record, $name)) {
            $record->save();

            return $record;
        }

        $relationship = $record->{$name}();

        if ($relationship instanceof BelongsTo) {
            $relationship->associate($tenant);
            $record->save();

            return $record;
        }

        $record->save();

        if ($relationship instanceof \Illuminate\Database\Eloquent\Relations\BelongsToMany) {
            $relationship->attach($tenant);
        }

        return $record;
    }

    /**
     * Add the tenant column to an `exists`/`unique` rule on a tenant-owned model's table.
     */
    public static function applyToRule(Exists|Unique $rule, ?Model $model, string $table): Exists|Unique
    {
        $tenant = alfakit()->getTenant();

        if ($tenant === null || $model === null || $model->getTable() !== $table) {
            return $rule;
        }

        $name = alfakit()->getCurrentOrDefaultPanel()->getTenantOwnershipRelationshipName();

        if (! method_exists($model, $name)) {
            return $rule;
        }

        $relationship = $model->{$name}();

        if ($relationship instanceof BelongsTo) {
            $rule->where($relationship->getForeignKeyName(), $tenant->getKey());
        }

        return $rule;
    }
}
