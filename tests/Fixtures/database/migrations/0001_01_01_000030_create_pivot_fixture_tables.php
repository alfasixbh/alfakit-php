<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * BelongsToMany relation managers with pivot attributes (`Fixtures/Pivot`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pivot_courses', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('pivot_students', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->nullable();
            $table->timestamps();
        });

        Schema::create('pivot_course_student', function (Blueprint $table): void {
            // Its own key, so a student can be attached twice (`allowDuplicates()`), and a
            // column that would clash with the student's `id` without aliasing.
            $table->id();
            $table->foreignId('course_id');
            $table->foreignId('student_id');
            $table->string('role')->nullable();
            $table->unsignedInteger('seat')->nullable();
            $table->timestamps();
        });

        Schema::create('pivot_lessons', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('course_id')->nullable();
            $table->string('title');
            $table->string('code')->nullable();
            $table->boolean('is_published')->default(true);
            $table->timestamps();
        });
    }
};
