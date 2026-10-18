<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Snapshots of models with `HasRecordVersions`: one row per create, update, delete and
 * restore, with the tracked attributes, the author and the tenant.
 *
 * Publish with `php artisan vendor:publish --tag=alfakit-record-versions-migrations`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alfakit_record_versions', function (Blueprint $table): void {
            $table->id();
            // String morphs so integer, UUID and ULID keys all fit.
            $table->string('versionable_type');
            $table->string('versionable_id');
            $table->string('event', 20);
            $table->json('snapshot');
            $table->string('author_type')->nullable();
            $table->string('author_id')->nullable();
            $table->string('tenant_type')->nullable();
            $table->string('tenant_id')->nullable();
            $table->string('panel')->nullable();
            $table->unsignedBigInteger('restored_from_id')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['versionable_type', 'versionable_id', 'id']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alfakit_record_versions');
    }
};
