<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Forms\Components\RichEditor;

use AlfaSix\Alfakit\Actions\Action;
use Illuminate\Support\Str;

/**
 * A custom block for `RichEditor::customBlocks()`. The editor stores the block's id and
 * configuration (`config`); the modal form of `configureEditorAction()` edits the
 * configuration, `toPreviewHtml()` renders it inside the editor and `toHtml()` when the
 * content is rendered with `RichContentRenderer`.
 *
 * The configuration comes from the browser: escape every value you put in the HTML (the
 * defaults do).
 */
abstract class RichContentCustomBlock
{
    /**
     * Stored with the block. Defaults to the class name without `Block` (`HeroBlock` → `hero`).
     */
    public static function getId(): string
    {
        return Str::kebab(static::getBaseName());
    }

    public static function getLabel(): string
    {
        return Str::headline(static::getBaseName());
    }

    protected static function getBaseName(): string
    {
        $name = class_basename(static::class);

        return str_ends_with($name, 'Block') && $name !== 'Block' ? substr($name, 0, -5) : $name;
    }

    public static function getIcon(): ?string
    {
        return null;
    }

    /**
     * Configure the modal that inserts and edits the block: give it a `schema([...])` for
     * a configuration form, a heading, a width… Without a schema the block is inserted
     * as is.
     */
    public static function configureEditorAction(Action $action): Action
    {
        return $action;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public static function toPreviewHtml(array $config): string
    {
        return '<div class="alfakit-custom-block-preview"><strong>'.e(static::getLabel()).'</strong></div>';
    }

    /**
     * Whether the editor's prose styles (headings, paragraphs, lists…) apply to the preview
     * of this configuration. Off by default, so the preview's own markup shows as is.
     *
     * @param  array<string, mixed>  $config
     */
    public static function shouldApplyProseStylingToPreview(array $config): bool
    {
        return false;
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $data  Extra data passed to `RichContentRenderer::customBlocks()`
     */
    public static function toHtml(array $config, array $data): string
    {
        return static::toPreviewHtml($config);
    }
}
