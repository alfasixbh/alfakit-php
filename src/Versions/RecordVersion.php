<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Versions;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One snapshot of a model with `HasRecordVersions`: the tracked attributes after a create,
 * update, delete or restore, who made the change and in which tenant.
 *
 * @property int $id
 * @property string $versionable_type
 * @property string $versionable_id
 * @property string $event
 * @property array<string, mixed> $snapshot
 * @property string|null $author_type
 * @property string|null $author_id
 * @property string|null $tenant_type
 * @property string|null $tenant_id
 * @property string|null $panel
 * @property int|null $restored_from_id
 * @property \Illuminate\Support\Carbon|null $created_at
 */
class RecordVersion extends Model
{
    public const UPDATED_AT = null;

    public const EVENT_CREATED = 'created';

    public const EVENT_UPDATED = 'updated';

    public const EVENT_DELETED = 'deleted';

    public const EVENT_RESTORED = 'restored';

    /** A "Restore this version" from the history (`restored_from_id` names the version). */
    public const EVENT_REVERTED = 'reverted';

    protected $table = 'alfakit_record_versions';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'snapshot' => 'array',
            'restored_from_id' => 'integer',
        ];
    }

    /**
     * The tracked attributes as stored (raw database values).
     *
     * @return array<string, mixed>
     */
    public function getSnapshot(): array
    {
        $value = $this->getAttributeValue('snapshot');

        return is_array($value) ? $value : [];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function versionable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function author(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Versions written in the given tenant, plus those written outside any tenant (console,
     * queues). Null applies no scope.
     *
     * @param  Builder<RecordVersion>  $query
     * @return Builder<RecordVersion>
     */
    public function scopeForTenant(Builder $query, ?Model $tenant): Builder
    {
        if ($tenant === null) {
            return $query;
        }

        return $query->where(static fn (Builder $query) => $query
            ->whereNull('tenant_id')
            ->orWhere(static fn (Builder $query) => $query->where('tenant_type', $tenant->getMorphClass())->where('tenant_id', (string) $tenant->getKey())));
    }
}
