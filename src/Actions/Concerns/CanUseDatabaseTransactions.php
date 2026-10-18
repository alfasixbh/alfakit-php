<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Actions\Concerns;

use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Wrap the action's hooks and handler in a database transaction. Follows the panel's
 * `databaseTransactions()` unless the action sets `databaseTransaction()` itself.
 */
trait CanUseDatabaseTransactions
{
    protected bool|Closure|null $hasDatabaseTransaction = null;

    public function databaseTransaction(bool|Closure|null $condition = true): static
    {
        $this->hasDatabaseTransaction = $condition;

        return $this;
    }

    public function shouldUseDatabaseTransaction(): bool
    {
        $condition = $this->evaluate($this->hasDatabaseTransaction);

        if ($condition !== null) {
            return (bool) $condition;
        }

        $panel = alfakit()->getCurrentPanel() ?? alfakit()->getDefaultPanel();

        return $panel !== null && $panel->hasDatabaseTransactions();
    }

    /**
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    protected function runInDatabaseTransaction(Closure $callback): mixed
    {
        return $this->shouldUseDatabaseTransaction() ? DB::transaction($callback) : $callback();
    }
}
