<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\FormsMorph;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tables for the MorphToSelect and BelongsToMany repeater fixtures.
 */
final class Tables
{
    public static function create(): void
    {
        Schema::create('morph_notes', function (Blueprint $table): void {
            $table->id();
            $table->string('body')->nullable();
            $table->nullableMorphs('notable');
            $table->timestamps();
        });

        Schema::create('morph_orders', function (Blueprint $table): void {
            $table->id();
            $table->string('number');
            $table->timestamps();
        });

        Schema::create('morph_products', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('morph_order_product', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id');
            $table->foreignId('product_id');
            $table->unsignedInteger('quantity')->default(1);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });
    }
}
