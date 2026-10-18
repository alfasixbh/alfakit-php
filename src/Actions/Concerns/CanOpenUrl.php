<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Actions\Concerns;

use Closure;

trait CanOpenUrl
{
    protected string|Closure|null $url = null;

    protected bool|Closure $shouldOpenUrlInNewTab = false;

    protected bool|Closure $shouldPostToUrl = false;

    public function url(string|Closure|null $url, bool|Closure $shouldOpenInNewTab = false): static
    {
        $this->url = $url;
        $this->shouldOpenUrlInNewTab = $shouldOpenInNewTab;

        return $this;
    }

    public function openUrlInNewTab(bool|Closure $condition = true): static
    {
        $this->shouldOpenUrlInNewTab = $condition;

        return $this;
    }

    /**
     * Send a POST request to the URL (with the CSRF token) instead of visiting it, e.g. a
     * "lock session" item in the user menu.
     */
    public function postToUrl(bool|Closure $condition = true): static
    {
        $this->shouldPostToUrl = $condition;

        return $this;
    }

    public function shouldPostToUrl(): bool
    {
        return (bool) $this->evaluate($this->shouldPostToUrl);
    }

    /**
     * The URL to open, or null when its scheme is not http(s), mailto, tel or relative.
     */
    public function getUrl(): ?string
    {
        return \AlfaSix\Alfakit\Support\Url::sanitize($this->evaluate($this->url));
    }

    public function shouldOpenUrlInNewTab(): bool
    {
        return (bool) $this->evaluate($this->shouldOpenUrlInNewTab);
    }
}
