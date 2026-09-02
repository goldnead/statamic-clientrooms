<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One room per client and brand, and the two things that hang off it.
     *
     * The e-mail address is the key, not a contact id: LeadHub is a suggest,
     * and a room must survive without it. Where LeadHub is there, `contact_id`
     * is the link to the person; where it is not, the address is the person.
     * Normalised (trimmed, lower-cased) before it is written, so the unique
     * key holds against `Maria@` and `maria@`.
     */
    public function up(): void
    {
        Schema::create('client_rooms', function (Blueprint $table) {
            $table->id();

            // Zero on every single-brand install. See Support\Brands.
            $table->unsignedBigInteger('brand_id')->default(0)->index();

            $table->unsignedBigInteger('contact_id')->nullable()->index();
            $table->string('email', 191)->index();
            $table->string('name', 191)->nullable();

            // A Statamic user id. A string, because Statamic's file-based users
            // carry UUIDs and the eloquent driver carries integers.
            $table->string('owner_user_id', 64)->nullable()->index();

            $table->string('status', 16)->default('open')->index();
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('closed_at')->nullable();

            // Touched by every task, file, note and automatic opening, so the
            // listing can sort by it without reading four tables.
            $table->timestamp('last_activity_at')->nullable()->index();

            // Two fields on purpose. `notes` is the coach's own and never leaves
            // the Control Panel; `client_notes` is what the coach chose to show.
            $table->text('notes')->nullable();
            $table->text('client_notes')->nullable();

            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(['brand_id', 'email']);
        });

        Schema::create('client_room_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->constrained('client_rooms')->cascadeOnDelete();
            $table->string('title', 255);
            $table->timestamp('due_at')->nullable();
            $table->timestamp('done_at')->nullable();
            $table->string('done_by', 64)->nullable();
            $table->string('created_by', 64)->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->index(['room_id', 'done_at']);
        });

        Schema::create('client_room_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->constrained('client_rooms')->cascadeOnDelete();

            // Container handle and path inside it, the way Statamic addresses
            // an asset. Not an asset id: those are `container::path` strings
            // that change when the file is moved, and a room must not lose its
            // documents because somebody tidied a folder.
            $table->string('container', 64);
            $table->string('path', 1024);
            $table->string('title', 255)->nullable();

            $table->boolean('visible_to_client')->default(true);
            $table->string('uploaded_by', 64)->nullable();
            $table->timestamps();

            $table->index(['room_id', 'visible_to_client']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_room_files');
        Schema::dropIfExists('client_room_tasks');
        Schema::dropIfExists('client_rooms');
    }
};
