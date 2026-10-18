<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Support\Concerns;

use AlfaSix\Alfakit\Panel;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * The user who started an import or export, the panel it ran in and the tenant it was
 * scoped to. Stored as string morphs so any key type fits.
 *
 * @mixin Model
 */
trait BelongsToRunOwner
{
    /**
     * @return MorphTo<Model, $this>
     */
    public function user(): MorphTo
    {
        return $this->morphTo('user');
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function tenant(): MorphTo
    {
        return $this->morphTo('tenant');
    }

    public function setOwner(?Authenticatable $user, ?Model $tenant, ?Panel $panel): static
    {
        $this->setAttribute('panel', $panel?->getId());
        $this->setAttribute('user_type', $user instanceof Model ? $user->getMorphClass() : null);
        $this->setAttribute('user_id', $user?->getAuthIdentifier() !== null ? (string) $user->getAuthIdentifier() : null);
        $this->setAttribute('tenant_type', $tenant?->getMorphClass());
        $this->setAttribute('tenant_id', $tenant?->getKey() !== null ? (string) $tenant->getKey() : null);

        return $this;
    }

    /**
     * Whether the given user started this run. Downloads are only served to them.
     */
    public function isOwnedBy(?Authenticatable $user): bool
    {
        if (! $user instanceof Model || $this->getAttribute('user_id') === null) {
            return false;
        }

        return $this->getAttribute('user_type') === $user->getMorphClass()
            && hash_equals((string) $this->getAttribute('user_id'), (string) $user->getAuthIdentifier());
    }

    public function getPanel(): ?Panel
    {
        $id = $this->getAttribute('panel');

        return is_string($id) && alfakit()->hasPanel($id) ? alfakit()->getPanel($id) : null;
    }

    public function isCompleted(): bool
    {
        return $this->getAttribute('completed_at') !== null;
    }

    /**
     * Add processed and successful rows atomically, so parallel chunk jobs never lose
     * counts.
     */
    public function addProcessedRows(int $processed, int $successful): void
    {
        $this->newQuery()->whereKey($this->getKey())->incrementEach([
            'processed_rows' => $processed,
            'successful_rows' => $successful,
        ]);

        $this->refresh();
    }

    /**
     * Mark the run as completed once every row was processed. Returns true for exactly
     * one caller (the last chunk job), which then sends the completion notification.
     */
    public function markAsCompletedIfFinished(): bool
    {
        $updated = $this->newQuery()
            ->whereKey($this->getKey())
            ->whereNull('completed_at')
            ->whereColumn('processed_rows', '>=', 'total_rows')
            ->update(['completed_at' => $this->freshTimestamp()]);

        $this->refresh();

        return $updated === 1;
    }
}
