<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Personal dashboard layouts (`Dashboard::customizable()`): one row per panel, dashboard,
 * user and tenant, holding the order, column spans and hidden widgets the user chose.
 *
 * Publish with `php artisan vendor:publish --tag=alfakit-dashboard-migrations`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create(config('alfakit.dashboards.layouts_table', 'alfakit_dashboard_layouts'), function (Blueprint $table): void {
            $table->id();
            $table->string('panel', 64);
            $table->string('dashboard', 191);
            // String morphs so integer, UUID and ULID keys all fit.
            $table->string('user_type', 191);
            $table->string('user_id', 64);
            // '' for panels without tenancy, so the unique index also covers them.
            $table->string('tenant_key', 191)->default('');
            $table->text('layout');
            $table->timestamps();

            $table->unique(['panel', 'dashboard', 'user_type', 'user_id', 'tenant_key'], 'alfakit_dashboard_layouts_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('alfakit.dashboards.layouts_table', 'alfakit_dashboard_layouts'));
    }
};
