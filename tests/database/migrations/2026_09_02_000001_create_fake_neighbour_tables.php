<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The columns of two neighbours' tables this addon reads. Test-only: both
 * siblings are suggests and not in vendor.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('brands')) {
            Schema::create('brands', function (Blueprint $table) {
                $table->id();
                $table->string('handle')->unique();
                $table->string('name');
            });
        }

        if (! Schema::hasTable('leadhub_contacts')) {
            Schema::create('leadhub_contacts', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('brand_id')->default(0);
                $table->string('email')->nullable();
                $table->string('email_normalized')->nullable()->index();
                $table->string('first_name')->nullable();
                $table->string('last_name')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('leadhub_contacts');
        Schema::dropIfExists('brands');
    }
};
