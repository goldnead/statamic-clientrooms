<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The three columns of `statamic-products`' table the listener reads.
 * Test-only: the sibling is a suggest and not in vendor.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('products')) {
            return;
        }

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('handle', 191)->unique();
            $table->string('type', 32);
            $table->unsignedBigInteger('brand_id')->default(0);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
