<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Resources\Concerns;

use AlfaSix\Alfakit\Resources\ParentRecords;
use AlfaSix\Alfakit\Resources\ParentResourceRegistration;
use AlfaSix\Alfakit\Resources\Resource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOneOrMany;
use Illuminate\Database\Eloquent\Relations\MorphOneOrMany;

/**
 * Nested resources: a resource whose pages live under a record of another resource
 * (`/posts/{post}/comments/{record}/edit`). Its queries are scoped to that parent record,
 * new records are created through the parent's relationship, and its breadcrumbs start
 * with the parent.
 *
 * ```php
 * class CommentResource extends Resource
 * {
 *     protected static ?string $parentResource = PostResource::class;
 * }
 * ```
 *
 * Override `getParentResourceRegistration()` to name the relationships:
 * `return PostResource::asParent()->relationship('comments')->inverseRelationship('post');`.
 */
trait IsNestedResource
{
    /** @var class-string<resource>|null */
    protected static ?string $parentResource = null;

    public static function asParent(): ParentResourceRegistration
    {
        return ParentResourceRegistration::make(static::class);
    }

    public static function getParentResourceRegistration(): ?ParentResourceRegistration
    {
        return static::$parentResource === null ? null : static::$parentResource::asParent();
    }

    /**
     * @return class-string<resource>|null
     */
    public static function getParentResource(): ?string
    {
        return static::getParentResourceRegistration()?->getParentResource();
    }

    public static function isNested(): bool
    {
        return static::getParentResourceRegistration() !== null;
    }

    /**
     * The parent record of the page being served (null outside nested pages).
     */
    public static function getParentRecord(): ?Model
    {
        $parent = static::getParentResource();

        return $parent === null ? null : ParentRecords::get($parent);
    }

    /**
     * The URL prefix of the resource's routes: its slug, after the parent's record segment
     * for nested resources (`posts/{post}/comments`).
     */
    /**
     * Where the nested records are listed under the parent record: the parent's relation
     * page (`ParentResourceRegistration::page()`, by default the kebab-cased relationship
     * name), else the parent record's view, edit or the parent's index page. Used for the
     * nested resource's breadcrumbs and redirects when it has no index page of its own.
     */
    public static function getParentRelationPageUrl(): ?string
    {
        $registration = static::getParentResourceRegistration();
        $parent = static::getParentRecord();

        if ($registration === null) {
            return null;
        }

        $parentResource = $registration->getParentResource();
        $page = $registration->getPageName(static::class);

        if ($parent !== null) {
            foreach ([$page, 'view', 'edit'] as $name) {
                if ($parentResource::hasPage($name)) {
                    return $parentResource::getUrl($name, ['record' => $parent]);
                }
            }
        }

        return $parentResource::hasPage('index') ? $parentResource::getUrl() : null;
    }

    public static function getRoutePrefix(): string
    {
        $registration = static::getParentResourceRegistration();

        if ($registration === null) {
            $cluster = method_exists(static::class, 'getClusterRoutePrefix') ? (string) static::getClusterRoutePrefix() : '';

            return $cluster.static::getSlug();
        }

        $parent = $registration->getParentResource();

        return $parent::getRoutePrefix().'/{'.$registration->getRouteParameterName().'}/'.static::getSlug();
    }

    /**
     * Fill in the parent route parameters a nested resource URL needs: from the record's
     * inverse relationship, else from the parent record of the current page.
     *
     * @param  array<string, mixed>  $parameters
     * @return array<string, mixed>
     */
    public static function fillParentRouteParameters(array $parameters): array
    {
        $registration = static::getParentResourceRegistration();

        if ($registration === null) {
            return $parameters;
        }

        $name = $registration->getRouteParameterName();
        $parentResource = $registration->getParentResource();
        $parent = $parameters[$name] ?? null;

        if ($parent === null && ($record = $parameters['record'] ?? null) instanceof Model) {
            $inverse = $registration->getInverseRelationshipName();

            if (method_exists($record, $inverse)) {
                $related = $record->{$inverse}()->getResults();
                $parent = $related instanceof Model ? $related : null;
            }

            // A BelongsToMany child can belong to several parents: the current one wins.
            $current = ParentRecords::get($parentResource);

            if ($current !== null && ! $parent instanceof Model) {
                $parent = $current;
            }
        }

        $parent ??= ParentRecords::get($parentResource);

        // Grandparents (a nested parent) come from the parent record the same way.
        $ancestors = $parentResource::fillParentRouteParameters($parent instanceof Model ? ['record' => $parent] : []);
        unset($ancestors['record']);
        $parameters = [...$ancestors, ...$parameters];

        if ($parent instanceof Model) {
            $parameters[$name] = $parent->getAttribute($parentResource::getRecordRouteKeyName() ?? $parent->getRouteKeyName());
        }

        return $parameters;
    }

    /**
     * Limit a nested resource's query to the current parent record.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public static function scopeEloquentQueryToParent(Builder $query): Builder
    {
        $registration = static::getParentResourceRegistration();
        $parent = static::getParentRecord();

        if ($registration === null || $parent === null) {
            return $query;
        }

        $relationship = $parent->{$registration->getRelationshipName(static::class)}();

        if ($relationship instanceof HasOneOrMany) {
            $query->where($query->qualifyColumn($relationship->getForeignKeyName()), $relationship->getParentKey());

            if ($relationship instanceof MorphOneOrMany) {
                $query->where($query->qualifyColumn($relationship->getMorphType()), $relationship->getMorphClass());
            }

            return $query;
        }

        $inverse = $registration->getInverseRelationshipName();
        $model = $query->getModel();

        if (method_exists($model, $inverse) && ($inverseRelationship = $model->{$inverse}()) instanceof BelongsTo) {
            return $query->where($inverseRelationship->getQualifiedForeignKeyName(), $parent->getKey());
        }

        return $query->whereHas($inverse, static fn (Builder $q) => $q->whereKey($parent->getKey()));
    }

    /**
     * Point a new record at the current parent before it is saved (foreign key, morph
     * type). Returns true when it still has to be attached after saving (BelongsToMany).
     */
    public static function associateRecordWithParent(Model $record): bool
    {
        $registration = static::getParentResourceRegistration();
        $parent = static::getParentRecord();

        if ($registration === null || $parent === null) {
            return false;
        }

        $relationship = $parent->{$registration->getRelationshipName(static::class)}();

        if ($relationship instanceof HasOneOrMany) {
            $record->setAttribute($relationship->getForeignKeyName(), $relationship->getParentKey());

            if ($relationship instanceof MorphOneOrMany) {
                $record->setAttribute($relationship->getMorphType(), $relationship->getMorphClass());
            }

            return false;
        }

        return $relationship instanceof BelongsToMany;
    }

    /**
     * Attach a saved record to the current parent (BelongsToMany children).
     */
    public static function attachRecordToParent(Model $record): void
    {
        $registration = static::getParentResourceRegistration();
        $parent = static::getParentRecord();

        if ($registration === null || $parent === null) {
            return;
        }

        $relationship = $parent->{$registration->getRelationshipName(static::class)}();

        if ($relationship instanceof BelongsToMany) {
            $relationship->syncWithoutDetaching([$record->getKey()]);
        }
    }
}
