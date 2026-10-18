<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\CommandPalette;

use AlfaSix\Alfakit\Panel;
use AlfaSix\Alfakit\Resources\Resource;
use AlfaSix\Alfakit\Resources\ResourceConfiguration;
use AlfaSix\Alfakit\Support\Html;
use AlfaSix\Alfakit\Support\Url;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * The records a user opened last (view and edit pages), per user, panel and tenant, kept
 * in the cache. Only references are stored (resource, route key, page): every read
 * resolves the record again through the resource's (tenant-scoped) query and the user's
 * policy, so records the user can no longer see never show up.
 */
final class RecentRecords
{
    /** Stored entries beyond the panel's limit, so records filtered out on read can be replaced. */
    private const SPARE = 5;

    private const TTL_DAYS = 30;

    /**
     * @param  class-string<resource>  $resource
     * @param  'view'|'edit'  $page
     */
    public static function record(Panel $panel, Authenticatable $user, string $resource, Model $record, string $page, ?string $configuration = null): void
    {
        $limit = $panel->getRecentRecordsLimit();

        if ($limit <= 0 || ! $resource::hasRecentRecords() || $resource::isNested()) {
            return;
        }

        $key = $record->getAttribute($resource::getRecordRouteKeyName() ?? $record->getRouteKeyName());

        if (! is_scalar($key)) {
            return;
        }

        $entry = ['resource' => $resource, 'key' => (string) $key, 'page' => $page, 'configuration' => $configuration];
        $same = static fn (array $item): bool => $item['resource'] === $entry['resource'] && $item['key'] === $entry['key'] && ($item['configuration'] ?? null) === $configuration;

        $entries = array_values(array_filter(self::stored($panel, $user), static fn (array $item): bool => ! $same($item)));
        array_unshift($entries, $entry);

        Cache::put(self::cacheKey($panel, $user), array_slice($entries, 0, $limit + self::SPARE), now()->addDays(self::TTL_DAYS));
    }

    /**
     * The recent records the user may still open, newest first.
     *
     * @return array<int, array{title: string, url: string, group: string, icon?: string}>
     */
    public static function get(Panel $panel, Authenticatable $user): array
    {
        $limit = $panel->getRecentRecordsLimit();

        if ($limit <= 0) {
            return [];
        }

        $registered = [];

        foreach ($panel->getResourceRegistrations() as [$resource, $configuration]) {
            $registered[$resource.'|'.($configuration?->getKey() ?? '')] = $configuration;
        }

        $results = [];

        foreach (self::stored($panel, $user) as $entry) {
            $id = $entry['resource'].'|'.($entry['configuration'] ?? '');

            // Resources removed from the panel since the visit are skipped.
            if (! array_key_exists($id, $registered)) {
                continue;
            }

            $result = self::resolve($entry['resource'], $registered[$id], $entry['key'], $entry['page']);

            if ($result !== null) {
                $results[] = $result;
            }

            if (count($results) >= $limit) {
                break;
            }
        }

        return $results;
    }

    public static function forget(Panel $panel, Authenticatable $user): void
    {
        Cache::forget(self::cacheKey($panel, $user));
    }

    /**
     * @param  class-string<resource>  $resource
     * @return array{title: string, url: string, group: string, icon?: string}|null
     */
    private static function resolve(string $resource, ?ResourceConfiguration $configuration, string $key, string $page): ?array
    {
        return $resource::withConfiguration($configuration, static function () use ($resource, $key, $page): ?array {
            try {
                if (! $resource::hasRecentRecords() || ! $resource::canAccess()) {
                    return null;
                }

                // The resource's own query: tenant-scoped, soft-deleted records included only
                // where the resource allows them.
                $record = $resource::resolveRecordRouteBinding($key);

                if ($record === null) {
                    return null;
                }

                $canEdit = $resource::hasPage('edit') && $resource::canEdit($record);
                $canView = $resource::hasPage('view') && $resource::canView($record);

                $target = match (true) {
                    $page === 'edit' && $canEdit => 'edit',
                    $canView => 'view',
                    $canEdit => 'edit',
                    default => null,
                };

                if ($target === null) {
                    return null;
                }

                $url = Url::sanitize($resource::getUrl($target, ['record' => $record]));

                if ($url === null) {
                    return null;
                }

                $icon = $resource::getNavigationIcon();

                return array_filter([
                    'title' => Html::toText((string) ($resource::getRecordTitle($record) ?? $record->getKey())),
                    'url' => $url,
                    'group' => $resource::getTitleCaseModelLabel(),
                    'icon' => $icon,
                ], static fn (mixed $value): bool => $value !== null);
            } catch (Throwable) {
                return null;
            }
        });
    }

    /**
     * @return array<int, array{resource: class-string<resource>, key: string, page: string, configuration: string|null}>
     */
    private static function stored(Panel $panel, Authenticatable $user): array
    {
        $stored = Cache::get(self::cacheKey($panel, $user));

        if (! is_array($stored)) {
            return [];
        }

        return array_values(array_filter($stored, static fn (mixed $item): bool => is_array($item)
            && is_string($item['resource'] ?? null)
            && is_subclass_of($item['resource'], Resource::class)
            && is_string($item['key'] ?? null)
            && in_array($item['page'] ?? null, ['view', 'edit'], true)));
    }

    private static function cacheKey(Panel $panel, Authenticatable $user): string
    {
        $tenant = $panel->hasTenancy() ? $panel->getTenant() : null;

        return 'alfakit:recent-records:'.sha1(implode('|', [
            $panel->getId(),
            $tenant === null ? '' : $tenant->getMorphClass().':'.$tenant->getKey(),
            $user instanceof Model ? $user->getMorphClass() : $user::class,
            (string) $user->getAuthIdentifier(),
        ]));
    }
}
