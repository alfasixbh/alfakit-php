<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Pages\Dashboard;

use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;

/**
 * Where a dashboard keeps its applied filters (`HasFiltersForm`, `HasFiltersAction`): in the
 * session under `$sessionKey` (per user, panel, tenant and dashboard), or, when the
 * dashboard's `persistsFiltersInSession()` returns false, in the page URL
 * (`?filters[startDate]=…`), so they last only as long as that URL and can be bookmarked.
 */
final class DashboardFilters
{
    public function __construct(
        protected bool $inSession,
        protected string $sessionKey,
        protected string $queryKey = 'filters',
    ) {}

    /**
     * The applied filters. Values read from the URL are user input (they were not validated
     * when the link was made).
     *
     * @return array<string, mixed>
     */
    public function get(): array
    {
        if ($this->inSession) {
            $filters = session()->get($this->sessionKey);

            return is_array($filters) ? $filters : [];
        }

        $query = [];
        parse_str((string) parse_url($this->pageUrl(), PHP_URL_QUERY), $query);
        $filters = $query[$this->queryKey] ?? null;

        if (! is_array($filters)) {
            return [];
        }

        $clean = [];

        foreach ($filters as $key => $value) {
            if (is_string($key) && (is_scalar($value) || is_array($value))) {
                $clean[$key] = $value;
            }
        }

        return $clean;
    }

    /**
     * Keep `$data` as the applied filters. In the session nothing is returned; in the URL
     * the response redirects to the dashboard with the filters in its query string.
     *
     * @param  array<string, mixed>  $data
     */
    public function store(array $data): ?RedirectResponse
    {
        if ($this->inSession) {
            session()->put($this->sessionKey, $data);

            return null;
        }

        return redirect()->to($this->url($data));
    }

    public function forget(): ?RedirectResponse
    {
        if ($this->inSession) {
            session()->forget($this->sessionKey);

            return null;
        }

        return redirect()->to($this->url([]));
    }

    /**
     * The dashboard URL (the page the request came from) with `$filters` in its query string.
     *
     * @param  array<string, mixed>  $filters
     */
    public function url(array $filters): string
    {
        $url = $this->pageUrl();
        $query = [];
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        unset($query[$this->queryKey]);

        $filters = array_filter($filters, static fn (mixed $value): bool => $value !== null && $value !== '' && $value !== []);

        if ($filters !== []) {
            $query[$this->queryKey] = $filters;
        }

        $base = Str::before($url, '?');

        return $query === [] ? $base : $base.'?'.http_build_query($query);
    }

    /**
     * The URL of the dashboard as the user sees it: the current URL on a page request, the
     * referring page on an internal endpoint request (actions, live refreshes).
     */
    protected function pageUrl(): string
    {
        $request = request();

        if (str_contains($request->path(), '_alfakit/')) {
            $previous = url()->previous();

            if ($previous !== '' && str_starts_with($previous, $request->getSchemeAndHttpHost())) {
                return $previous;
            }
        }

        return $request->fullUrl();
    }
}
