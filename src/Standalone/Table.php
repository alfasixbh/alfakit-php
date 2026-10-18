<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Standalone;

/**
 * A table outside a panel: sorting, search, filters, pagination, record and bulk actions,
 * inline edits, reordering and polling all work as inside a panel. Sort, search and
 * filters live in the host page's query string, so the controller simply re-renders the
 * same `make()` call.
 *
 * ```php
 * class OrdersTable extends \AlfaSix\Alfakit\Standalone\Table
 * {
 *     public Customer $customer;
 *
 *     public function table(Table $table): Table
 *     {
 *         return $table->query($this->customer->orders())->columns([...]);
 *     }
 * }
 *
 * Inertia::render('Account/Orders', ['orders' => OrdersTable::make(['customer' => $customer])]);
 * ```
 */
abstract class Table extends Component {}
