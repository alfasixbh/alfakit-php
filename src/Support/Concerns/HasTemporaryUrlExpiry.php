<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Support\Concerns;

use AlfaSix\Alfakit\Support\TemporaryUrls;
use Carbon\CarbonInterface;
use Closure;

/**
 * `temporaryUrlExpiry()`: how long the temporary URLs of private files stay valid, for
 * this component, instead of `config('alfakit.temporary_file_url_expiry_minutes')`.
 */
trait HasTemporaryUrlExpiry
{
    protected int|Closure|null $temporaryUrlExpiry = null;

    /**
     * Minutes the temporary URLs of private files stay valid (rounded up to the end of the
     * hour). Null uses `config('alfakit.temporary_file_url_expiry_minutes')`.
     */
    public function temporaryUrlExpiry(int|Closure|null $minutes): static
    {
        $this->temporaryUrlExpiry = $minutes;

        return $this;
    }

    public function getTemporaryUrlExpiryMinutes(): int
    {
        $minutes = $this->evaluate($this->temporaryUrlExpiry);

        return is_numeric($minutes) && (int) $minutes > 0 ? (int) $minutes : TemporaryUrls::defaultExpiryMinutes();
    }

    public function getTemporaryUrlExpiration(): CarbonInterface
    {
        return TemporaryUrls::expiresAt($this->getTemporaryUrlExpiryMinutes());
    }
}
