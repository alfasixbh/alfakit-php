<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Forms\Components\RichEditor\Plugins;

use AlfaSix\Alfakit\Forms\Components\RichEditor\Plugins\Contracts\RichContentPlugin as RichContentPluginContract;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * A rich content plugin with empty defaults: override what the plugin needs.
 *
 *     class HighlightPlugin extends RichContentPlugin
 *     {
 *         public function getTipTapJsExtensions(): array
 *         {
 *             return ['highlight-color'];
 *         }
 *
 *         public function getEditorTools(): array
 *         {
 *             return [RichEditorTool::make('highlightColor')->icon('highlighter')->action()];
 *         }
 *     }
 */
abstract class RichContentPlugin implements RichContentPluginContract
{
    public static function make(): static
    {
        return app(static::class);
    }

    public function getTipTapJsExtensions(): array
    {
        return [];
    }

    public function getEditorTools(): array
    {
        return [];
    }

    public function getEditorActions(): array
    {
        return [];
    }

    public function getEnabledToolbarButtons(): array
    {
        return [];
    }

    public function getDisabledToolbarButtons(): array
    {
        return [];
    }

    public function configureHtmlSanitizer(HtmlSanitizerConfig $config): HtmlSanitizerConfig
    {
        return $config;
    }

    public function renderJson(array $node, string $content): ?string
    {
        return null;
    }
}
