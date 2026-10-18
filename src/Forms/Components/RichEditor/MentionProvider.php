<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Forms\Components\RichEditor;

use AlfaSix\Alfakit\Support\Concerns\EvaluatesClosures;
use AlfaSix\Alfakit\Support\Serializable;
use Closure;

/**
 * Mentions for `RichEditor::mentions()`: typing the trigger character (`@`, `#`) lists
 * matching items, either a static list (`items()`) or results searched on the server as
 * the user types (`getSearchResultsUsing()`). A mention is stored with its id and label.
 */
class MentionProvider
{
    use EvaluatesClosures;

    /** @var array<string|int, string>|Closure */
    protected array|Closure $items = [];

    protected ?Closure $getSearchResultsUsing = null;

    protected ?Closure $getLabelsUsing = null;

    protected int|Closure $searchDebounce = 250;

    protected int|Closure $limit = 10;

    protected ?Closure $getUrlUsing = null;

    final public function __construct(protected string $char) {}

    public static function make(string $char): static
    {
        return new static($char);
    }

    /**
     * @param  array<string|int, string>|Closure  $items  id => label
     */
    public function items(array|Closure $items): static
    {
        $this->items = $items;

        return $this;
    }

    /**
     * Search on the server: `fn (string $search): array => [id => label, …]`.
     */
    public function getSearchResultsUsing(?Closure $callback): static
    {
        $this->getSearchResultsUsing = $callback;

        return $this;
    }

    /**
     * Current labels of mentioned ids, for `RichContentRenderer`: `fn (array $ids): array`.
     */
    public function getLabelsUsing(?Closure $callback): static
    {
        $this->getLabelsUsing = $callback;

        return $this;
    }

    /**
     * Render mentions as links: `fn (string $id, string $label): ?string` returns the URL
     * (`route('users.show', $id)`), or null to keep a plain mention. Used by
     * `RichContentRenderer` (`mentions()`) and `RichContentAttribute`.
     */
    public function url(?Closure $callback): static
    {
        $this->getUrlUsing = $callback;

        return $this;
    }

    public function hasUrl(): bool
    {
        return $this->getUrlUsing !== null;
    }

    public function getUrl(string $id, string $label): ?string
    {
        if ($this->getUrlUsing === null) {
            return null;
        }

        $url = $this->evaluate($this->getUrlUsing, ['id' => $id, 'label' => $label]);

        return is_string($url) && $url !== '' ? $url : null;
    }

    public function searchDebounce(int|Closure $milliseconds): static
    {
        $this->searchDebounce = $milliseconds;

        return $this;
    }

    public function limit(int|Closure $limit): static
    {
        $this->limit = $limit;

        return $this;
    }

    public function getChar(): string
    {
        return $this->char;
    }

    public function isSearchable(): bool
    {
        return $this->getSearchResultsUsing !== null;
    }

    /**
     * @return array<int|string, string>
     */
    public function getItems(): array
    {
        return $this->normalise($this->evaluate($this->items) ?? []);
    }

    /**
     * @return array<int|string, string>
     */
    public function getSearchResults(string $search): array
    {
        $limit = max(1, (int) $this->evaluate($this->limit));

        if ($this->getSearchResultsUsing !== null) {
            return array_slice($this->normalise($this->evaluate($this->getSearchResultsUsing, ['search' => $search, 'query' => $search]) ?? []), 0, $limit, true);
        }

        $search = mb_strtolower($search);

        return array_slice(array_filter($this->getItems(), static fn (string $label): bool => $search === '' || str_contains(mb_strtolower($label), $search)), 0, $limit, true);
    }

    /**
     * @param  array<int, string|int>  $ids
     * @return array<int|string, string>
     */
    public function getLabels(array $ids): array
    {
        if ($this->getLabelsUsing !== null) {
            return $this->normalise($this->evaluate($this->getLabelsUsing, ['ids' => $ids]) ?? []);
        }

        return array_intersect_key($this->getItems(), array_flip(array_map('strval', $ids)));
    }

    /**
     * @return array<string, mixed>
     */
    public function toSchema(): array
    {
        $searchable = $this->isSearchable();

        return Serializable::compact([
            'trigger' => $this->char,
            'searchable' => $searchable ?: null,
            'searchDebounce' => $searchable ? (int) $this->evaluate($this->searchDebounce) : null,
            'items' => $searchable ? null : array_map(
                static fn (int|string $id, string $label): array => ['id' => (string) $id, 'label' => $label],
                array_keys($this->getItems()),
                $this->getItems(),
            ),
        ]);
    }

    /**
     * @param  array<mixed>  $items
     * @return array<int|string, string>
     */
    protected function normalise(array $items): array
    {
        $normalised = [];

        foreach ($items as $id => $label) {
            if (is_scalar($label)) {
                $normalised[(string) $id] = (string) $label;
            }
        }

        return $normalised;
    }
}
