<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Panel\Concerns;

use AlfaSix\Alfakit\Resources\Resource;
use AlfaSix\Alfakit\Resources\ResourceConfiguration;
use Closure;

/**
 * Configurable resources: `->resources([OrderResource::make('archived')])` registers a
 * resource again under a key, with its own routes, navigation item and options.
 */
trait HasResourceConfigurations
{
    /** @var array<string, ResourceConfiguration> keyed by `Resource@key` */
    protected array $resourceConfigurations = [];

    protected function registerResourceConfiguration(ResourceConfiguration $configuration): void
    {
        $this->resourceConfigurations[$configuration->getResource().'@'.$configuration->getKey()] = $configuration;
    }

    /**
     * The configured registrations, of one resource or of all.
     *
     * @param  class-string<resource>|null  $resource
     * @return array<int, ResourceConfiguration>
     */
    public function getResourceConfigurations(?string $resource = null): array
    {
        return array_values(array_filter(
            $this->resourceConfigurations,
            static fn (ResourceConfiguration $configuration): bool => $resource === null || $configuration->getResource() === $resource,
        ));
    }

    /**
     * @param  class-string<resource>  $resource
     */
    public function getResourceConfiguration(string $resource, string $key): ?ResourceConfiguration
    {
        return $this->resourceConfigurations[$resource.'@'.$key] ?? null;
    }

    /**
     * Every registration: plain resource classes (null configuration) first, then the
     * configured ones.
     *
     * @return array<int, array{0: class-string<resource>, 1: ResourceConfiguration|null}>
     */
    public function getResourceRegistrations(): array
    {
        return [
            ...array_map(static fn (string $resource): array => [$resource, null], $this->getResources()),
            ...array_map(static fn (ResourceConfiguration $configuration): array => [$configuration->getResource(), $configuration], array_values($this->resourceConfigurations)),
        ];
    }

    /**
     * Call `$callback($resource)` for every registration, each with its configuration active.
     *
     * @template TReturn
     *
     * @param  Closure(class-string<resource>, ResourceConfiguration|null): TReturn  $callback
     * @return array<int, TReturn>
     */
    public function mapResourceRegistrations(Closure $callback): array
    {
        $results = [];

        foreach ($this->getResourceRegistrations() as [$resource, $configuration]) {
            $results[] = $resource::withConfiguration($configuration, static fn (): mixed => $callback($resource, $configuration));
        }

        return $results;
    }
}
