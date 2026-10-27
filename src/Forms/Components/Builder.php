<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Forms\Components;

use AlfaSix\Alfakit\Forms\Components\Builder\Block;
use AlfaSix\Alfakit\Schemas\Schema;
use AlfaSix\Alfakit\Support\Enums\Width;
use AlfaSix\Alfakit\Support\Labels;
use Closure;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use LogicException;

/**
 * A list of items, each one of several block types with its own schema. The state is a
 * list of `['type' => block name, 'data' => [...]]` items, like Filament's Builder.
 *
 * Items reuse the Repeater machinery (per-item schemas, validation, add/delete/reorder
 * constraints); each item's fields live under `{name}.{index}.data`.
 */
class Builder extends Repeater
{
    /** @var int|array<string, int|null>|Closure|null */
    protected int|array|Closure|null $blockPickerColumns = null;

    protected bool|Closure $hasBlockNumbers = true;

    protected bool|Closure $hasBlockIcons = false;

    protected bool|Closure $hasBlockLabels = true;

    protected int|Closure $defaultItems = 0;

    protected bool|Closure $hasBlockPreviews = false;

    protected bool|Closure $hasInteractiveBlockPreviews = false;

    protected Width|string|Closure|null $blockPickerWidth = null;

    protected bool|Closure $isBlockSearchable = false;

    protected bool|Closure $hasBlockActionsMenu = false;

    protected int|Closure|null $blockSearchDebounce = null;

    protected string|Htmlable|Closure|null $searchPrompt = null;

    protected string|Htmlable|Closure|null $noSearchResultsMessage = null;

    /**
     * The block types. Named arrays group blocks under a heading in the picker (ungrouped
     * blocks come first), like `Block::group()`:
     *
     *     ->blocks([
     *         'Content' => [Block::make('heading'), Block::make('paragraph')],
     *         'Media' => [Block::make('video')],
     *     ])
     *
     * Grouping only affects the picker, not the stored state.
     *
     * @param  array<Block|array<Block>>|Closure  $blocks
     */
    public function blocks(array|Closure $blocks): static
    {
        return $this->schema($blocks instanceof Closure
            ? static fn (Builder $component): array => $component->normalizeBlocks($component->evaluate($blocks) ?? [])
            : $this->normalizeBlocks($blocks));
    }

    /**
     * Flatten grouped blocks (`['Group' => [Block, …]]`), giving each block its group
     * unless it has one (`Block::group()`). Integer keys are ungrouped lists.
     *
     * @param  array<mixed>  $blocks
     * @return array<Block>
     */
    protected function normalizeBlocks(array $blocks): array
    {
        $components = [];

        foreach ($blocks as $group => $groupBlocks) {
            if (! is_array($groupBlocks)) {
                if ($groupBlocks instanceof Block) {
                    $components[] = $groupBlocks;
                }

                continue;
            }

            foreach ($groupBlocks as $block) {
                if (! $block instanceof Block) {
                    continue;
                }

                if (is_string($group) && $group !== '' && ! $block->hasGroup()) {
                    $block->group($group);
                }

                $components[] = $block;
            }
        }

        return $components;
    }

    /**
     * Columns of the block picker menu (e.g. `2` or `['default' => 1, 'md' => 3]`).
     *
     * @param  int|array<string, int|null>|Closure|null  $columns
     */
    public function blockPickerColumns(int|array|Closure|null $columns = 2): static
    {
        $this->blockPickerColumns = $columns;

        return $this;
    }

    /**
     * Width of the block picker menu (`Width::Medium`, `'2xl'` …).
     */
    public function blockPickerWidth(Width|string|Closure|null $width): static
    {
        $this->blockPickerWidth = $width;

        return $this;
    }

    /**
     * A search field in the block picker; it matches block labels in the browser.
     */
    public function searchable(bool|Closure $condition = true): static
    {
        $this->isBlockSearchable = $condition;

        return $this;
    }

    public function isSearchable(): bool
    {
        return (bool) $this->evaluate($this->isBlockSearchable);
    }

    /**
     * Milliseconds to wait after typing in the block picker search before filtering.
     */
    public function searchDebounce(int|Closure|null $milliseconds): static
    {
        $this->blockSearchDebounce = $milliseconds;

        return $this;
    }

    /**
     * Placeholder and accessible label of the block picker search field.
     */
    public function searchPrompt(string|Htmlable|Closure|null $message): static
    {
        $this->searchPrompt = $message;

        return $this;
    }

    /**
     * Null uses the renderer's translated default ("Search blocks…").
     */
    public function getSearchPrompt(): ?string
    {
        return $this->toPlainText($this->evaluate($this->searchPrompt));
    }

    /**
     * Shown in the block picker when no block matches the search.
     */
    public function noSearchResultsMessage(string|Htmlable|Closure|null $message): static
    {
        $this->noSearchResultsMessage = $message;

        return $this;
    }

    /**
     * Null uses the renderer's translated default ("No results.").
     */
    public function getNoSearchResultsMessage(): ?string
    {
        return $this->toPlainText($this->evaluate($this->noSearchResultsMessage));
    }

    private function toPlainText(mixed $message): ?string
    {
        if ($message instanceof Htmlable) {
            $message = html_entity_decode(strip_tags($message->toHtml()), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        return is_string($message) && trim($message) !== '' ? $message : null;
    }

    /**
     * Alias of {@see self::searchDebounce()}.
     */
    public function blockSearchDebounce(int|Closure|null $milliseconds): static
    {
        return $this->searchDebounce($milliseconds);
    }

    public function getBlockPickerWidth(): ?string
    {
        $width = $this->evaluate($this->blockPickerWidth);

        return $width instanceof Width ? $width->value : $width;
    }

    /**
     * Show each item as its block's `preview()` instead of its form; the form opens with
     * the item's edit button (or by clicking a non-interactive preview). Interactive
     * previews keep links and buttons inside them clickable.
     */
    public function blockPreviews(bool|Closure $condition = true, bool|Closure $areInteractive = false): static
    {
        $this->hasBlockPreviews = $condition;
        $this->hasInteractiveBlockPreviews = $areInteractive;

        return $this;
    }

    public function hasBlockPreviews(): bool
    {
        return (bool) $this->evaluate($this->hasBlockPreviews);
    }

    public function hasInteractiveBlockPreviews(): bool
    {
        return (bool) $this->evaluate($this->hasInteractiveBlockPreviews);
    }

    /**
     * Number the items in their headers (`Heading 1`, `Paragraph 2`…).
     */
    public function blockNumbers(bool|Closure $condition = true): static
    {
        $this->hasBlockNumbers = $condition;

        return $this;
    }

    /**
     * Show each block's icon in its item header.
     */
    public function blockIcons(bool|Closure $condition = true): static
    {
        $this->hasBlockIcons = $condition;

        return $this;
    }

    /**
     * Put the item actions (move up / down, duplicate, extra item actions, delete) in a
     * compact "More" menu in each item header instead of a row of icon buttons. Only the
     * collapse toggle stays a button.
     */
    public function blockActionsMenu(bool|Closure $condition = true): static
    {
        $this->hasBlockActionsMenu = $condition;

        return $this;
    }

    public function hasBlockActionsMenu(): bool
    {
        return (bool) $this->evaluate($this->hasBlockActionsMenu);
    }

    public function blockLabels(bool|Closure $condition = true): static
    {
        $this->hasBlockLabels = $condition;

        return $this;
    }

    public function simple(Field $field): static
    {
        throw new LogicException('Builder items are blocks; use a Repeater for simple lists.');
    }

    public function relationship(string|Closure|null $name = null, ?Closure $modifyQueryUsing = null, ?Closure $modifyRecordsUsing = null): static
    {
        throw new LogicException('Builder stores its items in a JSON column; relationships are not supported.');
    }

    // ---------------------------------------------------------------- Blocks

    /**
     * Every block, keyed by name (hidden ones included).
     *
     * @return array<string, Block>
     */
    public function getBlocks(bool $withHidden = true): array
    {
        $blocks = [];

        foreach ($this->getChildSchema()->getComponents($withHidden) as $component) {
            if ($component instanceof Block) {
                $blocks[$component->getName()] = $component;
            }
        }

        return $blocks;
    }

    public function getBlock(string $name): ?Block
    {
        return $this->getBlocks()[$name] ?? null;
    }

    /**
     * The block types items may use: visible blocks, plus the types of items the server
     * already holds (so hiding a block does not invalidate existing content).
     *
     * @return array<int, string>
     */
    public function getAllowedBlockTypes(): array
    {
        $types = array_keys($this->getBlocks(withHidden: false));
        $server = $this->getContainer()->getServerStateAt($this->getStatePath());

        foreach (is_array($server) ? $server : [] as $item) {
            if (is_array($item) && is_string($item['type'] ?? null) && $this->getBlock($item['type']) !== null) {
                $types[] = $item['type'];
            }
        }

        return array_values(array_unique($types));
    }

    protected function getItemType(int|string $index): ?string
    {
        $type = $this->getContainer()->getStateAt($this->getStatePath().'.'.$index.'.type');

        return is_string($type) ? $type : null;
    }

    // ---------------------------------------------------------------- Items

    public function getItemSchema(int|string $index): Schema
    {
        return $this->getBlockSchema($this->getItemType($index), $index);
    }

    /**
     * The schema of a block at `$index`: clones of the block's components under
     * `{name}.{index}.data`. Unknown block types get an empty schema.
     */
    public function getBlockSchema(?string $type, int|string $index): Schema
    {
        $key = $index.'|'.($type ?? '');

        if (isset($this->itemSchemas[$key])) {
            return $this->itemSchemas[$key];
        }

        $path = $this->getStatePath().'.'.$index.'.data';
        $block = $type === null ? null : $this->getBlock($type);

        $schema = $block !== null
            ? $block->getChildSchema($path)
            : Schema::make()->parentComponent($this)->components([])->statePath($path);

        return $this->itemSchemas[$key] = $schema;
    }

    /**
     * Fields of the item at `$index`, or of the block template when `$index` is the
     * template placeholder.
     *
     * @return array<Field>
     */
    protected function getBlockFields(?string $type, int|string $index, bool $withHidden = false): array
    {
        $schema = $this->getBlockSchema($type, $index);

        return array_values(array_filter(
            $schema->getFlatFields($withHidden),
            static fn (Field $field): bool => ! $field->isNestedInRepeater($schema),
        ));
    }

    protected function getItemFields(int|string $index, bool $withHidden = false): array
    {
        return $this->getBlockFields($this->getItemType($index), $index, $withHidden);
    }

    // ---------------------------------------------------------------- Item actions

    protected function getDefaultAddActionLabel(): string
    {
        return $this->evaluate($this->addActionLabel) ?? __('alfakit::forms.builder.add', ['label' => Labels::forSentence($this->getLabel() ?? '')]);
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    protected function addItemFromArguments(array $arguments): void
    {
        $this->addBlock(is_string($arguments['block'] ?? null) ? $arguments['block'] : null);
    }

    /**
     * Append an item: `$state` must be a `['type' => …, 'data' => […]]` item.
     *
     * @param  array<string, mixed>|null  $state
     */
    public function addItem(?array $state = null): static
    {
        $type = $state['type'] ?? null;

        return is_string($type) ? $this->addBlock($type, is_array($state['data'] ?? null) ? $state['data'] : null) : $this;
    }

    /**
     * Append an item of the visible block `$type` (with `$data`, or the block's defaults)
     * unless the builder or the block is full.
     *
     * @param  array<string, mixed>|null  $data
     */
    public function addBlock(?string $type, ?array $data = null): static
    {
        if ($type === null || ! array_key_exists($type, $this->getBlocks(withHidden: false)) || ! $this->canAddItems() || ! $this->canAddBlock($type)) {
            return $this;
        }

        $state = $this->getBlockTemplateState($type);

        if ($data !== null) {
            $state['data'] = $data;
        }

        return $this->updateItems([...$this->getItems(), $state]);
    }

    /**
     * Whether another item of `$type` fits its block's `maxItems()`.
     */
    public function canAddBlock(string $type): bool
    {
        $max = $this->getBlock($type)?->getMaxItems();

        if ($max === null) {
            return true;
        }

        $count = count(array_filter($this->getItems(), static fn (mixed $item): bool => is_array($item) && ($item['type'] ?? null) === $type));

        return $count < $max;
    }

    protected function canCloneItem(int $index): bool
    {
        $type = $this->getItemType($index);

        return parent::canCloneItem($index) && $type !== null && $this->canAddBlock($type);
    }

    // ---------------------------------------------------------------- Hydration

    public function hydrateState(bool $useDefaults): void
    {
        $this->itemSchemas = [];

        $root = $this->getContainer()->getRootSchema();
        $path = $this->getStatePath();

        if ($useDefaults || ! Arr::has($root->getRawState(), $path)) {
            $default = $this->hasDefault() ? $this->getDefaultState() : null;
            $root->setStateAt($path, is_array($default) ? $default : []);
        }

        // Normalise to `{type, data}` items and drop items whose block no longer exists.
        $items = [];

        foreach ($this->getItems() as $item) {
            $type = is_array($item) ? ($item['type'] ?? null) : null;

            if (is_string($type) && $this->getBlock($type) !== null) {
                $items[] = ['type' => $type, 'data' => is_array($item['data'] ?? null) ? $item['data'] : []];
            }
        }

        $root->setStateAt($path, $items);

        foreach (array_keys($items) as $index) {
            foreach ($this->getItemFields($index, withHidden: true) as $field) {
                // Item values the field's own `default()` lists are kept; only missing
                // ones (new items, `defaultItems()`) take the item fields' defaults.
                $field->hydrateState(! Arr::has($root->getRawState(), $field->getStatePath()));
            }
        }

        $this->callAfterStateHydrated();
    }

    protected function dehydrateItem(int|string $index): array
    {
        $data = [];
        $itemPath = $this->getBlockSchema($this->getItemType($index), $index)->getStatePath();

        foreach ($this->getItemFields($index) as $field) {
            if ($field->isDehydrated()) {
                $field->dehydrateStateInto($data, $field->getStatePathRelativeTo($itemPath));
            }
        }

        return ['type' => $this->getItemType($index), 'data' => $data];
    }

    /**
     * Default state of a new item of `$type`.
     *
     * @return array{type: string, data: array<string, mixed>}
     */
    public function getBlockTemplateState(string $type): array
    {
        $data = [];

        $itemPath = $this->getBlockSchema($type, self::TEMPLATE_INDEX)->getStatePath();

        foreach ($this->getBlockFields($type, self::TEMPLATE_INDEX, withHidden: true) as $field) {
            Arr::set($data, $field->getStatePathRelativeTo($itemPath), $field->hasDefault() ? $field->getDefaultState() : $field->getDefaultStateForTemplate());
        }

        return ['type' => $type, 'data' => $data];
    }

    // ---------------------------------------------------------------- Validation

    protected function getTypeValidationRules(): array
    {
        $rules = parent::getTypeValidationRules();

        if (array_filter(array_map(static fn (Block $block): ?int => $block->getMaxItems(), $this->getBlocks())) !== []) {
            $rules[] = new class(fn (): ?string => $this->getBlockLimitViolation()) implements ValidationRule
            {
                public function __construct(private readonly Closure $check) {}

                public function validate(string $attribute, mixed $value, Closure $fail): void
                {
                    if (($error = ($this->check)()) !== null) {
                        $fail($error);
                    }
                }
            };
        }

        return $rules;
    }

    /**
     * The message for the first block used more often than its `maxItems()` allows.
     */
    protected function getBlockLimitViolation(): ?string
    {
        $counts = array_count_values(array_filter(array_map(
            static fn (mixed $item): ?string => is_array($item) && is_string($item['type'] ?? null) ? $item['type'] : null,
            $this->getItems(),
        )));

        foreach ($this->getBlocks() as $name => $block) {
            $max = $block->getMaxItems();

            if ($max !== null && ($counts[$name] ?? 0) > $max) {
                return trans_choice('alfakit::forms.builder.block_max_items', $max, ['block' => $block->getLabel(), 'count' => $max]);
            }
        }

        return null;
    }

    public function getValidationRulesByPath(): array
    {
        $rules = parent::getValidationRulesByPath();
        $allowed = $this->getAllowedBlockTypes();

        foreach (array_keys($this->getItems()) as $index) {
            $rules[$this->getStatePath().'.'.$index] = ['array'];
            $rules[$this->getStatePath().'.'.$index.'.type'] = ['required', 'string', Rule::in($allowed)];
            $rules[$this->getStatePath().'.'.$index.'.data'] = ['nullable', 'array'];
        }

        return $rules;
    }

    // ---------------------------------------------------------------- Serialisation

    /**
     * @return array<string, mixed>
     */
    protected function serializeItem(int $index): array
    {
        $type = $this->getItemType($index);
        $block = $type === null ? null : $this->getBlock($type);
        $data = $this->getItems()[$index]['data'] ?? [];

        return [
            'key' => (string) $index,
            'type' => $type,
            'label' => $block?->getItemLabel(is_array($data) ? $data : [], $index),
            ...(($summary = $block?->getItemSummary(is_array($data) ? $data : [], $index)) !== null ? ['summary' => $summary] : []),
            'icon' => $block?->getIcon(),
            'columns' => $block?->getColumns(),
            'schema' => $this->getItemSchema($index)->toSchema(),
            ...$this->hasBlockPreviews() && $block !== null ? array_filter([
                'preview' => $block->renderPreview(is_array($data) ? $data : [], $index),
                'previewComponent' => $block->getPreviewComponent(),
            ], static fn (mixed $value): bool => $value !== null) : [],
            ...$this->getItemActionsProps((string) $index),
        ];
    }

    protected function getSchemaProps(): array
    {
        if ($this->shouldRenderDeferredPlaceholder()) {
            return [
                ...$this->getDeferredPlaceholderProps(),
                'blocks' => [],
                'blockNumbers' => (bool) $this->evaluate($this->hasBlockNumbers),
                'blockLabels' => (bool) $this->evaluate($this->hasBlockLabels),
            ];
        }

        $pickerColumns = $this->evaluate($this->blockPickerColumns);
        $blocks = [];

        foreach ($this->getBlocks(withHidden: false) as $name => $block) {
            $blocks[] = [
                'name' => $name,
                'label' => $block->getLabel(),
                'icon' => $block->getIcon(),
                ...(($description = $block->getDescription()) !== null ? ['description' => $description] : []),
                ...(($group = $block->getGroup()) !== null ? ['group' => $group] : []),
                ...(($maxItems = $block->getMaxItems()) !== null ? ['maxItems' => $maxItems] : []),
                'columns' => $block->getColumns(),
                'schema' => $this->getBlockSchema($name, self::TEMPLATE_INDEX)->toSchema(),
                'state' => $this->getBlockTemplateState($name),
                ...($this->hasBlockPreviews() && ($component = $block->getPreviewComponent()) !== null ? ['previewComponent' => $component] : []),
                // Items show a server-made summary: collapsing one refreshes it (`summary()`).
                ...($block->hasSummary() ? ['summary' => true] : []),
            ];
        }

        $previews = $this->hasBlockPreviews();

        return [
            'items' => array_map($this->serializeItem(...), array_keys($this->getItems())),
            'blocks' => $blocks,
            'blockPickerColumns' => $pickerColumns === null ? null : (is_array($pickerColumns) ? $pickerColumns : ['default' => 1, 'sm' => $pickerColumns]),
            'blockPickerWidth' => $this->getBlockPickerWidth(),
            'blockSearch' => $this->isSearchable() ?: null,
            'blockSearchDebounce' => $this->isSearchable() ? $this->evaluate($this->blockSearchDebounce) : null,
            'blockSearchPrompt' => $this->isSearchable() ? $this->getSearchPrompt() : null,
            'blockNoSearchResultsMessage' => $this->isSearchable() ? $this->getNoSearchResultsMessage() : null,
            'blockNumbers' => (bool) $this->evaluate($this->hasBlockNumbers),
            'blockIcons' => $this->evaluate($this->hasBlockIcons) ?: null,
            'blockLabels' => (bool) $this->evaluate($this->hasBlockLabels),
            'blockActionsMenu' => $this->hasBlockActionsMenu() ?: null,
            'blockPreviews' => $previews ?: null,
            'interactivePreviews' => $previews && $this->hasInteractiveBlockPreviews() ? true : null,
            ...$this->getItemControlsSchema(),
            'virtualize' => $this->getVirtualizeSchema(),
        ];
    }
}
