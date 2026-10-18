<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\GlobalSearch;

final class GlobalSearchResult
{
    /**
     * @param  array<string, mixed>  $details
     * @param  array<int, array<string, mixed>>  $actions  Serialised URL actions
     */
    public function __construct(
        public readonly string $title,
        public readonly string $url,
        public readonly array $details = [],
        public readonly array $actions = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return ['title' => $this->title, 'url' => \AlfaSix\Alfakit\Support\Url::sanitize($this->url), 'details' => $this->details ?: null, 'actions' => $this->actions ?: null];
    }
}
