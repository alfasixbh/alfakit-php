<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Forms\Components\RichEditor\Concerns;

use AlfaSix\Alfakit\Actions\Action;
use AlfaSix\Alfakit\Forms\Components\RichEditor\EditorCommand;
use AlfaSix\Alfakit\Forms\Components\RichEditor\MentionProvider;
use AlfaSix\Alfakit\Forms\Components\RichEditor\Models\Contracts\HasRichContent;
use AlfaSix\Alfakit\Forms\Components\RichEditor\Plugins\Contracts\RichContentPlugin;
use AlfaSix\Alfakit\Forms\Components\RichEditor\RichContentAttribute;
use AlfaSix\Alfakit\Forms\Components\RichEditor\RichContentCustomBlock;
use AlfaSix\Alfakit\Forms\Components\RichEditor\RichEditorTool;
use AlfaSix\Alfakit\Support\Icons;
use AlfaSix\Alfakit\Support\Labels;
use AlfaSix\Alfakit\Support\TemporaryUpload;
use Closure;
use JsonException;

/**
 * RichEditor extensions: JSON storage (`json()`), custom blocks (`customBlocks()`),
 * mentions (`mentions()`), merge tags (`mergeTags()`) and plugins (`plugins()`).
 *
 * Settings the field leaves unset come from the model's rich content attribute
 * (`HasRichContent::setUpRichContent()`) when the form's record or model has one.
 *
 * Custom blocks are field actions (`customBlock:{id}`): their modal form runs on the
 * server, which returns the block's configuration and preview. Mention searches and
 * block previews go through the select options endpoint with the field's path.
 */
trait HasRichContentExtensions
{
    protected bool|Closure|null $isJson = null;

    /** @var array<int|string, class-string<RichContentCustomBlock>|array<class-string<RichContentCustomBlock>>>|Closure|null */
    protected array|Closure|null $customBlocks = null;

    /** @var array<MentionProvider>|Closure|null */
    protected array|Closure|null $mentionProviders = null;

    /** @var array<int|string, string>|Closure|null */
    protected array|Closure|null $mergeTags = null;

    /** @var array<string, string>|Closure */
    protected array|Closure $mergeTagLabels = [];

    /** @var array<RichContentPlugin>|Closure|null */
    protected array|Closure|null $plugins = null;

    /** @var array<string, RichContentAttribute|null> */
    private array $richContentAttributeCache = [];

    /**
     * Store the content as a Tiptap JSON document (an array; cast the attribute to
     * `array`) instead of HTML.
     */
    public function json(bool|Closure $condition = true): static
    {
        $this->isJson = $condition;

        return $this;
    }

    public function isJson(): bool
    {
        return (bool) ($this->evaluate($this->isJson) ?? $this->getRichContentAttribute()?->isJson());
    }

    /**
     * The model's rich content configuration for this field's attribute
     * (`registerRichContent()` in `setUpRichContent()`), or null.
     */
    public function getRichContentAttribute(): ?RichContentAttribute
    {
        if (! $this->hasContainer()) {
            return null;
        }

        $record = $this->getRecord();
        $key = $record !== null ? 'record:'.spl_object_id($record) : 'model:'.$this->getModel();

        if (array_key_exists($key, $this->richContentAttributeCache)) {
            return $this->richContentAttributeCache[$key];
        }

        $model = $record ?? $this->getModelInstance();

        return $this->richContentAttributeCache[$key] = $model instanceof HasRichContent
            ? $model->getRichContentAttribute($this->getName())
            : null;
    }

    /**
     * String keys group the blocks in the custom blocks panel:
     * `['Marketing' => [HeroBlock::class, CtaBlock::class], DividerBlock::class]`.
     *
     * @param  array<int|string, class-string<RichContentCustomBlock>|array<class-string<RichContentCustomBlock>>>|Closure  $blocks
     */
    public function customBlocks(array|Closure $blocks): static
    {
        $this->customBlocks = $blocks;

        return $this;
    }

    /**
     * @return array<string, class-string<RichContentCustomBlock>> Keyed by block id
     */
    public function getCustomBlocks(): array
    {
        return array_map(static fn (array $block): string => $block[0], $this->getGroupedCustomBlocks());
    }

    /**
     * @return array<string, array{0: class-string<RichContentCustomBlock>, 1: string|null}> id => [class, group]
     */
    protected function getGroupedCustomBlocks(): array
    {
        $blocks = [];
        $add = static function (mixed $block, ?string $group) use (&$blocks): void {
            if (is_string($block) && is_subclass_of($block, RichContentCustomBlock::class)) {
                $blocks[$block::getId()] = [$block, $group];
            }
        };

        $configured = $this->evaluate($this->customBlocks) ?? $this->getRichContentAttribute()?->getCustomBlocks() ?? [];

        // Ungrouped blocks first, then the groups in their order.
        foreach ($configured as $block) {
            if (! is_array($block)) {
                $add($block, null);
            }
        }

        foreach ($configured as $group => $groupBlocks) {
            if (is_array($groupBlocks)) {
                foreach ($groupBlocks as $block) {
                    $add($block, is_string($group) ? $group : null);
                }
            }
        }

        return $blocks;
    }

    /**
     * @param  array<MentionProvider>|Closure  $providers
     */
    public function mentions(array|Closure $providers): static
    {
        $this->mentionProviders = $providers;

        return $this;
    }

    /**
     * @return array<string, MentionProvider> Keyed by trigger character
     */
    public function getMentionProviders(): array
    {
        $providers = [];

        foreach ($this->evaluate($this->mentionProviders) ?? $this->getRichContentAttribute()?->getMentions() ?? [] as $provider) {
            if ($provider instanceof MentionProvider) {
                $providers[$provider->getChar()] = $provider;
            }
        }

        return $providers;
    }

    public function getMentionProvider(string $char): ?MentionProvider
    {
        return $this->getMentionProviders()[$char] ?? null;
    }

    /**
     * Placeholders stored as `{{ id }}` and replaced by `RichContentRenderer::mergeTags()`.
     *
     * @param  array<int|string, string>|Closure  $tags  `['name', 'today']` or `['name' => 'Full name']`
     */
    public function mergeTags(array|Closure $tags): static
    {
        $this->mergeTags = $tags;

        return $this;
    }

    /**
     * What the editor shows for merge tags given as a list: `['name' => 'Full name']`.
     * Display only; the content stores the id.
     *
     * @param  array<string, string>|Closure  $labels
     */
    public function mergeTagLabels(array|Closure $labels): static
    {
        $this->mergeTagLabels = $labels;

        return $this;
    }

    /**
     * @return array<string, string> id => label
     */
    public function getMergeTags(): array
    {
        $labels = $this->evaluate($this->mergeTagLabels) ?? [];
        $configured = $this->evaluate($this->mergeTags);

        if ($configured === null) {
            $configured = $this->getRichContentAttribute()?->getMergeTagLabels() ?? [];
        }

        $tags = [];

        foreach ($configured as $key => $value) {
            if (is_int($key)) {
                $tags[(string) $value] = (string) ($labels[(string) $value] ?? Labels::fromName((string) $value));
            } else {
                $tags[$key] = (string) $value;
            }
        }

        return $tags;
    }

    /**
     * Rich content plugins: Tiptap extensions, toolbar tools and editor actions
     * (`RichContentPlugin`).
     *
     * @param  array<RichContentPlugin>|Closure  $plugins
     */
    public function plugins(array|Closure $plugins): static
    {
        $this->plugins = $plugins;

        return $this;
    }

    /**
     * @return array<RichContentPlugin>
     */
    public function getPlugins(): array
    {
        $plugins = $this->evaluate($this->plugins) ?? $this->getRichContentAttribute()?->getPlugins() ?? [];

        return array_values(array_filter($plugins, static fn (mixed $plugin): bool => $plugin instanceof RichContentPlugin));
    }

    /**
     * @return array<string, RichEditorTool> Keyed by name
     */
    public function getEditorTools(): array
    {
        $tools = [];

        foreach ($this->getPlugins() as $plugin) {
            foreach ($plugin->getEditorTools() as $tool) {
                $tools[$tool->getName()] = $tool;
            }
        }

        return $tools;
    }

    /**
     * The result of a plugin editor action that changes the content: the browser runs the
     * commands on the selection the action was opened with.
     *
     *     ->action(fn (array $data, array $arguments, RichEditor $component) => $component->runCommands(
     *         [EditorCommand::make('setMark', 'highlight', ['color' => $data['color']])],
     *         editorSelection: $arguments['editorSelection'] ?? null,
     *     ))
     *
     * @param  array<EditorCommand>  $commands
     * @param  array<string, mixed>|null  $editorSelection
     * @return array{editorCommands: list<list<mixed>>, editorSelection: array<string, mixed>|null}
     */
    public function runCommands(array $commands, ?array $editorSelection = null): array
    {
        return [
            'editorCommands' => array_values(array_map(static fn (EditorCommand $command): array => $command->toArray(), $commands)),
            'editorSelection' => $editorSelection,
        ];
    }

    public function getCustomBlockActionName(string $id): string
    {
        return 'customBlock:'.$id;
    }

    /**
     * The custom block modals, run as field actions.
     *
     * @return array<Action>
     */
    protected function getExtraFieldActions(): array
    {
        return [
            ...array_values(array_map(fn (string $block): Action => $this->makeCustomBlockAction($block), $this->getCustomBlocks())),
            ...$this->getPluginEditorActions(),
        ];
    }

    /**
     * Editor actions of the plugins whose tool opens them (`RichEditorTool::action()`).
     *
     * @return array<string, Action> Keyed by tool name
     */
    protected function getPluginEditorActions(): array
    {
        $tools = array_filter($this->getEditorTools(), static fn (RichEditorTool $tool): bool => $tool->runsAction());

        if ($tools === []) {
            return [];
        }

        $actions = [];

        foreach ($this->getPlugins() as $plugin) {
            foreach ($plugin->getEditorActions() as $action) {
                if (isset($tools[$action->getName()])) {
                    $actions[$action->getName()] = $action->visible(fn (): bool => ! $this->isDisabled() && ! $this->isReadOnly());
                }
            }
        }

        return $actions;
    }

    /**
     * @param  class-string<RichContentCustomBlock>  $block
     */
    protected function makeCustomBlockAction(string $block): Action
    {
        $id = $block::getId();
        $isEditing = static fn (array $arguments): bool => is_array($arguments['config'] ?? null) && ($arguments['editing'] ?? false);

        $action = Action::make($this->getCustomBlockActionName($id))
            ->label($block::getLabel())
            ->icon($block::getIcon())
            ->modalHeading(fn (array $arguments): string => __($isEditing($arguments) ? 'alfakit::forms.rich_editor.edit_block' : 'alfakit::forms.rich_editor.insert_block', ['block' => $block::getLabel()]))
            ->modalSubmitActionLabel(fn (array $arguments): string => __($isEditing($arguments) ? 'alfakit::forms.rich_editor.save_block' : 'alfakit::forms.rich_editor.insert'))
            ->fillForm(static fn (array $arguments): array => is_array($arguments['config'] ?? null) ? $arguments['config'] : [])
            ->visible(fn (): bool => ! $this->isDisabled() && ! $this->isReadOnly())
            ->action(static fn (array $data): array => [
                'id' => $id,
                'config' => $data,
                'preview' => $block::toPreviewHtml($data),
                'prose' => $block::shouldApplyProseStylingToPreview($data),
            ]);

        return $block::configureEditorAction($action);
    }

    /**
     * Server-side mention search for the provider of `$char`.
     *
     * @return array<int, array{id: string, label: string}>|null Null when no such provider
     */
    public function getMentionSearchResults(string $char, string $search): ?array
    {
        $provider = $this->getMentionProvider($char);

        if ($provider === null) {
            return null;
        }

        $results = [];

        foreach ($provider->getSearchResults($search) as $id => $label) {
            $results[] = ['id' => (string) $id, 'label' => $label];
        }

        return $results;
    }

    /**
     * The editor preview of a custom block with the given configuration, or null when the
     * field has no such block.
     *
     * @param  array<string, mixed>  $config
     */
    public function getCustomBlockPreview(string $id, array $config): ?string
    {
        $block = $this->getCustomBlocks()[$id] ?? null;

        return $block === null ? null : $block::toPreviewHtml($config);
    }

    /**
     * Whether the preview of block `$id` gets the editor's prose styles
     * (`RichContentCustomBlock::shouldApplyProseStylingToPreview()`).
     *
     * @param  array<string, mixed>  $config
     */
    public function shouldApplyProseStylingToCustomBlockPreview(string $id, array $config): bool
    {
        $block = $this->getCustomBlocks()[$id] ?? null;

        return $block !== null && $block::shouldApplyProseStylingToPreview($config);
    }

    /**
     * @return array<string, mixed>
     */
    protected function getRichContentSchemaProps(): array
    {
        $blocks = [];
        $groups = array_map(static fn (array $block): ?string => $block[1], $this->getGroupedCustomBlocks());

        foreach ($this->getCustomBlocks() as $id => $block) {
            $action = $this->prepareFieldAction($this->makeCustomBlockAction($block));

            if (! $action->isVisible()) {
                continue;
            }

            $blocks[] = array_filter([
                'id' => $id,
                'label' => $block::getLabel(),
                'group' => $groups[$id] ?? null,
                // Subclasses override `getIcon()`, so aliases are resolved here.
                'icon' => Icons::resolve($block::getIcon()),
                'action' => $action->toSchema(),
            ], static fn (mixed $value): bool => $value !== null);
        }

        $tools = [];
        $actions = $this->getPluginEditorActions();

        foreach ($this->getEditorTools() as $name => $tool) {
            $schema = $tool->toSchema();

            if ($tool->runsAction()) {
                $action = isset($actions[$name]) ? $this->prepareFieldAction($actions[$name]) : null;

                if ($action === null || ! $action->isVisible()) {
                    unset($schema['action']);
                } else {
                    $schema['action'] = $action->toSchema();
                }
            }

            $tools[] = $schema;
        }

        $extensions = [];

        foreach ($this->getPlugins() as $plugin) {
            array_push($extensions, ...array_filter($plugin->getTipTapJsExtensions(), 'is_string'));
        }

        $mergeTags = [];

        foreach ($this->getMergeTags() as $id => $label) {
            $mergeTags[] = ['id' => (string) $id, 'label' => $label];
        }

        return [
            'json' => $this->isJson() ?: null,
            'customBlocks' => $blocks ?: null,
            'mentions' => array_values(array_map(static fn (MentionProvider $provider): array => $provider->toSchema(), $this->getMentionProviders())) ?: null,
            'mergeTags' => $mergeTags ?: null,
            'editorTools' => $tools ?: null,
            'extensions' => array_values(array_unique($extensions)) ?: null,
        ];
    }

    /**
     * Toolbar buttons for the extensions, appended when they are configured but not
     * placed in `toolbarButtons()` explicitly.
     *
     * Plugins' enabled buttons are appended and their disabled ones removed first.
     *
     * @param  array<mixed>  $buttons
     * @return array<mixed>
     */
    protected function withExtensionToolbarButtons(array $buttons): array
    {
        $extra = [];

        if ($buttons !== []) {
            foreach ($this->getPlugins() as $plugin) {
                $disabled = $plugin->getDisabledToolbarButtons();
                $buttons = array_values(array_filter($buttons, static fn (mixed $button): bool => ! is_string($button) || ! in_array($button, $disabled, true)));

                foreach ($plugin->getEnabledToolbarButtons() as $button) {
                    if (! in_array($button, static::flattenToolbarButtons($buttons), true) && ! in_array($button, $extra, true)) {
                        $extra[] = $button;
                    }
                }
            }
        }

        $names = static::flattenToolbarButtons($buttons);

        if ($this->getCustomBlocks() !== [] && ! in_array('customBlocks', $names, true)) {
            $extra[] = 'customBlocks';
        }

        if ($this->getMergeTags() !== [] && ! in_array('mergeTags', $names, true)) {
            $extra[] = 'mergeTags';
        }

        if ($extra === [] || $buttons === []) {
            return $buttons;
        }

        return [...$buttons, '|', ...$extra];
    }

    /**
     * JSON documents: move `tmp:` images to the attachments disk, like HTML content.
     *
     * @param  array<string, mixed>  $document
     * @return array<string, mixed>
     */
    protected function storeJsonFileAttachments(array $document): array
    {
        try {
            $encoded = json_encode($document, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException) {
            return $document;
        }

        if (! str_contains($encoded, TemporaryUpload::PREFIX) || $this->shouldDeferFileAttachments()) {
            return $document;
        }

        return $this->mapImageNodes($document);
    }

    /**
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>|null Null drops the node (an image whose upload is gone)
     */
    private function mapImageNodes(array $node): ?array
    {
        if (($node['type'] ?? null) === 'image') {
            $src = $node['attrs']['src'] ?? null;

            if (is_string($src) && str_starts_with($src, TemporaryUpload::PREFIX)) {
                $url = $this->storeFileAttachment($src);

                if ($url === null) {
                    return null;
                }

                $node['attrs']['src'] = $url;
            }

            return $node;
        }

        if (is_array($node['content'] ?? null)) {
            $content = [];

            foreach ($node['content'] as $child) {
                if (is_array($child) && ($mapped = $this->mapImageNodes($child)) !== null) {
                    $content[] = $mapped;
                }
            }

            $node['content'] = $content;
        }

        return $node;
    }

    /**
     * A document with no text and nothing but empty paragraphs.
     *
     * @param  array<string, mixed>  $node
     */
    protected function isEmptyDocument(array $node): bool
    {
        $type = $node['type'] ?? null;

        if ($type === 'text') {
            return trim((string) ($node['text'] ?? '')) === '';
        }

        if (! in_array($type, ['doc', 'paragraph', 'hardBreak', null], true)) {
            return false;
        }

        foreach (is_array($node['content'] ?? null) ? $node['content'] : [] as $child) {
            if (is_array($child) && ! $this->isEmptyDocument($child)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Dehydrate JSON content (an array, or a JSON string from an uncast attribute).
     *
     * @return array<string, mixed>|null
     */
    protected function dehydrateJsonContent(mixed $state): ?array
    {
        if (is_string($state)) {
            try {
                $state = json_decode($state, true, flags: JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                return null;
            }
        }

        if (! is_array($state) || $this->isEmptyDocument($state)) {
            return null;
        }

        return $this->storeJsonFileAttachments($state);
    }
}
