<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tables for ImportAction and ExportAction: one row per import or export run, plus the
 * rows of an import that failed validation (downloadable as a CSV).
 *
 * Publish with `php artisan vendor:publish --tag=alfakit-import-export-migrations` (or
 * `alfakit:install --import-export`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('imports', function (Blueprint $table): void {
            $table->id();
            $table->string('panel')->nullable();
            // String morphs so integer, UUID and ULID keys all fit.
            $table->string('user_type')->nullable();
            $table->string('user_id')->nullable();
            $table->string('tenant_type')->nullable();
            $table->string('tenant_id')->nullable();
            $table->string('importer');
            $table->string('file_name');
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('processed_rows')->default(0);
            $table->unsignedInteger('successful_rows')->default(0);
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['user_type', 'user_id']);
        });

        Schema::create('failed_import_rows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('import_id')->constrained('imports')->cascadeOnDelete();
            $table->json('data');
            $table->text('validation_error')->nullable();
            $table->timestamps();
        });

        Schema::create('exports', function (Blueprint $table): void {
            $table->id();
            $table->string('panel')->nullable();
            $table->string('user_type')->nullable();
            $table->string('user_id')->nullable();
            $table->string('tenant_type')->nullable();
            $table->string('tenant_id')->nullable();
            $table->string('exporter');
            $table->string('file_disk');
            $table->string('file_name');
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('processed_rows')->default(0);
            $table->unsignedInteger('successful_rows')->default(0);
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['user_type', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('failed_import_rows');
        Schema::dropIfExists('imports');
        Schema::dropIfExists('exports');
    }
};
