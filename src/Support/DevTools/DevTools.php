<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Support\DevTools;

use AlfaSix\Alfakit\Pages\Page;
use AlfaSix\Alfakit\Resources\Pages\Page as ResourcePage;
use AlfaSix\Alfakit\Resources\Resource;
use AlfaSix\Alfakit\Support\SchemaContext;
use Illuminate\Support\Str;
use ReflectionClass;
use Throwable;

/**
 * Builds the `alfakitDevTools` page prop (`Page::DEV_TOOLS_PROP`) for the schema
 * inspector: what the page's context token identifies (panel, page, route parameters,
 * component, issue and expiry times; never the token itself, the user id or anything
 * secret) and the PHP classes behind the page with "open in editor" links. Only added
 * while `Panel::hasDevTools()` is true, which needs `app.debug`.
 */
final class DevTools
{
    /**
     * @return array{context: array<string, mixed>, tenant: string|null, classes: list<array{role: string, class: string, file: string|null, line: int|null, url: string|null}>, editor: string|null}
     */
    public static function collect(Page $page): array
    {
        $context = SchemaContext::fromToken($page->getContextToken());
        $lifetime = (int) config('alfakit.token_lifetime', 1440);
        $tenant = $page->getPanel()->hasTenancy() ? alfakit()->getTenant() : null;

        return [
            'context' => [
                'panel' => $context->panel,
                'page' => $context->page,
                'parameters' => array_map(static fn (mixed $value): mixed => is_scalar($value) || $value === null ? $value : get_debug_type($value), $context->parameters),
                'component' => $context->component,
                'boundToUser' => $context->user !== null,
                'issuedAt' => $context->issuedAt,
                'expiresAt' => $lifetime > 0 && $context->issuedAt !== null ? $context->issuedAt + $lifetime * 60 : null,
            ],
            'tenant' => $tenant === null ? null : (string) $tenant->getKey(),
            'classes' => self::classes($page),
            'editor' => EditorLink::editorName(),
        ];
    }

    /**
     * The page class, its resource and the resource's model.
     *
     * @return list<array{role: string, class: string, file: string|null, line: int|null, url: string|null}>
     */
    private static function classes(Page $page): array
    {
        $classes = ['page' => $page::class];

        if ($page instanceof ResourcePage) {
            try {
                $resource = $page::getResource();

                if (is_subclass_of($resource, Resource::class)) {
                    $classes['resource'] = $resource;
                    $classes['model'] = $resource::getModel();
                }
            } catch (Throwable) {
                // A page without a resource shows only itself.
            }
        }

        $entries = [];

        foreach ($classes as $role => $class) {
            if (! class_exists($class)) {
                continue;
            }

            $reflection = new ReflectionClass($class);
            $file = $reflection->getFileName();

            $entries[] = [
                'role' => $role,
                'class' => $class,
                'file' => $file === false ? null : Str::after($file, base_path().DIRECTORY_SEPARATOR),
                'line' => $file === false ? null : (int) $reflection->getStartLine(),
                'url' => EditorLink::forClass($class),
            ];
        }

        return $entries;
    }
}
