<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Auth\MultiFactor\Concerns;

/**
 * `HasEmailAuthentication` for an Eloquent user with a boolean `has_email_authentication`
 * column.
 *
 * @mixin \Illuminate\Database\Eloquent\Model
 */
trait InteractsWithEmailAuthentication
{
    public function hasEmailAuthentication(): bool
    {
        return (bool) $this->getAttribute('has_email_authentication');
    }

    public function toggleEmailAuthentication(bool $condition): void
    {
        $this->forceFill(['has_email_authentication' => $condition])->save();
    }
}
