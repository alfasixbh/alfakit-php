<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Widgets\Concerns;

use AlfaSix\Alfakit\Pages\Page;

/**
 * Gives a widget the filters of the dashboard it is on (`HasFiltersForm`) as
 * `$this->pageFilters`, e.g. `$this->pageFilters['startDate'] ?? null`.
 */
trait InteractsWithPageFilters
{
    /** @var array<string, mixed> */
    protected array $pageFilters = [];

    public function page(?Page $page): static
    {
        parent::page($page);

        $this->pageFilters = $page !== null && method_exists($page, 'getFilters') ? (array) $page->getFilters() : [];

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function getPageFilters(): array
    {
        return $this->pageFilters;
    }
}
