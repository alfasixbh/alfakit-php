<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Panel\Concerns;

use AlfaSix\Alfakit\Pages\Page;
use AlfaSix\Alfakit\Pages\PageConfiguration;
use Closure;

/**
 * Configurable pages: `->pages([SettingsPage::make('general')])` registers a page again
 * under a key, with its own route, navigation item and options.
 */
trait HasPageConfigurations
{
    /** @var array<string, PageConfiguration> keyed by `Page@key` */
    protected array $pageConfigurations = [];

    protected function registerPageConfiguration(PageConfiguration $configuration): void
    {
        $this->pageConfigurations[$configuration->getPage().'@'.$configuration->getKey()] = $configuration;
    }

    /**
     * The configured registrations, of one page or of all.
     *
     * @param  class-string<Page>|null  $page
     * @return array<int, PageConfiguration>
     */
    public function getPageConfigurations(?string $page = null): array
    {
        return array_values(array_filter(
            $this->pageConfigurations,
            static fn (PageConfiguration $configuration): bool => $page === null || $configuration->getPage() === $page,
        ));
    }

    /**
     * @param  class-string<Page>  $page
     */
    public function getPageConfiguration(string $page, string $key): ?PageConfiguration
    {
        return $this->pageConfigurations[$page.'@'.$key] ?? null;
    }

    /**
     * Call `$callback($page)` for every page registration (plain pages, then configured
     * ones), each with its configuration active.
     *
     * @template TReturn
     *
     * @param  Closure(class-string<Page>, PageConfiguration|null): TReturn  $callback
     * @return array<int, TReturn>
     */
    public function mapPageRegistrations(Closure $callback): array
    {
        $results = [];

        foreach ($this->getPages() as $page) {
            $results[] = $page::withConfiguration(null, static fn (): mixed => $callback($page, null));
        }

        foreach ($this->pageConfigurations as $configuration) {
            $page = $configuration->getPage();
            $results[] = $page::withConfiguration($configuration, static fn (): mixed => $callback($page, $configuration));
        }

        return $results;
    }
}
