<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A place for a task to remember where it came from.
 *
 * `client_rooms` has carried `meta` since the first migration; its tasks did
 * not, and an import that wants to run twice without making duplicates needs
 * somewhere to write the id it arrived with. What belongs here is provenance —
 * another system's uuid, the run that brought it in — and nothing the addon
 * itself reasons about.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('client_room_tasks', 'meta')) {
            return;
        }

        Schema::table('client_room_tasks', function (Blueprint $table) {
            $table->json('meta')->nullable()->after('estimated_minutes');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('client_room_tasks', 'meta')) {
            return;
        }

        Schema::table('client_room_tasks', function (Blueprint $table) {
            $table->dropColumn('meta');
        });
    }
};
