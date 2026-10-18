<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\RichContent;

use AlfaSix\Alfakit\Actions\Action;
use AlfaSix\Alfakit\Forms\Components\RichEditor;
use AlfaSix\Alfakit\Forms\Components\RichEditor\EditorCommand;
use AlfaSix\Alfakit\Forms\Components\RichEditor\Plugins\RichContentPlugin;
use AlfaSix\Alfakit\Forms\Components\RichEditor\RichEditorTool;
use AlfaSix\Alfakit\Forms\Components\TextInput;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * A plugin with a JavaScript extension, a command tool, an action tool, a custom node and
 * an extra allowed attribute.
 */
class HighlightPlugin extends RichContentPlugin
{
    public function getTipTapJsExtensions(): array
    {
        return ['highlight-color'];
    }

    public function getEditorTools(): array
    {
        return [
            RichEditorTool::make('shout')->label('Shout')->icon('megaphone')->command('toggleMark', 'shout')->activeWhen('shout'),
            RichEditorTool::make('highlightColor')->label('Highlight colour')->action()->activeWhen('highlight'),
        ];
    }

    public function getEditorActions(): array
    {
        return [
            Action::make('highlightColor')
                ->schema([TextInput::make('color')->required()])
                ->fillForm(fn (array $arguments): array => ['color' => $arguments['attributes']['color'] ?? null])
                ->action(fn (array $data, array $arguments, RichEditor $component): array => $component->runCommands(
                    [EditorCommand::make('setMark', 'highlight', ['color' => $data['color']])],
                    editorSelection: $arguments['editorSelection'] ?? null,
                )),
        ];
    }

    public function getEnabledToolbarButtons(): array
    {
        return ['shout'];
    }

    public function getDisabledToolbarButtons(): array
    {
        return ['strike'];
    }

    public function configureHtmlSanitizer(HtmlSanitizerConfig $config): HtmlSanitizerConfig
    {
        return $config->allowElement('aside', ['data-callout']);
    }

    public function renderJson(array $node, string $content): ?string
    {
        return match ($node['type'] ?? null) {
            'callout' => '<aside data-callout="'.e((string) ($node['attrs']['tone'] ?? 'info')).'">'.$content.'</aside>',
            'shout' => '<strong data-shout>'.$content.'</strong>',
            default => null,
        };
    }
}
