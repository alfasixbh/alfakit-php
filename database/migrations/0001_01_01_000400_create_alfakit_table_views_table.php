<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Saved table views (`Table::savedViews()`): a user's own views ("My views") and the views
 * published to everyone on the same panel, table and tenant. Each row holds the table
 * state (filters, search, sort, columns, grouping, records per page) as JSON.
 *
 * Publish with `php artisan vendor:publish --tag=alfakit-table-views-migrations`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create(config('alfakit.tables.views_table', 'alfakit_table_views'), function (Blueprint $table): void {
            $table->id();
            $table->string('panel', 64);
            // Identifies the table: page (or relation manager / widget) class, query-string
            // identifier and model.
            $table->string('table_key', 64);
            // '' for panels without tenancy, so the indexes also cover them.
            $table->string('tenant_key', 191)->default('');
            // The owner. String morphs so integer, UUID and ULID keys all fit.
            $table->string('user_type', 191);
            $table->string('user_id', 64);
            $table->string('slug', 120);
            $table->string('name', 120);
            $table->string('icon', 120)->nullable();
            $table->boolean('is_shared')->default(false);
            $table->text('state');
            $table->timestamps();

            $table->unique(['panel', 'table_key', 'tenant_key', 'slug'], 'alfakit_table_views_slug_unique');
            $table->index(['panel', 'table_key', 'tenant_key', 'user_type', 'user_id'], 'alfakit_table_views_owner_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('alfakit.tables.views_table', 'alfakit_table_views'));
    }
};
