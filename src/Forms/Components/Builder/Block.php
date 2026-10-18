<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Forms\Components\Builder;

use AlfaSix\Alfakit\Schemas\Components\Component;
use AlfaSix\Alfakit\Support\Icons;
use AlfaSix\Alfakit\Support\Labels;
use BackedEnum;
use Closure;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;

/**
 * A block type of a Builder field: a name (stored as the item's `type`), a label, an
 * icon and its own schema (stored under the item's `data`).
 *
 * The label may be a closure receiving the item's `$state` (null in the block picker),
 * to label each item after its content.
 */
class Block extends Component
{
    protected string|BackedEnum|\Illuminate\Contracts\Support\Htmlable|Closure|null $icon = null;

    protected int|Closure|null $maxItems = null;

    protected string|Closure|null $preview = null;

    protected string|Closure|null $previewComponent = null;

    protected string|Closure|null $group = null;

    protected string|Closure|null $description = null;

    protected string|Closure|null $summary = null;

    final public function __construct(protected string $name) {}

    public static function make(mixed ...$arguments): static
    {
        return parent::make(...$arguments);
    }

    public function getName(): string
    {
        return $this->name;
    }

    /**
     * A short, plain-language explanation shown under the label in the block picker.
     */
    public function description(string|Closure|null $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getDescription(): ?string
    {
        $description = $this->evaluate($this->description);

        return is_string($description) && $description !== '' ? $description : null;
    }

    /**
     * A one-line, plain-text summary of an item, shown after its label in the item header
     * (most useful when the item is collapsed): a closure receiving `$state` / `$data` and
     * `$index`, or a string.
     */
    public function summary(string|Closure|null $summary): static
    {
        $this->summary = $summary;

        return $this;
    }

    /**
     * The summary of an item with `$data`, or null.
     *
     * @param  array<string, mixed>  $data
     */
    public function getItemSummary(array $data, ?int $index = null): ?string
    {
        if ($this->summary === null) {
            return null;
        }

        $summary = $this->evaluate($this->summary, ['state' => $data, 'data' => $data, 'index' => $index]);

        if (! is_scalar($summary)) {
            return null;
        }

        $summary = trim(preg_replace('/\s+/u', ' ', strip_tags((string) $summary)) ?? '');

        return $summary === '' ? null : Str::limit($summary, 160);
    }

    public function icon(string|BackedEnum|Htmlable|Closure|null $icon): static
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

    /**
     * The heading the block is listed under in the picker (also set by grouped
     * `Builder::blocks(['Group' => [...]])`).
     */
    public function group(string|Closure|null $group): static
    {
        $this->group = $group;

        return $this;
    }

    public function getGroup(): ?string
    {
        $group = $this->evaluate($this->group);

        return is_string($group) && $group !== '' ? $group : null;
    }

    public function hasGroup(): bool
    {
        return $this->group !== null;
    }

    /**
     * How many items of this block the builder may hold.
     */
    public function maxItems(int|Closure|null $count): static
    {
        $this->maxItems = $count;

        return $this;
    }

    public function getMaxItems(): ?int
    {
        $max = $this->evaluate($this->maxItems);

        return $max === null ? null : (int) $max;
    }

    /**
     * The preview of an item of this block (shown with `Builder::blockPreviews()`): a Blade
     * view rendered with the item's data as variables (plus `$data` and `$index`), or a
     * closure receiving `$data` / `$state` and `$index` that returns a view name, a View
     * or HTML (`Htmlable`).
     */
    public function preview(string|Closure|null $view): static
    {
        $this->preview = $view;

        return $this;
    }

    /**
     * Render the preview with a frontend component instead: the renderer resolves
     * `builder-preview:{name}` from its registry and passes `data`, `block` and `index`.
     */
    public function previewComponent(string|Closure|null $name): static
    {
        $this->previewComponent = $name;

        return $this;
    }

    public function getPreviewComponent(): ?string
    {
        return $this->evaluate($this->previewComponent);
    }

    public function hasPreview(): bool
    {
        return $this->preview !== null;
    }

    /**
     * The preview HTML of an item with `$data`, or null without a preview.
     *
     * @param  array<string, mixed>  $data
     */
    public function renderPreview(array $data, ?int $index = null): ?string
    {
        if ($this->preview === null) {
            return null;
        }

        $preview = $this->evaluate($this->preview, ['data' => $data, 'state' => $data, 'index' => $index]);

        if ($preview instanceof Htmlable) {
            return $preview->toHtml();
        }

        if ($preview instanceof View) {
            return $preview->render();
        }

        if (! is_string($preview) || $preview === '') {
            return null;
        }

        $variables = array_filter($data, static fn (mixed $key): bool => is_string($key) && preg_match('/^[A-Za-z_]\w*$/', $key) === 1, ARRAY_FILTER_USE_KEY);

        return view($preview, [...$variables, 'data' => $data, 'index' => $index])->render();
    }

    /**
     * The label of an item of this block, given its data.
     *
     * @param  array<string, mixed>|null  $state
     */
    public function getItemLabel(?array $state, ?int $index = null): string
    {
        $label = $this->evaluate($this->label, ['state' => $state, 'index' => $index]) ?? Labels::fromName($this->name, isRelationshipPath: false);

        return $this->shouldTranslateLabel ? __($label) : (string) $label;
    }

    public function getLabel(): ?string
    {
        return $this->getItemLabel(null);
    }

    /**
     * A block is read-only when its builder is, so the fields inside it are too.
     */
    public function isReadOnly(): bool
    {
        $parent = $this->hasContainer() ? $this->getContainer()->getParentComponent() : null;

        return $parent !== null && method_exists($parent, 'isReadOnly') && $parent->isReadOnly();
    }

    /**
     * @return array<string, int|null>
     */
    protected function getDefaultColumns(): array
    {
        return ['default' => 1];
    }

    public function getKey(): string
    {
        return $this->evaluate($this->key) ?? 'block:'.$this->name;
    }
}
