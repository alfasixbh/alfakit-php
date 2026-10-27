<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Forms\Components\Concerns;

use AlfaSix\Alfakit\Forms\Components\Field;
use AlfaSix\Alfakit\Schemas\Components\Component;
use AlfaSix\Alfakit\Schemas\Schema;
use Closure;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

trait HasState
{
    protected mixed $default = null;

    protected bool $hasDefault = false;

    protected ?string $statePathOverride = null;

    /** Null means "unless disabled": disabled fields never save what the browser sends. */
    protected bool|Closure|null $isDehydrated = null;

    protected bool|Closure $isDehydratedWhenHidden = false;

    /** Null means "follow `dehydrated()`"; `saved(false)` keeps the value out of what is persisted. */
    protected bool|Closure|null $isSaved = null;

    protected bool|Closure $shouldSaveRelationshipsWhenHidden = false;

    protected ?Closure $formatStateUsing = null;

    protected ?Closure $dehydrateStateUsing = null;

    protected ?Closure $mutateDehydratedStateUsing = null;

    /** @var array<Closure> */
    protected array $afterStateHydrated = [];

    /** @var array<Closure> */
    protected array $afterStateUpdated = [];

    /** @var array<Closure> */
    protected array $beforeStateDehydrated = [];

    protected bool|Closure $isLive = false;

    /** @var array<string>|Closure|null */
    protected array|Closure|null $partialRenderComponents = null;

    protected bool|Closure $isLiveOnBlur = false;

    protected int|string|Closure|null $liveDebounce = null;

    protected bool|Closure $isDirtyTracked = true;

    // ------------------------------------------------------------ Configuration

    public function default(mixed $state): static
    {
        $this->default = $state;
        $this->hasDefault = true;

        return $this;
    }

    public function hasDefault(): bool
    {
        return $this->hasDefault;
    }

    public function getDefaultState(): mixed
    {
        return $this->evaluate($this->default);
    }

    /**
     * Override the state path. Absolute (`isAbsolute: true`) paths ignore the container prefix.
     */
    public function statePath(?string $path): static
    {
        $this->statePathOverride = $path;

        return $this;
    }

    public function dehydrated(bool|Closure $condition = true): static
    {
        $this->isDehydrated = $condition;

        return $this;
    }

    /**
     * Whether a disabled field still saves its state (only when `dehydrated()` or
     * `saved()` was called).
     */
    public function isDehydratedWhenDisabled(): bool
    {
        if ($this->isSaved !== null && ! $this->evaluate($this->isSaved)) {
            return false;
        }

        return ($this->isDehydrated !== null && (bool) $this->evaluate($this->isDehydrated))
            || ($this->isSaved !== null && (bool) $this->evaluate($this->isSaved));
    }

    /**
     * `saved(false)`: the field is still validated and its value stays in the form state
     * (`$get()`, `afterStateUpdated`, live updates), but it is left out of `getState()` and
     * its relationship (BelongsToMany select, checkbox list) is not saved, e.g. a password
     * confirmation. `disabled()->saved()` saves a disabled field's server value.
     */
    public function saved(bool|Closure $condition = true): static
    {
        $this->isSaved = $condition;

        return $this;
    }

    public function isSaved(): bool
    {
        return $this->isSaved === null || (bool) $this->evaluate($this->isSaved);
    }

    /**
     * Save the field's relationship even while the field is hidden.
     */
    public function saveRelationshipsWhenHidden(bool|Closure $condition = true): static
    {
        $this->shouldSaveRelationshipsWhenHidden = $condition;

        return $this;
    }

    public function shouldSaveRelationshipsWhenHidden(): bool
    {
        return (bool) $this->evaluate($this->shouldSaveRelationshipsWhenHidden);
    }

    /**
     * Whether the field's relationship should be saved now: never with `saved(false)`,
     * and while hidden only with `saveRelationshipsWhenHidden()`.
     */
    public function shouldSaveRelationships(): bool
    {
        if (! $this->isSaved()) {
            return false;
        }

        return ! $this->isHiddenInTree() || $this->shouldSaveRelationshipsWhenHidden();
    }

    public function dehydratedWhenHidden(bool|Closure $condition = true): static
    {
        $this->isDehydratedWhenHidden = $condition;

        return $this;
    }

    /**
     * Transform the stored value into the value shown in the form.
     */
    public function formatStateUsing(?Closure $callback): static
    {
        $this->formatStateUsing = $callback;

        return $this;
    }

    /**
     * Transform the form value into the value that is persisted.
     */
    public function dehydrateStateUsing(?Closure $callback): static
    {
        $this->dehydrateStateUsing = $callback;

        return $this;
    }

    public function mutateDehydratedStateUsing(?Closure $callback): static
    {
        $this->mutateDehydratedStateUsing = $callback;

        return $this;
    }

    public function afterStateHydrated(?Closure $callback): static
    {
        if ($callback !== null) {
            $this->afterStateHydrated[] = $callback;
        }

        return $this;
    }

    /**
     * Runs on the server whenever the field changes. Implies `live()`, keeping the
     * `onBlur` and `debounce` options of an earlier `live()` call.
     */
    public function afterStateUpdated(?Closure $callback): static
    {
        if ($callback !== null) {
            $this->afterStateUpdated[] = $callback;

            if ($this->isLive === false) {
                $this->isLive = true;
            }
        }

        return $this;
    }

    public function beforeStateDehydrated(?Closure $callback): static
    {
        if ($callback !== null) {
            $this->beforeStateDehydrated[] = $callback;
        }

        return $this;
    }

    /**
     * Mark the field as reactive: changes are sent to the server so dependent closures
     * (`hidden`, `options`, `afterStateUpdated`…) can be re-evaluated. `$debounce` is in
     * milliseconds (`500`) or a duration string (`'500ms'`, `'1s'`, `'1.5s'`).
     */
    public function live(bool|Closure $condition = true, bool|Closure $onBlur = false, int|string|Closure|null $debounce = null): static
    {
        $this->isLive = $condition;
        $this->isLiveOnBlur = $onBlur;
        $this->liveDebounce = $debounce;

        return $this;
    }

    public function isLive(): bool
    {
        return (bool) $this->evaluate($this->isLive);
    }

    /**
     * After this field's live update, re-send only these components (names of fields next
     * to this one, component keys, or absolute state paths) instead of every component
     * that changed. State changes (`$set()`) are always sent.
     *
     * @param  array<string>|Closure  $components
     */
    public function partiallyRenderComponentsAfterStateUpdated(array|Closure $components): static
    {
        $this->partialRenderComponents = $components;

        return $this;
    }

    /**
     * After this field's live update, re-send only the field itself.
     */
    public function partiallyRenderAfterStateUpdated(bool|Closure $condition = true): static
    {
        $this->partialRenderComponents = fn (): ?array => $this->evaluate($condition) ? [$this->getStatePath()] : null;

        return $this;
    }

    /**
     * After this field's live update, re-send no components: `afterStateUpdated()` runs
     * and its `$set()` calls reach the browser, but nothing is re-rendered. Without
     * `afterStateUpdated()` hooks the field makes no request at all.
     */
    public function skipRenderAfterStateUpdated(bool|Closure $condition = true): static
    {
        $this->partialRenderComponents = fn (): ?array => $this->evaluate($condition) ? [] : null;

        return $this;
    }

    public function shouldSkipRenderAfterStateUpdated(): bool
    {
        return $this->getPartialRenderTargets() === [];
    }

    /**
     * What a live update of this field re-renders: null for every changed component
     * (the default), otherwise the state paths / keys of the components to re-send.
     *
     * @return list<string>|null
     */
    public function getPartialRenderTargets(): ?array
    {
        $components = $this->evaluate($this->partialRenderComponents);

        if (! is_array($components)) {
            return null;
        }

        $targets = [];

        foreach ($components as $name) {
            if (! is_string($name) || $name === '') {
                continue;
            }

            $targets[] = $this->resolvePartialRenderTarget($name);
        }

        return array_values(array_unique($targets));
    }

    /**
     * A sibling field's absolute state path (or a component's key), else `$name` as given.
     */
    protected function resolvePartialRenderTarget(string $name): string
    {
        if ($name === $this->getName()) {
            return $this->getStatePath();
        }

        if (! $this->hasContainer()) {
            return $name;
        }

        $component = $this->getContainer()->getComponent(
            static fn (Component $component): bool => ($component instanceof Field && $component->getName() === $name) || $component->getKey() === $name,
            withHidden: true,
        );

        if ($component instanceof Field) {
            return $component->getStatePath();
        }

        return $component?->getKey() ?? $name;
    }

    /**
     * Whether the browser posts this field's changes to the server (`live()`), unless
     * `skipRenderAfterStateUpdated()` makes the round trip pointless.
     */
    public function makesLiveRequests(): bool
    {
        return $this->isLive() && ! ($this->afterStateUpdated === [] && $this->shouldSkipRenderAfterStateUpdated());
    }

    public function isLiveOnBlur(): bool
    {
        return (bool) $this->evaluate($this->isLiveOnBlur);
    }

    public function getLiveDebounce(): ?int
    {
        return static::parseDebounce($this->evaluate($this->liveDebounce));
    }

    /**
     * The debounce of a `live()` field without `debounce:` (milliseconds): 150 ms for
     * fields that change with every keystroke or drag (Livewire's `wire:model.live`
     * default), 0 for fields that change once per choice (selects, toggles, checkboxes,
     * radios, date pickers, uploads), so they refresh right away.
     */
    public function getDefaultLiveDebounce(): int
    {
        return 150;
    }

    /**
     * Milliseconds from `500`, `'500'`, `'500ms'`, `'1s'` or `'1.5s'`; null when unset or
     * unreadable.
     */
    public static function parseDebounce(mixed $debounce): ?int
    {
        if ($debounce === null || is_bool($debounce)) {
            return null;
        }

        if (is_int($debounce) || is_float($debounce)) {
            return max(0, (int) $debounce);
        }

        if (! is_string($debounce) || preg_match('/^\s*(\d+(?:\.\d+)?)\s*(ms|s)?\s*$/i', $debounce, $matches) !== 1) {
            return null;
        }

        $value = (float) $matches[1];

        return (int) round(strtolower($matches[2] ?? 'ms') === 's' ? $value * 1000 : $value);
    }

    // ------------------------------------------------------------ Paths

    public function getStatePath(bool $isAbsolute = true): string
    {
        if ($this->statePathOverride !== null) {
            return $this->statePathOverride;
        }

        if (! $isAbsolute) {
            return $this->getName();
        }

        $containerPath = $this->hasContainer() ? $this->getContainer()->getStatePath() : null;

        return filled($containerPath) ? "{$containerPath}.{$this->getName()}" : $this->getName();
    }

    /**
     * The state path below a container's (an item of a repeater or builder): `address.city`
     * for `items.2.address.city` below `items.2`, keeping the segments that
     * `Group::statePath()` adds between them. The field's own name when it isn't below.
     */
    public function getStatePathRelativeTo(string $containerPath): string
    {
        $path = $this->getStatePath();
        $prefix = $containerPath === '' ? '' : $containerPath.'.';

        return $prefix !== '' && str_starts_with($path, $prefix) ? substr($path, strlen($prefix)) : $this->getStatePath(isAbsolute: false);
    }

    /**
     * Whether a concrete state path (e.g. `items.2.name`) belongs to this field's
     * wildcard pattern (e.g. `items.*.name`).
     */
    public function matchesStatePathPattern(string $path): bool
    {
        $pattern = $this->getStatePath();

        if (! str_contains($pattern, '*')) {
            return false;
        }

        return Str::is($pattern, $path);
    }

    /**
     * True when a parent component (such as a Repeater) between this field and the
     * `$boundary` schema hydrates and dehydrates the field's state itself, per item.
     * Without a boundary the whole ancestry is inspected.
     */
    public function isNestedInRepeater(?Schema $boundary = null): bool
    {
        $container = $this->hasContainer() ? $this->getContainer() : null;

        while ($container !== null && $container !== $boundary) {
            $parent = $container->getParentComponent();

            if ($parent === null) {
                return false;
            }

            if ($parent->managesChildFieldState()) {
                return true;
            }

            $container = $parent->hasContainer() ? $parent->getContainer() : null;
        }

        return false;
    }

    // ------------------------------------------------------------ Runtime state

    public function getState(): mixed
    {
        return $this->getContainer()->getStateAt($this->getStatePath());
    }

    /**
     * The value exactly as stored in the form state (injected into closures as `$rawState`).
     * Form state is not cast, so it equals `$state` unless a closure receives a formatted
     * or dehydrated value as `$state`.
     */
    public function getRawState(): mixed
    {
        return Arr::get($this->getContainer()->getRootSchema()->getRawState(), $this->getStatePath());
    }

    public function state(mixed $state): static
    {
        $this->getContainer()->setStateAt($this->getStatePath(), $this->evaluate($state));

        return $this;
    }

    public function hydrateState(bool $useDefaults): void
    {
        $root = $this->getContainer()->getRootSchema();
        $path = $this->getStatePath();

        if ($useDefaults || ! Arr::has($root->getRawState(), $path)) {
            $root->setStateAt($path, $this->hasDefault() ? $this->getDefaultState() : $this->getDefaultStateWhenEmpty());
        } elseif ($this->formatStateUsing !== null) {
            $root->setStateAt($path, $this->evaluate($this->formatStateUsing, ['state' => $this->getState()]));
        }

        $this->loadStateFromRelationships();
        $this->callAfterStateHydrated();
    }

    /**
     * The empty value for a field without an explicit default. Checkboxes return false,
     * multi-selects an empty array, and so on.
     */
    protected function getDefaultStateWhenEmpty(): mixed
    {
        return null;
    }

    public function getDefaultStateForTemplate(): mixed
    {
        return $this->getDefaultStateWhenEmpty();
    }

    /**
     * Coerce what the browser sent for this field into the shape the server works with,
     * right after the client state is applied and before validation. A single `FileUpload`
     * holds a list of paths on the server, while a renderer may send one path.
     */
    public function normalizeClientState(): void {}

    public function callAfterStateHydrated(): void
    {
        foreach ($this->afterStateHydrated as $callback) {
            $this->evaluate($callback, ['state' => $this->getState()]);
        }
    }

    /**
     * @param  mixed  $old  The state before the update (what the server last saw)
     */
    public function callAfterStateUpdated(mixed $old = null): void
    {
        $state = $this->getState();

        foreach ($this->afterStateUpdated as $callback) {
            $this->evaluate($callback, ['state' => $state, 'old' => $old]);
        }
    }

    public function callBeforeStateDehydrated(): void
    {
        foreach ($this->beforeStateDehydrated as $callback) {
            $this->evaluate($callback, ['state' => $this->getState()]);
        }
    }

    public function isDehydrated(): bool
    {
        if (! $this->isSaved()) {
            return false;
        }

        if ($this->isDehydrated === null) {
            if ($this->isDisabled() && ! $this->isDehydratedWhenDisabled()) {
                return false;
            }
        } elseif (! $this->evaluate($this->isDehydrated)) {
            return false;
        }

        if ($this->isHiddenInTree() && ! $this->evaluate($this->isDehydratedWhenHidden)) {
            return false;
        }

        return true;
    }

    public function dehydrateState(): mixed
    {
        $state = $this->getState();

        if ($this->dehydrateStateUsing !== null) {
            $state = $this->evaluate($this->dehydrateStateUsing, ['state' => $state]);
        }

        $state = $this->mutateDehydratedState($state);

        if ($this->mutateDehydratedStateUsing !== null) {
            $state = $this->evaluate($this->mutateDehydratedStateUsing, ['state' => $state]);
        }

        return $state;
    }

    /**
     * Dehydrate the field into `$data` at `$path` (absolute, or relative to the container
     * that collects the data), plus the attributes the field writes next to itself
     * (`FileUpload::storeFileNamesIn()`).
     *
     * @param  array<array-key, mixed>  $data
     */
    public function dehydrateStateInto(array &$data, string $path): void
    {
        Arr::set($data, $path, $this->dehydrateState());

        $parent = str_contains($path, '.') ? substr($path, 0, (int) strrpos($path, '.')).'.' : '';

        foreach ($this->getAdditionalDehydratedState() as $attribute => $value) {
            Arr::set($data, $parent.$attribute, $value);
        }
    }

    /**
     * Attributes next to the field (same parent) to save with it, computed by the last
     * `dehydrateState()`. Override in fields that write more than their own state.
     *
     * @return array<string, mixed>
     */
    public function getAdditionalDehydratedState(): array
    {
        return [];
    }

    /**
     * Type-specific dehydration (e.g. casting numeric inputs). Override in subclasses.
     */
    protected function mutateDehydratedState(mixed $state): mixed
    {
        return $state;
    }

    // ------------------------------------------------------------ Relationships

    public function loadStateFromRelationships(): void {}

    public function saveRelationships(): void {}
}
