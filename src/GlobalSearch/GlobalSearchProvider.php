<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\GlobalSearch;

use AlfaSix\Alfakit\Panel;

/**
 * Aggregates results from every globally searchable resource of a panel.
 */
class GlobalSearchProvider
{
    public function __construct(protected Panel $panel) {}

    /**
     * @return array<int, array{label: string, results: array<int, array<string, mixed>>}>
     */
    public function search(string $query): array
    {
        $registrations = method_exists($this->panel, 'getResourceRegistrations')
            ? $this->panel->getResourceRegistrations()
            : array_map(static fn (string $resource): array => [$resource, null], $this->panel->getResources());

        // `$globalSearchSort`: sorted resources first (lowest first), the rest in panel order.
        $sort = static fn (array $registration): array => [$registration[0]::getGlobalSearchSort() === null, $registration[0]::getGlobalSearchSort()];
        usort($registrations, static fn (array $a, array $b): int => $sort($a) <=> $sort($b));

        $groups = [];

        // Each registration searches with its configuration active (configurable resources
        // registered twice show up as two groups with their own labels and URLs).
        foreach ($registrations as [$resource, $configuration]) {
            $group = $resource::withConfiguration($configuration, static function () use ($resource, $query): ?array {
                if (! $resource::canGloballySearch()) {
                    return null;
                }

                $results = $resource::getGlobalSearchResults($query);

                if ($results->isEmpty()) {
                    return null;
                }

                return [
                    'label' => $resource::getTitleCasePluralModelLabel(),
                    'results' => $results->map(static fn (GlobalSearchResult $result): array => array_filter($result->toArray(), static fn ($v) => $v !== null))->all(),
                ];
            });

            if ($group !== null) {
                $groups[] = $group;
            }
        }

        return $groups;
    }
}
