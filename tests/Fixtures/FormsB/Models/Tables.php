<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\FormsB\Models;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tables for the relationship-layout fixtures, created by the tests that use them.
 */
final class Tables
{
    public static function create(): void
    {
        Schema::create('formsb_addresses', function (Blueprint $table): void {
            $table->id();
            $table->string('street');
            $table->string('city')->nullable();
            $table->timestamps();
        });

        Schema::create('formsb_articles', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->foreignId('address_id')->nullable();
            $table->timestamps();
        });

        Schema::create('formsb_article_seo', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('article_id');
            $table->string('meta_title')->unique();
            $table->string('meta_description')->nullable();
            $table->string('secret')->nullable();
            $table->timestamps();
        });

        Schema::create('formsb_images', function (Blueprint $table): void {
            $table->id();
            $table->morphs('imageable');
            $table->string('url');
            $table->timestamps();
        });
    }
}
