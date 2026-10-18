<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Web Push subscriptions (`Panel::pwa(push: true)`): one row per browser that turned
 * notifications on, per panel and user.
 *
 * Publish with `php artisan vendor:publish --tag=alfakit-pwa-migrations`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create(config('alfakit.pwa.subscriptions_table', 'alfakit_push_subscriptions'), function (Blueprint $table): void {
            $table->id();
            $table->string('panel', 64);
            // String morphs so integer, UUID and ULID keys all fit.
            $table->string('user_type', 191);
            $table->string('user_id', 64);
            $table->text('endpoint');
            $table->char('endpoint_hash', 64)->unique();
            $table->string('public_key', 255);
            $table->string('auth_token', 255);
            $table->string('user_agent', 255)->nullable();
            $table->timestamps();

            $table->index(['user_type', 'user_id'], 'alfakit_push_subscriptions_user_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('alfakit.pwa.subscriptions_table', 'alfakit_push_subscriptions'));
    }
};
