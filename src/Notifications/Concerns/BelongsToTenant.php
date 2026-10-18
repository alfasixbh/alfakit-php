<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Notifications\Concerns;

use AlfaSix\Alfakit\Notifications\DatabaseNotificationScope;
use Illuminate\Database\Eloquent\Model;

/**
 * Which tenant a database notification belongs to: the current tenant by default (on
 * tenancy panels), another one with `->tenant($team)`, or every tenant with `->forAllTenants()`.
 */
trait BelongsToTenant
{
    protected Model|false|null $tenant = null;

    public function tenant(?Model $tenant): static
    {
        $this->tenant = $tenant;

        return $this;
    }

    public function forAllTenants(): static
    {
        $this->tenant = false;

        return $this;
    }

    public function getTenant(): ?Model
    {
        if ($this->tenant === false) {
            return null;
        }

        return $this->tenant ?? (alfakit()->getCurrentPanel()?->hasTenancy() ? alfakit()->getTenant() : null);
    }

    /**
     * The data stored in the `notifications` table.
     *
     * @return array<string, mixed>
     */
    public function toDatabaseData(): array
    {
        $tenant = $this->getTenant();

        return [
            ...$this->toArray(),
            ...($tenant === null ? [] : [DatabaseNotificationScope::KEY => DatabaseNotificationScope::marker($tenant)]),
        ];
    }
}
