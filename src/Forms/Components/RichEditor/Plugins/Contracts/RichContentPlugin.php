<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Forms\Components\RichEditor\Plugins\Contracts;

use AlfaSix\Alfakit\Actions\Action;
use AlfaSix\Alfakit\Forms\Components\RichEditor\RichEditorTool;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * Extends `RichEditor` (`->plugins([MyPlugin::make()])`) and `RichContentRenderer` with
 * Tiptap extensions, toolbar tools and editor actions. Extend
 * {@see \AlfaSix\Alfakit\Forms\Components\RichEditor\Plugins\RichContentPlugin} for
 * empty defaults.
 *
 * The browser half lives in your frontend bundle: register each Tiptap extension under the
 * key returned by `getTipTapJsExtensions()` with
 * `createAlfakit({ richEditorExtensions: { key: () => import('./my-extension') } })`.
 */
interface RichContentPlugin
{
    /**
     * Registry keys of the Tiptap extensions the editor loads (lazily) for this plugin.
     *
     * @return array<string>
     */
    public function getTipTapJsExtensions(): array;

    /**
     * Toolbar buttons the plugin adds. They are available by name in `toolbarButtons()`
     * and `floatingToolbars()`; they are not shown until placed there (or returned by
     * `getEnabledToolbarButtons()`).
     *
     * @return array<RichEditorTool>
     */
    public function getEditorTools(): array;

    /**
     * Modal actions opened by tools with `->action()`; each is named like its tool. Return
     * `EditorCommand`s from the action to change the content
     * (`fn (array $data) => [EditorCommand::make('setMark', 'highlight', $data)]`).
     *
     * @return array<Action>
     */
    public function getEditorActions(): array;

    /**
     * Buttons appended to the editor's toolbar.
     *
     * @return array<string>
     */
    public function getEnabledToolbarButtons(): array;

    /**
     * Buttons removed from the editor's toolbar.
     *
     * @return array<string>
     */
    public function getDisabledToolbarButtons(): array;

    /**
     * Allow the elements and attributes the plugin's nodes and marks store. The sanitiser
     * stays strict otherwise: add only what you need (no event handlers, no `style`).
     */
    public function configureHtmlSanitizer(HtmlSanitizerConfig $config): HtmlSanitizerConfig;

    /**
     * HTML for one of the plugin's nodes or marks in JSON content (`RichEditor::json()`),
     * or null when the node isn't the plugin's. Escape every attribute you output.
     *
     * @param  array<string, mixed>  $node  A Tiptap node (`type`, `attrs`, `content`) or mark
     * @param  string  $content  The rendered children (nodes) or the marked HTML (marks)
     */
    public function renderJson(array $node, string $content): ?string;
}
