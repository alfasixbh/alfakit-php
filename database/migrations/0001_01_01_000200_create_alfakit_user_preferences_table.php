<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-user panel preferences, such as customised keyboard shortcuts. Without this table
 * the preferences are kept in the cache instead.
 *
 * Publish with `php artisan vendor:publish --tag=alfakit-user-preferences-migrations`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alfakit_user_preferences', function (Blueprint $table): void {
            $table->id();
            // String morphs so integer, UUID and ULID keys all fit.
            $table->string('user_type');
            $table->string('user_id');
            $table->string('panel');
            $table->string('key', 100);
            $table->json('value')->nullable();
            $table->timestamps();

            $table->unique(['user_type', 'user_id', 'panel', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alfakit_user_preferences');
    }
};
