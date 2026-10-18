<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Support\DevTools;

use Illuminate\Foundation\Concerns\ResolvesDumpSource;
use ReflectionClass;

/**
 * "Open in editor" links, built like Laravel's exception page and `dump()` sources: from
 * `config('app.editor')` (`vscode`, `phpstorm`, `cursor`… or `['href' => '…{file}…{line}',
 * 'base_path' => '/in/container']`), falling back to Ignition's `ignition.editor`. Null
 * when no editor is configured.
 */
final class EditorLink
{
    use ResolvesDumpSource;

    /**
     * @param  class-string  $class
     */
    public static function forClass(string $class): ?string
    {
        if (! class_exists($class)) {
            return null;
        }

        $reflection = new ReflectionClass($class);
        $file = $reflection->getFileName();

        return $file === false ? null : self::forFile($file, (int) $reflection->getStartLine());
    }

    public static function forFile(string $file, int $line = 1): ?string
    {
        $fallback = config('app.editor') === null && is_string($ignition = config('ignition.editor')) && $ignition !== '';

        if ($fallback) {
            config(['app.editor' => $ignition]);
        }

        try {
            $href = (new self)->resolveSourceHref($file, $line);
        } finally {
            if ($fallback) {
                config(['app.editor' => null]);
            }
        }

        return is_string($href) && $href !== '' ? $href : null;
    }

    /** The configured editor's name, for the link title. */
    public static function editorName(): ?string
    {
        $editor = config('app.editor') ?? config('ignition.editor');

        return match (true) {
            is_string($editor) && $editor !== '' => $editor,
            is_array($editor) && is_string($editor['name'] ?? null) => $editor['name'],
            is_array($editor) => 'editor',
            default => null,
        };
    }
}
