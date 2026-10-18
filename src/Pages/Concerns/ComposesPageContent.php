<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Pages\Concerns;

use AlfaSix\Alfakit\Schemas\Components\Component;
use AlfaSix\Alfakit\Schemas\Components\EmbeddedRelationManagers;
use AlfaSix\Alfakit\Schemas\Components\EmbeddedSchema;
use AlfaSix\Alfakit\Schemas\Components\EmbeddedTable;

/**
 * Resource pages honour `content()` like Filament: top-level markers (`EmbeddedTable`,
 * `EmbeddedSchema::make('form' | 'infolist')`, `EmbeddedRelationManagers`, returned by
 * `getFormContentComponent()` and friends) place the page's own blocks, and the other
 * components are rendered around them. Content without any marker is shown above the
 * page's usual blocks; a page without `content()` renders exactly as before.
 */
trait ComposesPageContent
{
    /**
     * @param  array<string, array<string, mixed>|null>  $embedded  The page's own blocks by marker key (`table`, `form`, `infolist`, `relationManagers`), in their default order
     * @return array<array<string, mixed>>
     */
    protected function composeContentBlocks(array $embedded): array
    {
        $content = $this->getContentSchema();

        if ($content === null) {
            return array_values(array_filter($embedded));
        }

        $components = $content->getComponents();
        $hasMarker = false;

        foreach ($components as $component) {
            if ($this->getContentMarkerKey($component) !== null) {
                $hasMarker = true;

                break;
            }
        }

        if (! $hasMarker) {
            return array_values(array_filter([
                $components === [] ? null : $this->contentSchemaBlock('content-before', $components),
                ...array_values($embedded),
            ]));
        }

        // Split the content into runs of ordinary components and the page's own blocks.
        $segments = [];
        $buffer = [];
        $placed = [];

        foreach ($components as $component) {
            $marker = $this->getContentMarkerKey($component);

            if ($marker === null) {
                $buffer[] = $component;

                continue;
            }

            // Each of the page's blocks is placed once, where its first marker is; a
            // marker for a block the page doesn't have (no relation managers) is skipped.
            if (isset($placed[$marker]) || ($embedded[$marker] ?? null) === null) {
                continue;
            }

            if ($buffer !== []) {
                $segments[] = ['components' => $buffer];
                $buffer = [];
            }

            $placed[$marker] = true;
            $segments[] = ['block' => $embedded[$marker]];
        }

        if ($buffer !== []) {
            $segments[] = ['components' => $buffer];
        }

        // Content before the first placed block is `content-before`; later runs are
        // `content-after`, `content-after-2`…
        $blocks = [];
        $after = 0;
        $seenBlock = false;

        foreach ($segments as $segment) {
            if (isset($segment['block'])) {
                $blocks[] = $segment['block'];
                $seenBlock = true;

                continue;
            }

            $key = ! $seenBlock ? 'content-before' : (++$after === 1 ? 'content-after' : 'content-after-'.$after);
            $blocks[] = $this->contentSchemaBlock($key, $segment['components'] ?? []);
        }

        return $blocks;
    }

    /**
     * The block key a top-level content component stands for, or null for ordinary content.
     */
    protected function getContentMarkerKey(Component $component): ?string
    {
        return match (true) {
            $component instanceof EmbeddedTable => 'table',
            $component instanceof EmbeddedSchema => $component->getName(),
            $component instanceof EmbeddedRelationManagers => 'relationManagers',
            default => null,
        };
    }

    /**
     * @param  array<Component>  $components
     * @return array<string, mixed>
     */
    protected function contentSchemaBlock(string $key, array $components): array
    {
        return ['type' => 'Schema', 'key' => $key, 'components' => array_map(static fn (Component $component): array => $component->toSchema(), $components)];
    }
}
