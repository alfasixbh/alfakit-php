<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Schemas\Components\Concerns;

use AlfaSix\Alfakit\Forms\Components\Field;
use AlfaSix\Alfakit\Schemas\Schema;
use AlfaSix\Alfakit\Support\TenantScope;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Arr;

/**
 * `relationship()` on Group, Fieldset and Section: the children edit a single related
 * record (HasOne, MorphOne, BelongsTo or MorphTo) instead of attributes of the form's
 * record. A MorphTo creates a `relatedModel` record when the owner has none yet.
 *
 * The children's state lives under the relationship name (`profile.bio`). It is filled
 * from the related record, validated with the rest of the form, left out of the owner's
 * data, and saved by `Schema::saveRelationships()` after the owner: the related record is
 * updated, or created (and associated, for BelongsTo) when it doesn't exist yet. Nested
 * fields see the related record as `$record`, so `unique()` and relationship fields work
 * against the related table.
 */
trait EntanglesStateWithSingularRelationship
{
    protected string|Closure|null $relationshipName = null;

    protected bool|Closure|null $relationshipCondition = null;

    /** @var class-string<Model>|Closure|null */
    protected string|Closure|null $relationshipRelatedModel = null;

    protected ?Closure $mutateRelationshipDataBeforeFillUsing = null;

    protected ?Closure $mutateRelationshipDataBeforeCreateUsing = null;

    protected ?Closure $mutateRelationshipDataBeforeSaveUsing = null;

    protected ?Model $cachedRelatedRecord = null;

    /** Object id of the owner record the cached related record belongs to. */
    protected ?int $cachedRelatedOwner = null;

    /**
     * @param  bool|Closure|null  $condition  Save only when this holds; receives the
     *                                        children's `$state`. When it doesn't, an
     *                                        existing related record is deleted (HasOne,
     *                                        MorphOne) or dissociated (BelongsTo).
     * @param  class-string<Model>|Closure|null  $relatedModel  For a MorphTo: the model
     *                                                          the children edit (and create)
     *                                                          while the owner points at none.
     */
    public function relationship(string|Closure $name, bool|Closure|null $condition = null, string|Closure|null $relatedModel = null): static
    {
        $this->relationshipName = $name;
        $this->relationshipCondition = $condition;
        $this->relationshipRelatedModel = $relatedModel;

        return $this;
    }

    public function mutateRelationshipDataBeforeFillUsing(?Closure $callback): static
    {
        $this->mutateRelationshipDataBeforeFillUsing = $callback;

        return $this;
    }

    public function mutateRelationshipDataBeforeCreateUsing(?Closure $callback): static
    {
        $this->mutateRelationshipDataBeforeCreateUsing = $callback;

        return $this;
    }

    public function mutateRelationshipDataBeforeSaveUsing(?Closure $callback): static
    {
        $this->mutateRelationshipDataBeforeSaveUsing = $callback;

        return $this;
    }

    public function hasRelationship(): bool
    {
        return $this->relationshipName !== null;
    }

    public function getRelationshipName(): ?string
    {
        $name = $this->evaluate($this->relationshipName);

        return is_string($name) && $name !== '' ? $name : null;
    }

    /**
     * @return HasOne<Model, Model>|MorphOne<Model, Model>|BelongsTo<Model, Model>|null
     */
    public function getRelationship(): HasOne|MorphOne|BelongsTo|null
    {
        $name = $this->getRelationshipName();
        $owner = $this->getContainerRecordOrModel();

        if ($name === null || $owner === null || ! method_exists($owner, $name)) {
            return null;
        }

        $relationship = $owner->{$name}();

        return ($relationship instanceof HasOne || $relationship instanceof MorphOne || $relationship instanceof BelongsTo) ? $relationship : null;
    }

    /**
     * The related record, when the owner record exists and has one.
     */
    public function getRelatedRecord(): ?Model
    {
        $owner = $this->hasContainer() ? $this->getContainer()->getRecord() : null;

        if ($owner === null || ! $owner->exists) {
            return null;
        }

        if ($this->cachedRelatedOwner === spl_object_id($owner)) {
            return $this->cachedRelatedRecord;
        }

        $relationship = $this->getRelationship();
        $related = $relationship?->getResults();

        $this->cachedRelatedOwner = spl_object_id($owner);

        return $this->cachedRelatedRecord = $related instanceof Model ? $related : null;
    }

    protected function getContainerRecordOrModel(): ?Model
    {
        if (! $this->hasContainer()) {
            return null;
        }

        return $this->getContainer()->getRecord() ?? $this->getContainer()->getModelInstance();
    }

    /**
     * Children are a record scope of their own: the related model (and record, once it
     * exists).
     */
    public function getChildSchema(?string $statePath = null): Schema
    {
        $schema = parent::getChildSchema($statePath);

        if (! $this->hasRelationship() || ($relationship = $this->getRelationship()) === null) {
            return $schema;
        }

        $related = $this->getRelatedRecord();
        $model = $related !== null ? $related::class : $this->getRelatedModelClass($relationship);

        if ($model !== null) {
            $schema->model($model);
        }

        $schema->record($related);

        return $schema;
    }

    /**
     * The model the children edit when there's no related record yet. A MorphTo without
     * a type points at its owner's own model, so it needs `relationship(relatedModel:)`.
     *
     * @param  HasOne<Model, Model>|MorphOne<Model, Model>|BelongsTo<Model, Model>  $relationship
     * @return class-string<Model>|null
     */
    protected function getRelatedModelClass(HasOne|MorphOne|BelongsTo $relationship): ?string
    {
        if (! $relationship instanceof MorphTo) {
            return $relationship->getRelated()::class;
        }

        $model = $this->evaluate($this->relationshipRelatedModel);

        if (is_string($model) && is_subclass_of($model, Model::class)) {
            return $model;
        }

        $owner = $this->getContainerRecordOrModel();
        $type = $owner?->getAttribute($relationship->getMorphType());

        return filled($type) ? $relationship->getRelated()::class : null;
    }

    /**
     * Whether a Repeater (which hydrates and saves its items itself) sits between this
     * component and the root schema.
     */
    public function isNestedInRepeater(): bool
    {
        $container = $this->hasContainer() ? $this->getContainer() : null;

        while ($container !== null && ($parent = $container->getParentComponent()) !== null) {
            if ($parent->managesChildFieldState()) {
                return true;
            }

            $container = $parent->hasContainer() ? $parent->getContainer() : null;
        }

        return false;
    }

    /**
     * Put the related record's attributes under this component's state path. Called by
     * `Schema::fill()` when it fills from the owner record.
     */
    public function fillStateFromRelationship(): void
    {
        if (! $this->hasRelationship()) {
            return;
        }

        $path = (string) $this->getStatePath();
        $record = $this->getRelatedRecord();
        $root = $this->getContainer()->getRootSchema();

        if ($record === null) {
            $root->setStateAt($path, []);

            return;
        }

        $data = Arr::only($record->attributesToArray(), $this->getChildStateKeys());

        if ($this->mutateRelationshipDataBeforeFillUsing !== null) {
            $data = $this->evaluate($this->mutateRelationshipDataBeforeFillUsing, ['data' => $data, 'record' => $record], [Model::class => $record, $record::class => $record]) ?? $data;
        }

        $root->setStateAt($path, $data);
    }

    /**
     * Top-level keys (relative to this component) the children read, so unrelated
     * attributes of the related record never reach the browser.
     *
     * @return array<int, string>
     */
    protected function getChildStateKeys(): array
    {
        $prefix = $this->getStatePath().'.';
        $keys = [];

        foreach (array_keys($this->getChildSchema()->getFlatFields(withHidden: true)) as $path) {
            $path = (string) $path;

            if (str_starts_with($path, $prefix)) {
                $keys[] = explode('.', substr($path, strlen($prefix)))[0];
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * The dehydrated state of the fields this component saves (not those of nested
     * relationship components or repeaters, which save themselves).
     *
     * @return array<string, mixed>
     */
    public function getRelationshipData(): array
    {
        $schema = $this->getChildSchema();
        $prefix = $this->getStatePath().'.';
        $data = [];

        foreach ($schema->getFlatFields() as $path => $field) {
            $path = (string) $path;

            if (! str_starts_with($path, $prefix) || $field->isNestedInRepeater($schema) || $this->belongsToNestedRelationship($field) || ! $field->isDehydrated()) {
                continue;
            }

            $field->dehydrateStateInto($data, substr($path, strlen($prefix)));
        }

        return $data;
    }

    protected function belongsToNestedRelationship(Field $field): bool
    {
        $container = $field->hasContainer() ? $field->getContainer() : null;

        while ($container !== null && ($parent = $container->getParentComponent()) !== null && $parent !== $this) {
            if ($parent instanceof \AlfaSix\Alfakit\Schemas\Components\Group && $parent->hasRelationship()) {
                return true;
            }

            $container = $parent->hasContainer() ? $parent->getContainer() : null;
        }

        return false;
    }

    /**
     * Create, update or remove the related record. Called by `Schema::saveRelationships()`
     * once the owner record has been saved.
     */
    public function saveRelationship(): void
    {
        $relationship = $this->getRelationship();
        $owner = $this->getContainer()->getRecord();

        if ($relationship === null || $owner === null || ! $owner->exists || $this->isDisabled()) {
            return;
        }

        $data = $this->getRelationshipData();
        $related = $relationship->getResults();
        $related = $related instanceof Model ? $related : null;

        if ($this->relationshipCondition !== null && ! $this->evaluate($this->relationshipCondition, ['state' => $data, 'data' => $data])) {
            if ($related !== null && $relationship instanceof BelongsTo) {
                $relationship->dissociate();
                $owner->save();
            } elseif ($related !== null) {
                $related->delete();
            }

            $this->rememberRelatedRecord($owner, null);

            return;
        }

        if ($related !== null) {
            if ($this->mutateRelationshipDataBeforeSaveUsing !== null) {
                $data = $this->evaluate($this->mutateRelationshipDataBeforeSaveUsing, ['data' => $data, 'record' => $related], [Model::class => $related, $related::class => $related]) ?? $data;
            }

            $related->fill($data)->save();
            $this->rememberRelatedRecord($owner, $related);

            return;
        }

        if ($this->mutateRelationshipDataBeforeCreateUsing !== null) {
            $data = $this->evaluate($this->mutateRelationshipDataBeforeCreateUsing, ['data' => $data]) ?? $data;
        }

        if ($relationship instanceof MorphTo) {
            $model = $this->getRelatedModelClass($relationship);

            if ($model === null) {
                return; // Nothing tells which model to create.
            }

            $related = TenantScope::associate((new $model)->newInstance($data));
            $relationship->associate($related);
            $owner->save();
        } elseif ($relationship instanceof BelongsTo) {
            $related = TenantScope::associate($relationship->getRelated()->newInstance($data));
            $relationship->associate($related);
            $owner->save();
        } else {
            // `make()` sets the foreign key (and morph type) to the owner.
            $related = TenantScope::associate($relationship->make($data));
        }

        $this->rememberRelatedRecord($owner, $related);
    }

    protected function rememberRelatedRecord(Model $owner, ?Model $related): void
    {
        $this->cachedRelatedOwner = spl_object_id($owner);
        $this->cachedRelatedRecord = $related;
    }
}
