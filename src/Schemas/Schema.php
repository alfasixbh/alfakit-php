<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Schemas;

use AlfaSix\Alfakit\Forms\Components\DatePicker;
use AlfaSix\Alfakit\Forms\Components\Field;
use AlfaSix\Alfakit\Forms\Components\Repeater;
use AlfaSix\Alfakit\Schemas\Components\Component;
use AlfaSix\Alfakit\Support\Concerns\Configurable;
use AlfaSix\Alfakit\Support\Concerns\EvaluatesClosures;
use AlfaSix\Alfakit\Support\SchemaContext;
use AlfaSix\Alfakit\Support\Serializable;
use Closure;
use DateTimeInterface;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Traits\Macroable;
use Illuminate\Validation\ValidationException;
use JsonException;

/**
 * A container of components with shared state, record and operation context.
 *
 * Used for forms, infolists, action modals and page content alike. Only the root
 * schema owns state; nested schemas (children of layout components) delegate to it.
 */
class Schema
{
    use Concerns\HasRootHeadingLevel;
    use Configurable;
    use EvaluatesClosures;
    use Macroable;

    /**
     * Reserved state key that carries read-only values the server wrote (see
     * `getChangedReadOnlyState()`); the browser sends it back untouched.
     */
    public const READ_ONLY_STATE_KEY = '__alfakitReadOnly';

    /** @var array<Component>|Closure */
    protected array|Closure $components = [];

    protected ?Component $parentComponent = null;

    /** The page, relation manager or other object this schema is rendered for (`hiddenOn(RelationManager::class)`). */
    protected ?object $owner = null;

    protected ?string $statePath = null;

    /** @var class-string<Model>|null */
    protected ?string $model = null;

    protected ?Model $record = null;

    protected ?string $operation = null;

    protected bool $forRecordCopy = false;

    /** @var array<string, mixed> */
    protected array $state = [];

    /** @var array<string, bool> */
    protected array $dirtyPaths = [];

    /**
     * Bumped whenever the root state changes, so layout components whose children come
     * from a closure rebuild them against the current state (see `getChildSchema()`).
     */
    protected int $stateVersion = 0;

    /**
     * The server-filled state as it was before `applyClientState()`, so fields can check
     * what the browser changed (e.g. repeater items added while `addable(false)`).
     *
     * @var array<string, mixed>|null
     */
    protected ?array $serverState = null;

    protected bool $isPrepared = false;

    /** @var array<string, string>|Closure */
    protected array|Closure $validationMessages = [];

    /** @var array<string, int|null>|int|Closure|null */
    protected array|int|Closure|null $columns = null;

    protected bool|Closure|null $hasInlineLabel = null;

    public static function make(): static
    {
        $static = app(static::class);
        $static->configure();

        return $static;
    }

    /**
     * A schema with fields inferred from a model's casts and table columns (see
     * `ModelSchema`), bound to that model. Tweak the result like any schema:
     *
     * ```php
     * $schema = Schema::fromModel(Post::class, except: ['slug']);
     * $schema->getComponent('title')?->required()->maxLength(120);
     * $schema->components([...$schema->getComponents(), Toggle::make('featured')]);
     * ```
     *
     * @param  class-string<Model>|Model  $model
     * @param  array<int, string>  $except  Attributes to leave out
     * @param  array<int, string>|null  $only  Attributes to include, in this order
     */
    public static function fromModel(string|Model $model, array $except = [], ?array $only = null): static
    {
        return static::make()
            ->model($model)
            ->components(ModelSchema::fields($model, $except, $only));
    }

    // ---------------------------------------------------------------- Components

    /**
     * @param  array<Component>|Closure  $components
     */
    public function components(array|Closure $components): static
    {
        $this->components = $components;
        $this->isPrepared = false;

        if (is_array($components)) {
            foreach ($components as $component) {
                $component->container($this);
            }
        }

        return $this;
    }

    /**
     * @param  array<Component>|Closure  $components
     */
    public function schema(array|Closure $components): static
    {
        return $this->components($components);
    }

    /**
     * @return array<Component>
     */
    public function getComponents(bool $withHidden = false): array
    {
        $components = array_values(array_map(function (Component $component): Component {
            $component->container($this);

            return $component;
        }, $this->evaluate($this->components) ?? []));

        if (! $this->isPrepared) {
            foreach ($components as $component) {
                $component->prepare();
            }

            $this->isPrepared = true;
        }

        if ($withHidden) {
            return $components;
        }

        return array_values(array_filter($components, static fn (Component $component): bool => $component->isVisible()));
    }

    public function getComponent(string|Closure $findComponentUsing, bool $withHidden = false): ?Component
    {
        $callback = is_string($findComponentUsing)
            ? static fn (Component $component): bool => $component->getKey() === $findComponentUsing
            : $findComponentUsing;

        return collect($this->getFlatComponents($withHidden))->first($callback);
    }

    /**
     * Every component in the tree, depth-first, including nested layout children.
     *
     * @return array<Component>
     */
    public function getFlatComponents(bool $withHidden = false): array
    {
        $components = [];

        foreach ($this->getComponents($withHidden) as $component) {
            $components[] = $component;

            if ($component->hasChildComponents()) {
                array_push($components, ...$component->getChildSchema()->getFlatComponents($withHidden));
            }
        }

        return $components;
    }

    /**
     * Every field in the tree, keyed by absolute state path.
     *
     * @return array<string, Field>
     */
    public function getFlatFields(bool $withHidden = false): array
    {
        $fields = [];

        foreach ($this->getFlatComponents($withHidden) as $component) {
            if ($component instanceof Field) {
                $fields[$component->getStatePath()] = $component;
            }
        }

        return $fields;
    }

    /**
     * Grid columns for the root level of the schema.
     *
     * @param  array<string, int|null>|int|Closure|null  $columns
     */
    public function columns(array|int|Closure|null $columns = 2): static
    {
        $this->columns = $columns;

        return $this;
    }

    /**
     * @return array<string, int|null>
     */
    public function getColumns(): array
    {
        $columns = $this->evaluate($this->columns);

        if ($columns === null) {
            return ['default' => 1, 'lg' => 2];
        }

        return is_array($columns) ? $columns : ['default' => 1, 'lg' => (int) $columns];
    }

    /**
     * Show the labels of every field and entry in this schema beside their controls (from
     * the `sm` breakpoint up). Nested layouts and components can override it.
     */
    public function inlineLabel(bool|Closure|null $condition = true): static
    {
        $this->hasInlineLabel = $condition;

        return $this;
    }

    /**
     * Whether components in this schema show inline labels: the schema's own setting, else
     * the setting of the layout component that owns it (which cascades further up).
     */
    public function hasInlineLabel(): bool
    {
        $condition = $this->evaluate($this->hasInlineLabel);

        if ($condition !== null) {
            return (bool) $condition;
        }

        return $this->parentComponent?->hasInlineLabel() ?? false;
    }

    // ---------------------------------------------------------------- Hierarchy

    public function parentComponent(?Component $component): static
    {
        $this->parentComponent = $component;

        return $this;
    }

    public function getParentComponent(): ?Component
    {
        return $this->parentComponent;
    }

    public function owner(?object $owner): static
    {
        $this->owner = $owner;

        return $this;
    }

    /**
     * The owner of the root schema: nested schemas (sections, repeater items) share it.
     */
    public function getOwner(): ?object
    {
        return $this->getRootSchema()->owner;
    }

    public function getRootSchema(): static
    {
        $schema = $this;

        while ($parent = $schema->getParentComponent()) {
            if (! $parent->hasContainer()) {
                break;
            }

            /** @var static $schema */
            $schema = $parent->getContainer();
        }

        return $schema;
    }

    public function isRoot(): bool
    {
        return $this->parentComponent === null;
    }

    // ---------------------------------------------------------------- Context

    public function statePath(?string $path): static
    {
        $this->statePath = $path;

        return $this;
    }

    public function getStatePath(): ?string
    {
        if ($this->statePath !== null) {
            return $this->statePath;
        }

        if ($this->parentComponent !== null && $this->parentComponent->hasContainer()) {
            return $this->parentComponent->getContainer()->getStatePath();
        }

        return null;
    }

    /**
     * @param  class-string<Model>|Model|null  $model
     */
    public function model(string|Model|null $model): static
    {
        if ($model instanceof Model) {
            return $this->record($model);
        }

        $this->model = $model;

        return $this;
    }

    public function record(?Model $record): static
    {
        $this->record = $record;

        if ($record !== null) {
            $this->model = $record::class;
        }

        return $this;
    }

    public function operation(?string $operation): static
    {
        $this->operation = $operation;

        return $this;
    }

    /**
     * The form fills a copy of its record (`ReplicateAction`): `unique()` rules don't ignore
     * the record, since the copy is a new row (W-S5-06).
     *
     * @internal
     */
    public function forRecordCopy(bool $condition = true): static
    {
        $this->forRecordCopy = $condition;

        return $this;
    }

    /** @internal */
    public function isForRecordCopy(): bool
    {
        return $this->getRootSchema()->forRecordCopy;
    }

    /**
     * @return class-string<Model>|null
     */
    public function getModel(): ?string
    {
        if ($this->model !== null) {
            return $this->model;
        }

        if ($this->record !== null) {
            return $this->record::class;
        }

        return $this->getParentSchema()?->getModel();
    }

    public function getModelInstance(): ?Model
    {
        if ($record = $this->getRecord()) {
            return $record;
        }

        $model = $this->getModel();

        return $model !== null ? app($model) : null;
    }

    public function getRecord(): ?Model
    {
        if ($this->record !== null) {
            return $this->record;
        }

        // A schema bound to its own model (a relationship repeater item) is a record scope of
        // its own: it must not inherit the parent's record, or `unique()`, `exists()` and
        // relationship fields would act on the parent's table.
        if ($this->model !== null) {
            return null;
        }

        return $this->getParentSchema()?->getRecord();
    }

    /**
     * The schema containing this schema's parent component (null for the root).
     */
    protected function getParentSchema(): ?self
    {
        $parent = $this->parentComponent;

        return $parent !== null && $parent->hasContainer() ? $parent->getContainer() : null;
    }

    public function getOperation(): ?string
    {
        if ($this->operation !== null) {
            return $this->operation;
        }

        return $this->isRoot() ? null : $this->getRootSchema()->getOperation();
    }

    // ---------------------------------------------------------------- State

    /**
     * Hydrate the schema. With no argument, fields are filled with their defaults
     * (create) or from the record (edit/view).
     *
     * @param  array<string, mixed>|null  $state
     */
    public function fill(?array $state = null): static
    {
        $root = $this->getRootSchema();
        $fromRecord = false;

        if ($state === null) {
            $record = $root->getRecord();

            if ($record !== null) {
                $fromRecord = true;

                // Only expose the attributes that fields actually read, so unrelated
                // record data never reaches the frontend.
                $topLevelKeys = array_values(array_unique(array_map(
                    static fn (string $path): string => explode('.', $path)[0],
                    array_keys($root->getFlatFields(withHidden: true)),
                )));

                $state = Arr::only($record->attributesToArray(), $topLevelKeys);
            }
        }

        $root->state = $state ?? [];
        $root->dirtyPaths = [];
        $root->stateVersion++;

        // Layout components bound to a relationship (`Section::make()->relationship()`)
        // fill their children's state from the related record, outer ones first.
        if ($fromRecord) {
            foreach ($root->getRelationshipComponents(withHidden: true) as $component) {
                $component->fillStateFromRelationship();
            }
        }

        foreach ($root->getFlatFields(withHidden: true) as $field) {
            if ($field->isNestedInRepeater($root)) {
                continue;
            }

            $field->hydrateState($state === null);
        }

        return $this;
    }

    /**
     * The state as held in memory, before dehydration hooks.
     *
     * @return array<string, mixed>
     */
    public function getRawState(): array
    {
        return $this->getRootSchema()->state;
    }

    /**
     * @param  array<string, mixed>  $state
     */
    public function rawState(array $state): static
    {
        $root = $this->getRootSchema();
        $root->state = $state;
        $root->stateVersion++;

        return $this;
    }

    /**
     * Apply state sent by the browser on top of the server-filled state. Hidden fields
     * keep their server value: the browser never received it and can't change it.
     *
     * @param  array<string, mixed>  $state
     */
    public function applyClientState(array $state): static
    {
        $root = $this->getRootSchema();
        $server = $root->state;
        $root->serverState = $server;
        // Hidden with the server's state: such fields were not sent to the browser.
        $hiddenOnServer = $root->getHiddenStatePaths();
        $root->state = $state;
        $root->stateVersion++;

        $readOnly = $root->decodeReadOnlyState($state[self::READ_ONLY_STATE_KEY] ?? null);
        unset($root->state[self::READ_ONLY_STATE_KEY]);

        foreach ($root->getHiddenStatePaths() as $path) {
            if (Arr::has($server, $path)) {
                Arr::set($root->state, $path, Arr::get($server, $path));
            } else {
                Arr::forget($root->state, $path);
            }
        }

        // A field the browser's state shows now (a live toggle revealed it) but that was
        // hidden when the form was sent has no value there: it keeps the server's (its
        // default, or the record's) instead of starting empty.
        $revealed = false;

        foreach ($root->getFlatFields() as $path => $field) {
            $path = (string) $path;

            if (in_array($path, $hiddenOnServer, true) && ! $field->isNestedInRepeater($root) && ! Arr::has($state, $path) && Arr::has($server, $path)) {
                Arr::set($root->state, $path, Arr::get($server, $path));
                $revealed = true;
            }
        }

        if ($revealed) {
            $root->stateVersion++;
        }

        // Read-only fields keep the server's value (the record's, or one the server wrote
        // with `$set()` during an earlier live refresh), never the browser's.
        foreach ($root->getFlatFields() as $path => $field) {
            $path = (string) $path;

            if ($field->isNestedInRepeater($root)) {
                continue;
            }

            // Repeaters forward this to the fields of each item.
            $field->normalizeClientState();

            $baseline = Arr::has($readOnly, $path) ? $readOnly : $server;

            if ($field->isReadOnly()) {
                if (Arr::has($baseline, $path)) {
                    Arr::set($root->state, $path, Arr::get($baseline, $path));
                } else {
                    Arr::forget($root->state, $path);
                }
            } elseif ($field instanceof Repeater) {
                $field->restoreReadOnlyItemState(Arr::get($baseline, $path));
            }
        }

        // Children generated from the browser's state before the server values above were restored are rebuilt.
        $root->stateVersion++;

        return $this;
    }

    /**
     * Values of read-only fields (and of repeaters holding read-only item fields) that
     * differ from the server-filled state, i.e. values the server wrote during this
     * request. They travel to the browser encrypted under a reserved state key, so the
     * next request restores them instead of the record's.
     *
     * @return array<string, mixed>
     */
    protected function getChangedReadOnlyState(): array
    {
        $root = $this->getRootSchema();

        if ($root->serverState === null) {
            return [];
        }

        $changed = [];

        foreach ($root->getFlatFields() as $path => $field) {
            $path = (string) $path;

            if ($field->isNestedInRepeater($root) || ! ($field->isReadOnly() || ($field instanceof Repeater && $field->hasReadOnlyItemFields()))) {
                continue;
            }

            $value = Arr::get($root->state, $path);

            if (! Arr::has($root->serverState, $path) || Arr::get($root->serverState, $path) !== $value) {
                Arr::set($changed, $path, $value);
            }
        }

        return $changed;
    }

    /**
     * @return array<string, mixed>
     */
    protected function decodeReadOnlyState(mixed $token): array
    {
        if (! is_string($token) || $token === '') {
            return [];
        }

        try {
            $payload = json_decode(Crypt::decryptString($token), true, flags: JSON_THROW_ON_ERROR);
        } catch (DecryptException|JsonException) {
            return [];
        }

        if (! is_array($payload) || ($payload['scope'] ?? null) !== $this->getReadOnlyStateScope() || ! is_array($payload['state'] ?? null)) {
            return [];
        }

        return $payload['state'];
    }

    /**
     * Binds read-only state tokens to the record and user they were issued for.
     */
    protected function getReadOnlyStateScope(): string
    {
        $record = $this->getRecord();

        return hash('sha256', implode('|', [
            $record === null ? '' : $record->getMorphClass().':'.(string) $record->getKey(),
            SchemaContext::currentUser() ?? '',
        ]));
    }

    /**
     * State paths of fields that are hidden (directly or by a hidden parent), excluding
     * fields managed by a repeater.
     *
     * @return array<int, string>
     */
    public function getHiddenStatePaths(): array
    {
        $root = $this->getRootSchema();
        $visible = $root->getFlatFields();
        $paths = [];

        foreach ($root->getFlatFields(withHidden: true) as $path => $field) {
            if (! array_key_exists($path, $visible) && ! $field->isNestedInRepeater($root)) {
                $paths[] = (string) $path;
            }
        }

        return $paths;
    }

    /**
     * The server-filled state at `$path` before the browser's state was applied, or null
     * when no client state has been applied (or the path didn't exist on the server).
     */
    public function getServerStateAt(string $path): mixed
    {
        $server = $this->getRootSchema()->serverState;

        if ($server === null) {
            return null;
        }

        return $path === '' ? $server : Arr::get($server, $path);
    }

    public function getStateAt(string $path): mixed
    {
        $root = $this->getRootSchema();

        return $path === '' ? $root->state : Arr::get($root->state, $path);
    }

    public function setStateAt(string $path, mixed $value): static
    {
        $root = $this->getRootSchema();

        if ($path === '') {
            $root->state = is_array($value) ? $value : [];
        } else {
            Arr::set($root->state, $path, $value);
        }

        $root->stateVersion++;

        return $this;
    }

    /**
     * A counter that changes whenever the root state changes (fill, client state, `$set()`).
     */
    public function getStateVersion(): int
    {
        return $this->getRootSchema()->stateVersion;
    }

    public function markAsDirty(string $path): static
    {
        $this->getRootSchema()->dirtyPaths[$path] = true;

        return $this;
    }

    /**
     * @return array<string>
     */
    public function getDirtyPaths(): array
    {
        return array_keys($this->getRootSchema()->dirtyPaths);
    }

    /**
     * Run `afterStateUpdated` hooks for the field at the given path, passing `$old` (the
     * state before the update) to the hooks.
     */
    public function callAfterStateUpdated(string $path, mixed $old = null): static
    {
        $this->getRootSchema()->getFieldByStatePath($path)?->callAfterStateUpdated($old);

        return $this;
    }

    /**
     * Locate a field by absolute state path, descending into Repeater items
     * (e.g. `comments.2.body`).
     */
    public function getFieldByStatePath(string $path): ?Field
    {
        foreach ($this->getFlatFields(withHidden: true) as $fieldPath => $field) {
            if ($field->isNestedInRepeater($this)) {
                continue;
            }

            if ($fieldPath === $path) {
                return $field;
            }

            if ($field->managesChildFieldState() && str_starts_with($path, $fieldPath.'.')) {
                return $field->getNestedFieldByStatePath($path);
            }
        }

        return null;
    }

    /**
     * Dehydrated state, ready for persistence. Hidden and `dehydrated(false)` fields are
     * excluded; `dehydrateStateUsing` / `mutateDehydratedStateUsing` hooks are applied.
     *
     * @return array<string, mixed>
     */
    public function getState(bool $shouldCallHooksBefore = true): array
    {
        $root = $this->getRootSchema();
        $state = [];

        if ($shouldCallHooksBefore) {
            foreach ($root->getFlatFields() as $field) {
                if (! $field->isNestedInRepeater($root)) {
                    $field->callBeforeStateDehydrated();
                }
            }
        }

        foreach ($root->getFlatFields(withHidden: true) as $path => $field) {
            if (! $field->isDehydrated()) {
                continue;
            }

            if ($field->isNestedInRepeater($root)) {
                continue; // Repeaters dehydrate their own children.
            }

            $field->dehydrateStateInto($state, (string) $path);
        }

        // Relationship components save their children's state to the related record.
        foreach ($root->getRelationshipComponents(withHidden: true) as $component) {
            Arr::forget($state, (string) $component->getStatePath());
        }

        return $state;
    }

    /**
     * Layout components bound to a singular relationship, depth-first, excluding those
     * inside repeaters (which manage their items themselves).
     *
     * @return array<Components\Group>
     */
    public function getRelationshipComponents(bool $withHidden = false): array
    {
        return array_values(array_filter(
            $this->getFlatComponents($withHidden),
            static fn (Component $component): bool => $component instanceof Components\Group && $component->hasRelationship() && ! $component->isNestedInRepeater(),
        ));
    }

    /**
     * Validate the current state and return the dehydrated, validated data.
     *
     *
     * @throws ValidationException
     *
     * @return array<string, mixed>
     */
    public function validate(): array
    {
        $validator = $this->getValidator();

        $validator->validate();

        return $this->getState();
    }

    /**
     * Validate only the fields at the given state paths (and everything nested under them),
     * e.g. the fields of one wizard step.
     *
     * @param  array<int, string>  $paths
     *
     * @throws ValidationException
     */
    public function validateOnly(array $paths): void
    {
        $this->getValidator($paths)->validate();
    }

    /**
     * @param  array<int, string>|null  $only  State paths to limit the rules to (nested paths included)
     */
    public function getValidator(?array $only = null): ValidatorContract
    {
        $root = $this->getRootSchema();
        $rules = [];
        $attributes = [];
        $messages = $this->evaluate($root->validationMessages) ?? [];
        $data = $root->state;

        foreach ($root->getFlatFields() as $path => $field) {
            if ($field->isNestedInRepeater($root)) {
                continue;
            }

            // `trim()`, `stripCharacters()`, `mutateStateForValidationUsing()`: the validator
            // sees the transformed value, the form state keeps what the user typed.
            foreach ($field->getValidationStateByPath() as $statePath => $value) {
                if (Arr::has($data, $statePath)) {
                    Arr::set($data, $statePath, $value);
                }
            }

            if ($only !== null && ! $this->isPathAmong((string) $path, $only)) {
                continue;
            }

            // Disabled fields keep their server value, so there's nothing to validate.
            if ($field->isDisabled() && ! $field->isDehydratedWhenDisabled()) {
                continue;
            }

            foreach ($field->getValidationRulesByPath() as $path => $fieldRules) {
                if ($fieldRules === []) {
                    continue;
                }

                $rules[$path] = $fieldRules;
            }

            $attributes = [...$attributes, ...$field->getValidationAttributesByPath()];

            foreach ($field->getValidationMessagesByPath() as $key => $message) {
                $messages[$key] = $message;
            }
        }

        return Validator::make($data, $rules, $messages, $attributes);
    }

    /**
     * @param  array<int, string>  $paths
     */
    protected function isPathAmong(string $path, array $paths): bool
    {
        foreach ($paths as $candidate) {
            if ($path === $candidate || str_starts_with($path, $candidate.'.')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, string>|Closure  $messages
     */
    public function validationMessages(array|Closure $messages): static
    {
        $this->validationMessages = $messages;

        return $this;
    }

    /**
     * Persist relationship-based fields (BelongsToMany selects, HasMany repeaters…) after
     * the owning record has been saved.
     */
    public function saveRelationships(): static
    {
        // Related records first, so fields inside them (a BelongsToMany select in a
        // relationship section) find their record.
        foreach ($this->getRelationshipComponents() as $component) {
            $component->saveRelationship();
        }

        // Hidden fields are visited too: `saveRelationshipsWhenHidden()` opts them in, and
        // `saved(false)` opts any field out (`shouldSaveRelationships()`).
        foreach ($this->getFlatFields(withHidden: true) as $field) {
            if ($field->isNestedInRepeater($this) || ! $field->shouldSaveRelationships()) {
                continue;
            }

            if ($field->isDisabled() && ! $field->isDehydratedWhenDisabled()) {
                continue;
            }

            $field->saveRelationships();
        }

        return $this;
    }

    /**
     * Load relationship-based field state from the record (the inverse of `saveRelationships`).
     */
    public function loadStateFromRelationships(): static
    {
        foreach ($this->getFlatFields(withHidden: true) as $field) {
            if (! $field->isNestedInRepeater($this)) {
                $field->loadStateFromRelationships();
            }
        }

        return $this;
    }

    // ---------------------------------------------------------------- Serialisation

    /**
     * @return array<int, array<string, mixed>>
     */
    public function toSchema(): array
    {
        return array_map(static fn (Component $component): array => $component->toSchema(), $this->getComponents());
    }

    /**
     * Full payload for a form renderer: component tree plus current state and the
     * list of live paths that require a server round-trip on change.
     *
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        $root = $this->getRootSchema();
        $state = $root->getRawState();

        // Hidden fields' values (e.g. an admin-only note) never reach the browser.
        foreach ($root->getHiddenStatePaths() as $path) {
            Arr::forget($state, $path);
        }

        // Dates set after hydration, in the pickers' own format (W-S1-05).
        foreach ($root->getFlatFields() as $path => $field) {
            if ($field instanceof DatePicker && ($value = Arr::get($state, $path)) instanceof DateTimeInterface) {
                Arr::set($state, $path, $field->formatStateForFrontend($value));
            }
        }

        if (($readOnly = $root->getChangedReadOnlyState()) !== []) {
            try {
                $state[self::READ_ONLY_STATE_KEY] = Crypt::encryptString(json_encode(
                    ['scope' => $root->getReadOnlyStateScope(), 'state' => $readOnly],
                    JSON_THROW_ON_ERROR,
                ));
            } catch (JsonException) {
                // Values that can't be encoded fall back to the record's on the next request.
            }
        }

        $components = $root->toSchema();

        return Serializable::compact([
            'components' => $components,
            'state' => $state,
            'columns' => $root->getColumns(),
            // Lets live refreshes and field actions answer with only what changed.
            'fingerprints' => SchemaDiff::isReactive($components) ? SchemaDiff::fingerprints($components) : null,
        ]);
    }

    protected function getEvaluationIdentifier(): string
    {
        return 'schema';
    }

    /**
     * @return array<mixed>
     */
    protected function resolveDefaultClosureDependencyForEvaluationByName(string $parameterName): array
    {
        return match ($parameterName) {
            'model' => [$this->getModel()],
            'operation', 'context' => [$this->getOperation()],
            'record' => [$this->getRecord()],
            'state' => [$this->getRawState()],
            default => [],
        };
    }

    /**
     * @return array<mixed>
     */
    protected function resolveDefaultClosureDependencyForEvaluationByType(string $parameterType): array
    {
        $record = $this->getRecord();

        return match (true) {
            $parameterType === Model::class => [$record],
            $record instanceof $parameterType => [$record],
            default => [],
        };
    }
}
