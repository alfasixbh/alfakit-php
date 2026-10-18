<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Panel\Concerns;

use Closure;

/**
 * Run every action (page saves included, since they are actions) inside a database
 * transaction, so a failure or `halt()` rolls back everything it wrote.
 */
trait HasDatabaseTransactions
{
    protected bool|Closure $hasDatabaseTransactions = false;

    public function databaseTransactions(bool|Closure $condition = true): static
    {
        $this->hasDatabaseTransactions = $condition;

        return $this;
    }

    public function hasDatabaseTransactions(): bool
    {
        return (bool) $this->evaluate($this->hasDatabaseTransactions);
    }
}
