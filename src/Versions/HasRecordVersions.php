<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Versions;

use Closure;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Keeps a snapshot of the model's tracked attributes on every create, update, delete and
 * restore (`alfakit_record_versions`, publish the migration with
 * `vendor:publish --tag=alfakit-record-versions-migrations`). Resources of the model get a
 * "History" header action on their edit and view pages.
 *
 * ```php
 * class Post extends Model
 * {
 *     use HasRecordVersions;
 *
 *     // Optional: only these attributes (default: every attribute except keys,
 *     // timestamps and `$hidden` ones), minus the excluded ones.
 *     protected array $versionInclude = ['title', 'body', 'status'];
 *     protected array $versionExclude = ['views'];
 *     protected int $maxVersions = 50;
 * }
 * ```
 *
 * Works without the activity log plugin; it only uses Eloquent events.
 *
 * @phpstan-require-extends Model
 */
trait HasRecordVersions
{
    /**
     * Model classes whose versioning is paused (`withoutVersions()`).
     *
     * @var array<string, bool>
     */
    protected static array $versioningPaused = [];

    public static function bootHasRecordVersions(): void
    {
        static::created(static fn (self $model) => $model->recordVersion(RecordVersion::EVENT_CREATED));
        static::updated(static function (self $model): void {
            // Only when a tracked attribute changed (not e.g. a `views` counter).
            if (array_intersect(array_keys($model->getChanges()), $model->getVersionedAttributeNames()) !== []) {
                $model->recordVersion(RecordVersion::EVENT_UPDATED);
            }
        });
        static::deleted(static fn (self $model) => $model->recordVersion(RecordVersion::EVENT_DELETED));

        if (method_exists(static::class, 'restored')) {
            static::restored(static fn (self $model) => $model->recordVersion(RecordVersion::EVENT_RESTORED));
        }
    }

    /**
     * Run `$callback` without writing versions of this model.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public static function withoutVersions(Closure $callback): mixed
    {
        $previous = static::$versioningPaused[static::class] ?? false;
        static::$versioningPaused[static::class] = true;

        try {
            return $callback();
        } finally {
            static::$versioningPaused[static::class] = $previous;
        }
    }

    /**
     * @return MorphMany<RecordVersion, $this>
     */
    public function recordVersions(): MorphMany
    {
        return $this->morphMany(RecordVersion::class, 'versionable')->latest('id');
    }

    /**
     * @return MorphMany<RecordVersion, $this>
     */
    public function versions(): MorphMany
    {
        return $this->recordVersions();
    }

    /**
     * Attributes to track; null tracks every attribute (minus the excluded ones).
     *
     * @return array<string>|null
     */
    public function getVersionIncludedAttributes(): ?array
    {
        return property_exists($this, 'versionInclude') && is_array($this->versionInclude) ? array_values($this->versionInclude) : null;
    }

    /**
     * Never tracked: the primary key, timestamps, `$hidden` attributes (passwords, tokens)
     * and `$versionExclude`.
     *
     * @return array<string>
     */
    public function getVersionExcludedAttributes(): array
    {
        $excluded = [
            $this->getKeyName(),
            $this->getCreatedAtColumn(),
            $this->getUpdatedAtColumn(),
            ...$this->getHidden(),
            ...(property_exists($this, 'versionExclude') && is_array($this->versionExclude) ? $this->versionExclude : []),
        ];

        if (method_exists($this, 'getDeletedAtColumn')) {
            $excluded[] = $this->getDeletedAtColumn();
        }

        return array_values(array_filter($excluded, is_string(...)));
    }

    /**
     * How many versions to keep per record (null keeps all); older ones are pruned when a
     * new version is written.
     */
    public function getMaxVersions(): ?int
    {
        if (! property_exists($this, 'maxVersions')) {
            return 50;
        }

        return is_int($this->maxVersions) && $this->maxVersions > 0 ? $this->maxVersions : null;
    }

    /**
     * @return array<string>
     */
    public function getVersionedAttributeNames(): array
    {
        $names = $this->getVersionIncludedAttributes() ?? array_keys($this->getAttributes());

        return array_values(array_diff($names, $this->getVersionExcludedAttributes()));
    }

    /**
     * The tracked attributes as stored in the database (raw values: JSON columns stay
     * strings, so restoring writes back exactly what was there).
     *
     * @return array<string, mixed>
     */
    public function getVersionSnapshot(): array
    {
        $attributes = $this->getAttributes();
        $snapshot = [];

        foreach ($this->getVersionedAttributeNames() as $name) {
            if (array_key_exists($name, $attributes)) {
                $value = $attributes[$name];
                $snapshot[$name] = $value instanceof DateTimeInterface ? $this->fromDateTime($value) : $value;
            }
        }

        return $snapshot;
    }

    /**
     * Write a version now (the Eloquent events call this).
     */
    public function recordVersion(string $event, ?RecordVersion $restoredFrom = null): ?RecordVersion
    {
        if (static::$versioningPaused[static::class] ?? false) {
            return null;
        }

        $panel = alfakit()->getCurrentPanel();
        $author = $panel?->auth()->user() ?? auth()->user();
        $tenant = $panel?->hasTenancy() ? $panel->getTenant() : null;

        $version = $this->recordVersions()->create([
            'event' => $event,
            'snapshot' => $this->getVersionSnapshot(),
            'author_type' => $author instanceof Model ? $author->getMorphClass() : null,
            'author_id' => $author instanceof Model ? (string) $author->getKey() : null,
            'tenant_type' => $tenant?->getMorphClass(),
            'tenant_id' => $tenant === null ? null : (string) $tenant->getKey(),
            'panel' => $panel?->getId(),
            'restored_from_id' => $restoredFrom?->getKey(),
        ]);

        $this->pruneRecordVersions();

        return $version;
    }

    /**
     * Restore the tracked attributes of a version (other attributes are left alone) and
     * save: the save writes a new version that names the restored one. Attributes in
     * `$except` keep their current values (the owner columns of a `scopeToUser()` panel).
     *
     * @param  array<int, string>  $except
     */
    public function restoreRecordVersion(RecordVersion $version, array $except = []): void
    {
        abort_unless($version->versionable_type === $this->getMorphClass() && $version->versionable_id === (string) $this->getKey(), 404);

        $tracked = array_diff($this->getVersionedAttributeNames(), $except);
        $values = array_intersect_key($version->getSnapshot(), array_flip($tracked));

        static::withoutVersions(function () use ($values): void {
            // Raw values, as they were stored: casts are not applied twice.
            $this->setRawAttributes([...$this->getAttributes(), ...$values]);
            $this->save();
        });

        $this->recordVersion(RecordVersion::EVENT_REVERTED, $version);
    }

    protected function pruneRecordVersions(): void
    {
        $max = $this->getMaxVersions();

        if ($max === null) {
            return;
        }

        $keep = $this->recordVersions()->limit($max)->pluck('id');

        if ($keep->count() < $max) {
            return;
        }

        $this->morphMany(RecordVersion::class, 'versionable')->whereNotIn('id', $keep->all())->delete();
    }
}
