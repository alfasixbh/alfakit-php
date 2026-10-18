<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Panel\Concerns;

use Closure;
use InvalidArgumentException;

/**
 * Where resource Create and Edit pages go after saving, for every resource of the panel:
 *
 * ```php
 * $panel
 *     ->resourceCreatePageRedirect('index') // back to the list after creating
 *     ->resourceEditPageRedirect('view');   // to the View page after saving
 * ```
 *
 * `index`, `view` or `edit`. A target the resource has no page for, or the user may not
 * open for the record, falls back to the page's default. A page's own `getRedirectUrl()`
 * override still wins.
 */
trait HasResourceRedirects
{
    protected string|Closure|null $resourceCreatePageRedirect = null;

    protected string|Closure|null $resourceEditPageRedirect = null;

    /**
     * @param  'index'|'view'|'edit'|Closure|null  $page
     */
    public function resourceCreatePageRedirect(string|Closure|null $page): static
    {
        $this->resourceCreatePageRedirect = self::assertResourceRedirectTarget($page);

        return $this;
    }

    /**
     * @param  'index'|'view'|'edit'|Closure|null  $page
     */
    public function resourceEditPageRedirect(string|Closure|null $page): static
    {
        $this->resourceEditPageRedirect = self::assertResourceRedirectTarget($page);

        return $this;
    }

    public function getResourceCreatePageRedirect(): ?string
    {
        return self::resourceRedirectTarget($this->evaluate($this->resourceCreatePageRedirect));
    }

    public function getResourceEditPageRedirect(): ?string
    {
        return self::resourceRedirectTarget($this->evaluate($this->resourceEditPageRedirect));
    }

    private static function assertResourceRedirectTarget(string|Closure|null $page): string|Closure|null
    {
        if (is_string($page) && self::resourceRedirectTarget($page) === null) {
            throw new InvalidArgumentException("Resource page redirect [{$page}] must be [index], [view] or [edit].");
        }

        return $page;
    }

    private static function resourceRedirectTarget(mixed $page): ?string
    {
        return is_string($page) && in_array($page, ['index', 'view', 'edit'], true) ? $page : null;
    }
}
