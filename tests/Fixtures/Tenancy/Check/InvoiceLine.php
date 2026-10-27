<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\Tenancy\Check;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A line of an invoice: its relationships are not on Invoice. */
class InvoiceLine extends Model
{
    protected $guarded = [];

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function note(): BelongsTo
    {
        return $this->belongsTo(Note::class);
    }
}
