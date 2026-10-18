<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Pages;

use InvalidArgumentException;

/**
 * One registration of a configurable page: `SettingsPage::make('general')` returns the
 * page's configuration class (`Page::$configurationClass`), whose fluent options the page
 * reads back through `static::getConfiguration()` while that registration serves the
 * request. Register it like a page:
 *
 * ```php
 * $panel->pages([
 *     SettingsPage::make('general')->slug('general-settings')->settingsCategory('general'),
 *     SettingsPage::make('advanced')->settingsCategory('advanced'), // /settings-page/advanced
 * ]);
 * ```
 *
 * Extend this class to add your own options.
 */
class PageConfiguration
{
    /** Route default that names the configuration a route belongs to. */
    public const ROUTE_PARAMETER = 'alfakitPageConfiguration';

    protected ?string $slug = null;

    /**
     * @param  class-string<Page>  $page
     */
    final public function __construct(
        protected string $page,
        protected string $key,
    ) {
        if ($key === '' || preg_match('/^[A-Za-z0-9_-]+$/', $key) !== 1) {
            throw new InvalidArgumentException("Page configuration key [{$key}] may only contain letters, numbers, dashes and underscores.");
        }
    }

    /**
     * @param  class-string<Page>  $page
     */
    public static function make(string $page, string $key): static
    {
        return new static($page, $key);
    }

    /**
     * The URL path of this registration, replacing the default `{page-slug}/{key}`.
     */
    public function slug(?string $slug): static
    {
        $this->slug = $slug === null ? null : trim($slug, '/');

        return $this;
    }

    public function getSlug(): ?string
    {
        return $this->slug;
    }

    /**
     * @return class-string<Page>
     */
    public function getPage(): string
    {
        return $this->page;
    }

    public function getKey(): string
    {
        return $this->key;
    }
}
