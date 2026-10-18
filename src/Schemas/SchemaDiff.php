<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Schemas;

use Illuminate\Support\Arr;
use stdClass;

/**
 * Partial re-rendering for live forms. A form payload carries a fingerprint per component
 * (keyed by its index path, e.g. `0.2`): a hash of the component's own properties plus
 * the keys of its children, and a hash of its whole subtree. The browser sends the
 * fingerprints it holds with a refresh, and the response then contains only the
 * components that changed (as patches at their index path) and the top-level state keys
 * that differ from the state the browser sent. When the root structure changed, or the
 * browser sent no fingerprints, the full payload is returned instead.
 */
final class SchemaDiff
{
    /** Fingerprint entry of the root list (the keys of the top-level components). */
    public const ROOT = '';

    /**
     * Whether a serialised tree can be refreshed or changed by a server round trip (it has
     * live fields or field actions), so fingerprints are worth sending.
     *
     * @param  array<int, array<string, mixed>>  $components
     */
    public static function isReactive(array $components): bool
    {
        foreach ($components as $component) {
            if (isset($component['live']) || isset($component['prefixActions']) || isset($component['suffixActions']) || isset($component['hintActions'])) {
                return true;
            }

            foreach (self::nestedTrees($component) as $tree) {
                if (self::isReactive($tree)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  array<int, array<string, mixed>>  $components
     * @return array<int|string, string>
     */
    public static function fingerprints(array $components): array
    {
        $fingerprints = [self::ROOT => self::hash(self::keysOf($components))];

        self::collect($components, '', $fingerprints);

        return $fingerprints;
    }

    /**
     * The components that differ from the fingerprints the browser holds, as
     * `[path, component]` patches, or null when the browser must replace the whole tree.
     *
     * @param  array<int, array<string, mixed>>  $components
     * @param  array<string, mixed>  $previous
     * @return array<int, array{path: string, component: array<string, mixed>}>|null
     */
    public static function diff(array $components, array $previous): ?array
    {
        if (($previous[self::ROOT] ?? null) !== self::hash(self::keysOf($components))) {
            return null;
        }

        $current = [];
        self::collect($components, '', $current);

        $patches = [];
        self::compare($components, '', $current, $previous, $patches);

        return $patches;
    }

    /**
     * Top-level state keys whose value differs from what the browser sent, and keys the
     * browser sent that the server no longer returns (fields that became hidden).
     *
     * @param  array<string, mixed>  $state
     * @param  array<string, mixed>  $clientState
     * @return array{0: array<string, mixed>, 1: array<int, string>}
     */
    public static function diffState(array $state, array $clientState): array
    {
        $changed = [];

        foreach ($state as $key => $value) {
            if (! array_key_exists($key, $clientState) || self::normalise($clientState[$key]) !== self::normalise($value)) {
                $changed[$key] = $value;
            }
        }

        $removed = array_values(array_map('strval', array_diff(array_keys($clientState), array_keys($state))));

        return [$changed, $removed];
    }

    /**
     * A refresh response: partial when the browser sent fingerprints and the root
     * structure is unchanged, the full payload otherwise.
     *
     * @param  array<string, mixed>  $payload  `Schema::toPayload()`
     * @param  array<string, mixed>|null  $fingerprints  The browser's fingerprints
     * @param  array<string, mixed>|null  $clientState  The state the browser sent
     * @param  array<int, string>|null  $only  Re-send only these components (state paths or
     *                                         keys; `partiallyRenderComponentsAfterStateUpdated()`),
     *                                         none for `[]`, or every changed one for null
     * @return array<string, mixed>
     */
    public static function respond(array $payload, ?array $fingerprints, ?array $clientState, ?array $only = null): array
    {
        $components = $payload['components'] ?? [];

        if ($fingerprints === null || $fingerprints === []) {
            return $payload;
        }

        if ($only !== null) {
            [$patches, $merged] = self::limitedDiff($components, $fingerprints, $only);
        } else {
            $patches = self::diff($components, $fingerprints);
            $merged = $payload['fingerprints'] ?? self::fingerprints($components);
        }

        if ($patches === null) {
            return $payload;
        }

        $response = [
            'partial' => true,
            'patches' => $patches,
            'fingerprints' => $merged,
            'columns' => $payload['columns'] ?? null,
        ];

        $state = (array) ($payload['state'] ?? []);

        if ($clientState === null) {
            $response['state'] = $state === [] ? new stdClass : $state;

            return $response;
        }

        [$changed, $removed] = self::diffState($state, $clientState);

        $response['partialState'] = true;
        $response['state'] = $changed === [] ? new stdClass : $changed;
        $response['removedState'] = $removed;

        return $response;
    }

    /**
     * Patches for the `$only` components alone (whether or not anything else changed), and
     * the browser's fingerprints with theirs updated: the components that were not re-sent
     * keep the fingerprints of what the browser still shows. A target nested in a
     * Repeater item re-sends the Repeater (the smallest component with an index path).
     *
     * @param  array<int, array<string, mixed>>  $components
     * @param  array<string, mixed>  $previous
     * @param  array<int, string>  $only
     * @return array{0: array<int, array{path: string, component: array<string, mixed>}>, 1: array<int|string, mixed>}
     */
    public static function limitedDiff(array $components, array $previous, array $only): array
    {
        $current = [];
        self::collect($components, '', $current);

        $targets = [];
        self::findTargets($components, '', $only, $targets);

        $patches = [];
        $merged = $previous;

        foreach ($targets as $path => $component) {
            $path = (string) $path;

            if (($previous[$path] ?? null) === $current[$path]) {
                continue;
            }

            $patches[] = ['path' => $path, 'component' => $component];

            // Index paths like `1` become integer array keys: compare them as strings.
            foreach (array_keys($merged) as $key) {
                if ((string) $key === $path || str_starts_with((string) $key, $path.'.')) {
                    unset($merged[$key]);
                }
            }

            foreach ($current as $key => $fingerprint) {
                if ((string) $key === $path || str_starts_with((string) $key, $path.'.')) {
                    $merged[$key] = $fingerprint;
                }
            }
        }

        return [$patches, $merged];
    }

    /**
     * @param  array<int, array<string, mixed>>  $components
     * @param  array<int, string>  $only
     * @param  array<int|string, array<string, mixed>>  $into
     */
    private static function findTargets(array $components, string $prefix, array $only, array &$into): void
    {
        foreach (array_values($components) as $index => $component) {
            $path = $prefix === '' ? (string) $index : "{$prefix}.{$index}";
            $statePath = is_string($component['statePath'] ?? null) ? $component['statePath'] : null;
            $key = is_string($component['key'] ?? null) ? $component['key'] : null;

            $matches = ($statePath !== null && in_array($statePath, $only, true)) || ($key !== null && in_array($key, $only, true));

            if (! $matches && $statePath !== null && self::childrenOf($component) === []) {
                // A field inside one of this component's items (a Repeater's `items.0.total`).
                foreach ($only as $target) {
                    if (str_starts_with($target, $statePath.'.')) {
                        $matches = true;

                        break;
                    }
                }
            }

            if ($matches) {
                $into[$path] = $component;

                continue;
            }

            self::findTargets(self::childrenOf($component), $path, $only, $into);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $components
     * @param  array<int|string, string>  $into
     * @return array<int, string> The subtree hashes of `$components`
     */
    private static function collect(array $components, string $prefix, array &$into): array
    {
        $subtrees = [];

        foreach (array_values($components) as $index => $component) {
            $path = $prefix === '' ? (string) $index : "{$prefix}.{$index}";
            $children = self::childrenOf($component);
            $own = self::hash([Arr::except($component, ['schema']), self::keysOf($children)]);
            $subtree = self::hash([$own, self::collect($children, $path, $into)]);

            $into[$path] = "{$own}:{$subtree}";
            $subtrees[] = $subtree;
        }

        return $subtrees;
    }

    /**
     * @param  array<int, array<string, mixed>>  $components
     * @param  array<int|string, string>  $current
     * @param  array<string, mixed>  $previous
     * @param  array<int, array{path: string, component: array<string, mixed>}>  $patches
     */
    private static function compare(array $components, string $prefix, array $current, array $previous, array &$patches): void
    {
        foreach (array_values($components) as $index => $component) {
            $path = $prefix === '' ? (string) $index : "{$prefix}.{$index}";
            $old = $previous[$path] ?? null;

            if ($old === $current[$path]) {
                continue;
            }

            $own = explode(':', $current[$path])[0];
            $oldOwn = is_string($old) ? explode(':', $old)[0] : null;
            $children = self::childrenOf($component);

            // Same component with the same children: only something inside changed.
            if ($oldOwn === $own && $children !== []) {
                self::compare($children, $path, $current, $previous, $patches);

                continue;
            }

            $patches[] = ['path' => $path, 'component' => $component];
        }
    }

    /**
     * @param  array<string, mixed>  $component
     * @return array<int, array<string, mixed>>
     */
    private static function childrenOf(array $component): array
    {
        $children = $component['schema'] ?? [];

        return is_array($children) ? array_values(array_filter($children, 'is_array')) : [];
    }

    /**
     * Component trees nested in properties (Repeater items and templates).
     *
     * @param  array<string, mixed>  $component
     * @return array<int, array<int, array<string, mixed>>>
     */
    private static function nestedTrees(array $component): array
    {
        $trees = [self::childrenOf($component)];

        foreach ((array) ($component['items'] ?? []) as $item) {
            if (is_array($item) && is_array($item['schema'] ?? null)) {
                $trees[] = $item['schema'];
            }
        }

        if (is_array($component['template'] ?? null)) {
            $trees[] = $component['template'];
        }

        return $trees;
    }

    /**
     * @param  array<int, array<string, mixed>>  $components
     * @return array<int, mixed>
     */
    private static function keysOf(array $components): array
    {
        return array_map(static fn (array $component): mixed => [$component['type'] ?? null, $component['key'] ?? null], array_values($components));
    }

    private static function hash(mixed $value): string
    {
        return hash('xxh3', (string) json_encode($value, JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Compare state the way the browser sees it: as JSON.
     */
    private static function normalise(mixed $value): string
    {
        return (string) json_encode($value, JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE);
    }
}
