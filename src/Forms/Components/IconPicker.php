<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Forms\Components;

use AlfaSix\Alfakit\Support\Icons;
use AlfaSix\Alfakit\Support\Icons\IconCatalog;
use AlfaSix\Alfakit\Support\Icons\SvgSanitizer;
use Closure;

/**
 * Pick an icon by name from Lucide and the registered icon sets (`Icons::iconify()`,
 * `Icons::registerSet()`). The state is the icon name (`house`, `mdi-home`), which every
 * `icon()` in AlfaKit accepts. Searching runs on the server through the options endpoint,
 * a page at a time, so the browser never downloads a whole set.
 *
 * ```php
 * IconPicker::make('icon')
 *     ->sets(['lucide', 'mdi'])
 *     ->exclude(['skull'])
 *     ->gridColumns(8);
 * ```
 */
class IconPicker extends Field
{
    /** @var array<int, string>|Closure|null */
    protected array|Closure|null $sets = null;

    /** @var array<int, string>|Closure|null */
    protected array|Closure|null $allowedIcons = null;

    /** @var array<int, string>|Closure */
    protected array|Closure $excludedIcons = [];

    protected bool|Closure $searchable = true;

    protected int|Closure $gridColumns = 8;

    protected bool|Closure $allowCustomSvg = false;

    protected string|Closure $previewSize = 'md';

    protected int|Closure $perPage = 96;

    /**
     * The icon sets to pick from, in order: `lucide`, a registered Iconify or directory set
     * prefix (`mdi`, `app`) or `heroicon-o|s|m|c`. Default: Lucide and every registered
     * Iconify and directory set.
     *
     * @param  array<int, string>|Closure|null  $sets
     */
    public function sets(array|Closure|null $sets): static
    {
        $this->sets = $sets;

        return $this;
    }

    /**
     * Only offer these icons (full names: `house`, `mdi-home`), in this order.
     *
     * @param  array<int, string>|Closure|null  $icons
     */
    public function allowedIcons(array|Closure|null $icons): static
    {
        $this->allowedIcons = $icons;

        return $this;
    }

    /**
     * Alias of `allowedIcons()` (guava/filament-icon-picker).
     *
     * @param  array<int, string>|Closure|null  $icons
     */
    public function allowIcons(array|Closure|null $icons): static
    {
        return $this->allowedIcons($icons);
    }

    /**
     * Never offer (or accept) these icons.
     *
     * @param  array<int, string>|Closure  $icons
     */
    public function exclude(array|Closure $icons): static
    {
        $this->excludedIcons = $icons;

        return $this;
    }

    /**
     * Alias of `exclude()` (guava/filament-icon-picker).
     *
     * @param  array<int, string>|Closure  $icons
     */
    public function disallowIcons(array|Closure $icons): static
    {
        return $this->exclude($icons);
    }

    /**
     * Show the search box (on by default).
     */
    public function searchable(bool|Closure $condition = true): static
    {
        $this->searchable = $condition;

        return $this;
    }

    /**
     * How many icons each row of the picker grid shows (default 8).
     */
    public function gridColumns(int|Closure $columns): static
    {
        $this->gridColumns = $columns;

        return $this;
    }

    /**
     * Let users paste their own SVG markup (off by default). It is sanitised on the server
     * before it is stored and whenever it is drawn.
     */
    public function allowCustomSvg(bool|Closure $condition = true): static
    {
        $this->allowCustomSvg = $condition;

        return $this;
    }

    /**
     * Size of the icons in the grid and of the selected icon: `sm`, `md` (default) or `lg`.
     */
    public function previewSize(string|Closure $size): static
    {
        $this->previewSize = $size;

        return $this;
    }

    /**
     * How many icons one request returns (default 96).
     */
    public function perPage(int|Closure $count): static
    {
        $this->perPage = $count;

        return $this;
    }

    /**
     * @return list<string>
     */
    public function getSets(): array
    {
        $sets = $this->evaluate($this->sets) ?? IconCatalog::defaultSets();

        return array_values(array_unique(array_filter(array_map(strval(...), (array) $sets), IconCatalog::isListable(...))));
    }

    /**
     * @return list<string>|null
     */
    public function getAllowedIcons(): ?array
    {
        $icons = $this->evaluate($this->allowedIcons);

        return $icons === null ? null : array_values(array_map(strval(...), (array) $icons));
    }

    /**
     * @return list<string>
     */
    public function getExcludedIcons(): array
    {
        return array_values(array_map(strval(...), (array) $this->evaluate($this->excludedIcons)));
    }

    public function isSearchable(): bool
    {
        return (bool) $this->evaluate($this->searchable);
    }

    public function getGridColumns(): int
    {
        return max(1, min(16, (int) $this->evaluate($this->gridColumns)));
    }

    public function allowsCustomSvg(): bool
    {
        return (bool) $this->evaluate($this->allowCustomSvg);
    }

    public function getPreviewSize(): string
    {
        $size = (string) $this->evaluate($this->previewSize);

        return in_array($size, ['sm', 'md', 'lg'], true) ? $size : 'md';
    }

    public function getPerPage(): int
    {
        return max(1, min(500, (int) $this->evaluate($this->perPage)));
    }

    /**
     * The icons on offer (value => search keywords), optionally of one set.
     *
     * @return array<string, string>
     */
    public function getIcons(?string $set = null): array
    {
        $sets = $this->getSets();

        if ($set !== null && in_array($set, $sets, true)) {
            $sets = [$set];
        }

        $allowed = $this->getAllowedIcons();
        $icons = [];

        if ($allowed !== null) {
            $catalog = array_merge(...array_map(IconCatalog::icons(...), $sets === [] ? [IconCatalog::LUCIDE] : $sets));

            foreach ($allowed as $name) {
                // Without a set filter every allowed icon is offered; with one, only its own.
                if ($set === null || isset($catalog[$name])) {
                    $icons[$name] = $catalog[$name] ?? '';
                }
            }
        } else {
            foreach ($sets as $key) {
                $icons += IconCatalog::icons($key);
            }
        }

        foreach ($this->getExcludedIcons() as $name) {
            unset($icons[$name]);
        }

        return $icons;
    }

    /**
     * One page of matching icons, each with what the renderers draw.
     *
     * @return array{icons: list<array{value: string, icon: string|null}>, page: int, hasMore: bool, total: int}
     */
    public function getSearchResults(string $search, ?string $set = null, int $page = 1): array
    {
        $values = IconCatalog::search($this->getIcons($set), $this->isSearchable() ? $search : '', $set);
        $perPage = $this->getPerPage();
        $page = max(1, $page);
        $slice = array_slice($values, ($page - 1) * $perPage, $perPage);

        return [
            'icons' => array_map(static fn (string $value): array => ['value' => $value, 'icon' => Icons::resolve($value)], $slice),
            'page' => $page,
            'hasMore' => count($values) > $page * $perPage,
            'total' => count($values),
        ];
    }

    /**
     * Whether a value may be stored: an icon on offer, or sanitisable SVG markup when
     * `allowCustomSvg()` is on.
     */
    public function isAllowedIcon(string $value): bool
    {
        if (Icons::isSvg($value)) {
            return $this->allowsCustomSvg() && SvgSanitizer::sanitize($value) !== null;
        }

        return isset($this->getIcons()[$value]);
    }

    protected function getTypeValidationRules(): array
    {
        return [
            'string',
            function (string $attribute, mixed $value, Closure $fail): void {
                if (is_string($value) && $value !== '' && ! $this->isAllowedIcon($value)) {
                    $fail(__('alfakit::forms.icon_picker.invalid'));
                }
            },
        ];
    }

    protected function mutateDehydratedState(mixed $state): mixed
    {
        if (! is_string($state) || trim($state) === '') {
            return null;
        }

        // Custom SVG is stored sanitised.
        return Icons::isSvg($state) ? SvgSanitizer::sanitize($state) : trim($state);
    }

    protected function getSchemaProps(): array
    {
        $state = $this->getState();

        return [
            'sets' => array_map(static fn (string $set): array => ['key' => $set, 'label' => IconCatalog::label($set)], $this->getSets()),
            'searchable' => $this->isSearchable(),
            'gridColumns' => $this->getGridColumns(),
            'previewSize' => $this->getPreviewSize(),
            'allowCustomSvg' => $this->allowsCustomSvg() ?: null,
            // The current value and what the renderers draw for it.
            'selected' => is_string($state) && $state !== '' ? ['value' => $state, 'icon' => Icons::resolve($state)] : null,
        ];
    }

    /** Changes once per choice: `live()` refreshes without waiting. */
    public function getDefaultLiveDebounce(): int
    {
        return 0;
    }
}
